<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AgentActionService;
use App\Services\AgentEventService;
use App\Services\GeminiService;
use App\Services\TelegramService;
use App\Models\AgentEvent;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    public function __construct(
        private GeminiService $geminiService,
        private TelegramService $telegramService,
        private AgentActionService $agentActionService,
        private AgentEventService $agentEventService
    ) {
    }

    public function handleWebhook(Request $request)
    {
        set_time_limit(120);

        $payload = $request->all();
        $chatId = data_get($payload, 'message.chat.id');
        $text = data_get($payload, 'message.text');

        Log::info('Telegram webhook received.', [
            'has_chat_id' => (bool) $chatId,
            'has_text' => $text !== null,
        ]);

        if (!$chatId || $text === null) {
            return response()->json(['status' => 'success']);
        }

        $text = trim((string) $text);

        if ($text === '') {
            return response()->json(['status' => 'success']);
        }

        $event = null;

        try {
            $userName = $this->resolveUserName($payload);
            $event = $this->agentEventService->createReceivedFromTelegram((string) $chatId, $text, $userName);

            Log::info('Agent event created.', [
                'event_id' => $event->id,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create agent event.', [
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            $n8nReply = null;

            if (str_starts_with($text, '/')) {
                $n8nReply = $this->agentActionService->handleN8nCommand($text, $event);
            }

            if ($n8nReply) {
                if ($event) {
                    $event->update([
                        'action' => 'task_command',
                        'payload' => [
                            'command' => 'task',
                            'text' => $text,
                        ],
                    ]);

                    $this->finalizeEventFromAction($event, $n8nReply);
                }

                Log::info('Before Telegram reply (/task).', [
                    'event_id' => $event?->id,
                ]);

                $this->telegramService->sendMessage(
                    $chatId,
                    $n8nReply['reply'] ?? 'Kobi sudah memproses pesanmu.',
                    $n8nReply['parse_mode'] ?? null
                );

                return response()->json(['status' => 'success']);
            }

            if ($event) {
                $this->agentEventService->markAnalyzing($event);
            }

            Log::info('Before Gemini.', [
                'event_id' => $event?->id,
            ]);

            $analysis = $this->geminiService->analyzeMessage($text);

            Log::info('After Gemini.', [
                'event_id' => $event?->id,
                'action' => $analysis['action'] ?? null,
                'ok' => $analysis['ok'] ?? false,
            ]);

            if (!($analysis['ok'] ?? false)) {
                $status = $analysis['error_type'] ?? 'failed_ai';

                if ($event) {
                    $this->agentEventService->markFailed(
                        $event,
                        $status,
                        $analysis['reply'] ?? 'AI error.',
                        [
                            'analysis' => $analysis,
                        ]
                    );
                }

                Log::info('Before Telegram reply (AI failed).', [
                    'event_id' => $event?->id,
                ]);

                $this->telegramService->sendMessage(
                    $chatId,
                    $analysis['reply'] ?? 'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.',
                    $analysis['parse_mode'] ?? null
                );

                return response()->json(['status' => 'success']);
            }

            if ($event) {
                $event->update([
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

            $actionReply = $this->agentActionService->execute($analysis, $event);

            if ($event) {
                $this->finalizeEventFromAction($event, $actionReply);
            }

            Log::info('Before Telegram reply.', [
                'event_id' => $event?->id,
            ]);

            $this->telegramService->sendMessage(
                $chatId,
                $actionReply['reply'] ?? 'Kobi sudah memproses pesanmu.',
                $actionReply['parse_mode'] ?? null
            );

            return response()->json(['status' => 'success']);
        } catch (\Throwable $exception) {
            Log::error('Telegram webhook processing failed.', [
                'error' => $exception->getMessage(),
            ]);

            if ($event) {
                $this->agentEventService->markFailed(
                    $event,
                    'failed_exception',
                    $exception->getMessage(),
                    [
                        'hint' => 'Unhandled exception in webhook handler.',
                    ]
                );
            }

            $this->telegramService->sendMessage(
                $chatId,
                'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.',
                null
            );

            return response()->json(['status' => 'success']);
        }
    }

    private function finalizeEventFromAction(AgentEvent $event, array $actionReply): void
    {
        $status = $actionReply['status'] ?? 'completed';
        $result = $actionReply['result'] ?? [];

        if ($status !== 'completed') {
            $this->agentEventService->markFailed(
                $event,
                $status,
                $actionReply['error_message'] ?? 'Action failed.',
                $result
            );

            return;
        }

        $this->agentEventService->markCompleted($event, $result);
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