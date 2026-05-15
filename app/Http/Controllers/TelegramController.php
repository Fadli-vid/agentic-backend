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
            $chatId = data_get($payload, 'message.chat.id');
            $text = data_get($payload, 'message.text');

            Log::info('Telegram webhook received.', [
                'has_chat_id' => (bool) $chatId,
                'has_text' => $text !== null,
            ]);

            if (!$chatId || $text === null) {
                return response()->json(['status' => 'success'], 200);
            }

            $text = trim((string) $text);

            if ($text === '') {
                return response()->json(['status' => 'success'], 200);
            }

            Log::info('Service resolving started.', [
                'chat_id' => (string) $chatId,
            ]);

            try {
                $telegramService = app(TelegramService::class);
                Log::info('TelegramService resolved.');
            } catch (\Throwable $exception) {
                Log::error('Failed to resolve TelegramService.', [
                    'error' => $exception->getMessage(),
                ]);

                return response()->json(['status' => 'success'], 200);
            }

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
                (string) $chatId,
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
                    'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.',
                    null
                );

                return response()->json(['status' => 'success'], 200);
            }

            if ($this->isTaskCommand($text)) {
                $n8nReply = $agentActionService->handleN8nCommand($text, $event);

                if ($n8nReply) {
                    $n8nReply = $this->normalizeActionReply($n8nReply);

                    if ($event) {
                        $this->safeUpdateEvent($event, [
                            'action' => 'task_command',
                            'payload' => [
                                'command' => 'task',
                                'text' => $text,
                            ],
                        ]);
                    }

                    $this->safeFinalizeEvent($agentEventService, $event, $n8nReply);
                    $this->safeSendTelegramMessage(
                        $telegramService,
                        $chatId,
                        $n8nReply['reply'],
                        $n8nReply['parse_mode']
                    );

                    return response()->json(['status' => 'success'], 200);
                }
            }

            if ($event) {
                try {
                    $agentEventService?->markAnalyzing($event);
                } catch (\Throwable $exception) {
                    Log::warning('Failed to mark event analyzing.', [
                        'event_id' => $event->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            Log::info('Before Gemini.', [
                'event_id' => $event?->id,
            ]);

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
                    'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.',
                    null
                );

                return response()->json(['status' => 'success'], 200);
            }

            try {
                $analysis = $geminiService->analyzeMessage($text);
            } catch (\Throwable $exception) {
                Log::error('Gemini analysis failed.', [
                    'error' => $exception->getMessage(),
                ]);

                $analysis = [
                    'ok' => false,
                    'reply' => 'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.',
                    'error_type' => 'failed_ai',
                ];
            }

            Log::info('After Gemini.', [
                'event_id' => $event?->id,
                'action' => $analysis['action'] ?? null,
                'ok' => $analysis['ok'] ?? false,
            ]);

            if (!($analysis['ok'] ?? false)) {
                $status = $analysis['error_type'] ?? 'failed_ai';

                $this->safeMarkFailed(
                    $agentEventService,
                    $event,
                    $status,
                    $analysis['reply'] ?? 'AI error.',
                    [
                        'analysis' => $analysis,
                    ]
                );

                $this->safeSendTelegramMessage(
                    $telegramService,
                    $chatId,
                    $analysis['reply'] ?? 'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.',
                    $analysis['parse_mode'] ?? null
                );

                return response()->json(['status' => 'success'], 200);
            }

            if ($event) {
                $this->safeUpdateEvent($event, [
                    'action' => $analysis['action'] ?? null,
                    'payload' => [
                        'analysis' => $analysis,
                    ],
                ]);
            }

            Log::info('Before action execute.', [
                'event_id' => $event?->id,
                'action' => $analysis['action'] ?? null,
            ]);

            try {
                $actionReply = $agentActionService->execute($analysis, $event);
            } catch (\Throwable $exception) {
                Log::error('Agent action failed.', [
                    'action' => $analysis['action'] ?? null,
                    'error' => $exception->getMessage(),
                ]);

                $actionReply = [
                    'status' => 'failed_action',
                    'reply' => 'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.',
                    'result' => [],
                    'error_message' => $exception->getMessage(),
                ];
            }

            $actionReply = $this->normalizeActionReply($actionReply);
            $this->safeFinalizeEvent($agentEventService, $event, $actionReply);

            $this->safeSendTelegramMessage(
                $telegramService,
                $chatId,
                $actionReply['reply'],
                $actionReply['parse_mode']
            );

            return response()->json(['status' => 'success'], 200);
        } catch (\Throwable $exception) {
            Log::error('Telegram webhook processing failed.', [
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
                    'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.',
                    null
                );
            }

            return response()->json(['status' => 'success'], 200);
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
            ]);

            return null;
        }
    }

    private function safeFinalizeEvent(?AgentEventService $agentEventService, ?AgentEvent $event, array $actionReply): void
    {
        if (!$event || !$agentEventService) {
            return;
        }

        try {
            $this->finalizeEventFromAction($agentEventService, $event, $actionReply);
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
        if (!$event || !$agentEventService) {
            return;
        }

        try {
            $agentEventService->markFailed($event, $status, $errorMessage, $payload);
        } catch (\Throwable $exception) {
            Log::error('Failed to mark agent event failed.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeSendTelegramMessage(
        ?TelegramService $telegramService,
        ?string $chatId,
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
            return $telegramService->sendMessage($chatId, $text, $parseMode);
        } catch (\Throwable $exception) {
            Log::error('Failed to send Telegram message.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function safeUpdateEvent(AgentEvent $event, array $data): void
    {
        try {
            $event->update($data);
        } catch (\Throwable $exception) {
            Log::warning('Failed to update agent event.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
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

    private function finalizeEventFromAction(
        AgentEventService $agentEventService,
        AgentEvent $event,
        array $actionReply
    ): void {
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
    }

    private function resolveUserName(array $payload): ?string
    {
        $username = trim((string) data_get($payload, 'message.from.username'));

        if ($username !== '') {
            return $username;
        }

        $firstName = trim((string) data_get($payload, 'message.from.first_name'));
        $lastName = trim((string) data_get($payload, 'message.from.last_name'));

        $fullName = trim($firstName . ' ' . $lastName);

        return $fullName !== '' ? $fullName : null;
    }
}
