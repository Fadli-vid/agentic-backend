<?php

namespace App\Http\Controllers;

use App\Models\AgentEvent;
use App\Services\AgentActionService;
use App\Services\AgentEventService;
use App\Services\GeminiService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $chatId = null;
        $text = null;
        $event = null;

        $telegramService = null;
        $agentEventService = null;
        $geminiService = null;
        $agentActionService = null;

        try {
            set_time_limit(120);

            $payload = $request->all();

            Log::info('Telegram raw payload.', [
                'payload' => $payload,
            ]);

            $message = data_get($payload, 'message')
                ?? data_get($payload, 'edited_message')
                ?? data_get($payload, 'channel_post')
                ?? data_get($payload, 'callback_query.message');

            $chatId = data_get($message, 'chat.id');
            $text = data_get($message, 'text');

            Log::info('Telegram parsed payload.', [
                'chat_id' => $chatId,
                'text' => $text,
                'has_message' => (bool) $message,
            ]);

            if (!$chatId || $text === null) {
                return response()->json([
                    'status' => 'ignored',
                    'reason' => 'missing_chat_id_or_text',
                ], 200);
            }

            $chatId = (string) $chatId;
            $text = trim((string) $text);

            if ($text === '') {
                return response()->json([
                    'status' => 'ignored',
                    'reason' => 'empty_text',
                ], 200);
            }

            /**
             * Resolve TelegramService first.
             * Kalau ini gagal, kita memang belum bisa kirim balasan.
             */
            try {
                $telegramService = app(TelegramService::class);

                Log::info('TelegramService resolved.');
            } catch (\Throwable $exception) {
                Log::error('Failed to resolve TelegramService.', [
                    'error' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]);

                return response()->json([
                    'status' => 'telegram_service_unavailable',
                ], 200);
            }

            /**
             * Debug gate.
             * Aktifkan di Render:
             * KOBI_WEBHOOK_TEST_REPLY=true
             *
             * Kalau bot membalas pesan ini, berarti:
             * Telegram -> Laravel -> TelegramService -> Telegram sudah aman.
             */
            if ($this->envBool('KOBI_WEBHOOK_TEST_REPLY', false)) {
                $this->safeSendTelegramMessage(
                    $telegramService,
                    $chatId,
                    'Webhook masuk, text terbaca, dan TelegramService aktif ✅'
                );

                return response()->json([
                    'status' => 'test_reply_sent',
                ], 200);
            }

            /**
             * Resolve optional services.
             * Kalau AgentEventService gagal, bot tetap lanjut ke Gemini.
             */
            try {
                $agentEventService = app(AgentEventService::class);

                Log::info('AgentEventService resolved.');
            } catch (\Throwable $exception) {
                Log::error('Failed to resolve AgentEventService.', [
                    'error' => $exception->getMessage(),
                ]);
            }

            $event = $this->safeCreateAgentEvent(
                $agentEventService,
                $chatId,
                $text,
                $this->resolveUserName($payload)
            );

            try {
                $agentActionService = app(AgentActionService::class);

                Log::info('AgentActionService resolved.');
            } catch (\Throwable $exception) {
                Log::error('Failed to resolve AgentActionService.', [
                    'error' => $exception->getMessage(),
                ]);

                $this->safeSendTelegramMessage(
                    $telegramService,
                    $chatId,
                    'Maaf, Kobi sedang mengalami kendala action service. Coba lagi ya.'
                );

                return response()->json([
                    'status' => 'agent_action_service_unavailable',
                ], 200);
            }

            /**
             * Handle /task command langsung ke n8n.
             */
            if ($this->isTaskCommand($text)) {
                try {
                    $n8nReply = $agentActionService->handleN8nCommand($text, $event);
                    $n8nReply = $this->normalizeActionReply($n8nReply);

                    $this->safeUpdateEvent($event, [
                        'action' => 'task_command',
                        'payload' => [
                            'command' => 'task',
                            'text' => $text,
                        ],
                    ]);

                    $this->safeFinalizeEvent($agentEventService, $event, $n8nReply);

                    $this->safeSendTelegramMessage(
                        $telegramService,
                        $chatId,
                        $n8nReply['reply'],
                        $n8nReply['parse_mode']
                    );

                    return response()->json([
                        'status' => 'success',
                    ], 200);
                } catch (\Throwable $exception) {
                    Log::error('N8N task command failed.', [
                        'error' => $exception->getMessage(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                    ]);

                    $this->safeMarkFailed(
                        $agentEventService,
                        $event,
                        'failed_n8n_command',
                        $exception->getMessage()
                    );

                    $this->safeSendTelegramMessage(
                        $telegramService,
                        $chatId,
                        'Waduh, koneksi ke n8n lagi bermasalah. Coba cek workflow atau URL n8n ya 😅'
                    );

                    return response()->json([
                        'status' => 'n8n_error_handled',
                    ], 200);
                }
            }

            /**
             * Gemini intent parser.
             */
            $this->safeMarkAnalyzing($agentEventService, $event);

            try {
                $geminiService = app(GeminiService::class);

                Log::info('GeminiService resolved.');
            } catch (\Throwable $exception) {
                Log::error('Failed to resolve GeminiService.', [
                    'error' => $exception->getMessage(),
                ]);

                $this->safeSendTelegramMessage(
                    $telegramService,
                    $chatId,
                    'Maaf, Kobi belum bisa mengakses Gemini sekarang. Coba lagi sebentar ya.'
                );

                return response()->json([
                    'status' => 'gemini_service_unavailable',
                ], 200);
            }

            Log::info('Before Gemini.', [
                'event_id' => $event?->id,
            ]);

            try {
                $analysis = $geminiService->analyzeMessage($text);
            } catch (\Throwable $exception) {
                Log::error('Gemini analysis exception.', [
                    'error' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]);

                $analysis = [
                    'ok' => false,
                    'error_type' => 'failed_ai',
                    'reply' => 'Maaf, Kobi sedang gagal membaca pesan lewat Gemini. Coba lagi ya.',
                ];
            }

            Log::info('After Gemini.', [
                'event_id' => $event?->id,
                'ok' => $analysis['ok'] ?? false,
                'action' => $analysis['action'] ?? null,
            ]);

            if (!($analysis['ok'] ?? false)) {
                $this->safeMarkFailed(
                    $agentEventService,
                    $event,
                    $analysis['error_type'] ?? 'failed_ai',
                    $analysis['reply'] ?? 'AI error.',
                    [
                        'analysis' => $analysis,
                    ]
                );

                $this->safeSendTelegramMessage(
                    $telegramService,
                    $chatId,
                    $analysis['reply'] ?? 'Maaf, Kobi sedang mengalami kendala AI. Coba lagi ya.',
                    $analysis['parse_mode'] ?? null
                );

                return response()->json([
                    'status' => 'ai_error_handled',
                ], 200);
            }

            $this->safeUpdateEvent($event, [
                'action' => $analysis['action'] ?? null,
                'payload' => [
                    'analysis' => $analysis,
                ],
            ]);

            /**
             * Execute action: chat/add_task/add_expense/create_reminder/trigger_workflow/etc.
             */
            Log::info('Before action execute.', [
                'event_id' => $event?->id,
                'action' => $analysis['action'] ?? null,
            ]);

            try {
                $actionReply = $agentActionService->execute($analysis, $event);
            } catch (\Throwable $exception) {
                Log::error('Agent action exception.', [
                    'action' => $analysis['action'] ?? null,
                    'error' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ]);

                $actionReply = [
                    'status' => 'failed_action',
                    'reply' => 'Maaf, Kobi gagal menjalankan action tadi. Coba cek log backend ya.',
                    'result' => [],
                    'error_message' => $exception->getMessage(),
                    'parse_mode' => null,
                ];
            }

            $actionReply = $this->normalizeActionReply($actionReply);

            $this->safeFinalizeEvent($agentEventService, $event, $actionReply);

            Log::info('Before Telegram reply.', [
                'event_id' => $event?->id,
                'reply' => $actionReply['reply'],
            ]);

            $this->safeSendTelegramMessage(
                $telegramService,
                $chatId,
                $actionReply['reply'],
                $actionReply['parse_mode']
            );

            return response()->json([
                'status' => 'success',
            ], 200);
        } catch (\Throwable $exception) {
            Log::error('Telegram webhook fatal error handled.', [
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            $this->safeMarkFailed(
                $agentEventService,
                $event,
                'failed_exception',
                $exception->getMessage(),
                [
                    'hint' => 'Unhandled exception in webhook handler.',
                ]
            );

            if ($chatId) {
                $this->safeSendTelegramMessage(
                    $telegramService,
                    $chatId,
                    'Maaf, Kobi sedang error di backend. Coba cek log Render ya 😅'
                );
            }

            return response()->json([
                'status' => 'fatal_error_handled',
            ], 200);
        }
    }

    private function isTaskCommand(string $text): bool
    {
        return (bool) preg_match('/^\/task(\s|$)/i', trim($text));
    }

    private function safeCreateAgentEvent(
        ?AgentEventService $agentEventService,
        string $chatId,
        string $text,
        ?string $userName
    ): ?AgentEvent {
        if (!$agentEventService) {
            return null;
        }

        try {
            $event = $agentEventService->createReceivedFromTelegram($chatId, $text, $userName);

            Log::info('Agent event created.', [
                'event_id' => $event->id,
            ]);

            return $event;
        } catch (\Throwable $exception) {
            Log::error('Failed to create agent event.', [
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return null;
        }
    }

    private function safeMarkAnalyzing(?AgentEventService $agentEventService, ?AgentEvent $event): void
    {
        if (!$agentEventService || !$event) {
            return;
        }

        try {
            $agentEventService->markAnalyzing($event);
        } catch (\Throwable $exception) {
            Log::warning('Failed to mark event analyzing.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeFinalizeEvent(
        ?AgentEventService $agentEventService,
        ?AgentEvent $event,
        array $actionReply
    ): void {
        if (!$agentEventService || !$event) {
            return;
        }

        try {
            $status = $actionReply['status'] ?? 'completed';
            $result = $actionReply['result'] ?? [];

            if ($status !== 'completed') {
                $agentEventService->markFailed(
                    $event,
                    $status,
                    $actionReply['error_message'] ?? 'Action failed.',
                    $result
                );

                return;
            }

            $agentEventService->markCompleted($event, $result);
        } catch (\Throwable $exception) {
            Log::error('Failed to finalize agent event.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeMarkFailed(
        ?AgentEventService $agentEventService,
        ?AgentEvent $event,
        string $status,
        string $errorMessage,
        array $payload = []
    ): void {
        if (!$agentEventService || !$event) {
            return;
        }

        try {
            $agentEventService->markFailed($event, $status, $errorMessage, $payload);
        } catch (\Throwable $exception) {
            Log::error('Failed to mark event failed.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeUpdateEvent(?AgentEvent $event, array $data): void
    {
        if (!$event) {
            return;
        }

        try {
            $event->update($data);
        } catch (\Throwable $exception) {
            Log::warning('Failed to update agent event.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeSendTelegramMessage(
        ?TelegramService $telegramService,
        string|int|null $chatId,
        string $text,
        ?string $parseMode = null
    ): bool {
        if (!$telegramService) {
            Log::warning('TelegramService is not available.');
            return false;
        }

        if (!$chatId) {
            Log::warning('Telegram chat_id is missing.');
            return false;
        }

        try {
            $sent = $telegramService->sendMessage((string) $chatId, $text, $parseMode);

            Log::info('Telegram message send attempted.', [
                'chat_id' => (string) $chatId,
                'sent' => $sent,
            ]);

            return $sent;
        } catch (\Throwable $exception) {
            Log::error('Failed to send Telegram message.', [
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return false;
        }
    }

    private function normalizeActionReply(?array $actionReply): array
    {
        $actionReply = is_array($actionReply) ? $actionReply : [];

        return [
            'status' => $actionReply['status'] ?? 'completed',
            'reply' => $actionReply['reply'] ?? 'Kobi sudah memproses pesanmu.',
            'result' => $actionReply['result'] ?? [],
            'parse_mode' => $actionReply['parse_mode'] ?? null,
            'error_message' => $actionReply['error_message'] ?? null,
        ];
    }

    private function resolveUserName(array $payload): ?string
    {
        $from = data_get($payload, 'message.from')
            ?? data_get($payload, 'edited_message.from')
            ?? data_get($payload, 'callback_query.from');

        $username = trim((string) data_get($from, 'username'));

        if ($username !== '') {
            return $username;
        }

        $firstName = trim((string) data_get($from, 'first_name'));
        $lastName = trim((string) data_get($from, 'last_name'));

        $fullName = trim($firstName . ' ' . $lastName);

        return $fullName !== '' ? $fullName : null;
    }

    private function envBool(string $key, bool $default = false): bool
    {
        $value = env($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}