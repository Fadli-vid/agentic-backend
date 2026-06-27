<?php

namespace App\Http\Controllers;

use App\Services\AgentOrchestratorService;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    public function __construct(
        private AgentOrchestratorService $orchestrator,
        private TelegramService $telegramService,
    ) {
    }

    public function handleWebhook(Request $request)
    {
        $chatId = null;
        $text = null;

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
             * Debug gate.
             * Aktifkan di Render:
             * KOBI_WEBHOOK_TEST_REPLY=true
             *
             * Kalau bot membalas pesan ini, berarti:
             * Telegram -> Laravel -> TelegramService -> Telegram sudah aman.
             */
            if ($this->envBool('KOBI_WEBHOOK_TEST_REPLY', false)) {
                $this->safeSendTelegramMessage(
                    $chatId,
                    'Webhook masuk, text terbaca, dan TelegramService aktif ✅'
                );

                return response()->json([
                    'status' => 'test_reply_sent',
                ], 200);
            }

            /**
             * Route to the orchestrator.
             * Telegram-specific command parsing happens here;
             * the orchestrator only receives clean business intents.
             */
            if ($this->isTaskCommand($text)) {
                $taskContent = $this->extractTaskContent($text);
                $result = $this->orchestrator->handleDirectN8nTask(
                    $taskContent,
                    'telegram',
                    $chatId,
                    $this->resolveUserName($payload)
                );
            } else {
                $result = $this->orchestrator->handleMessage(
                    'telegram',
                    $chatId,
                    $text,
                    $this->resolveUserName($payload)
                );
            }

            Log::info('Before Telegram reply.', [
                'chat_id' => $chatId,
                'reply' => $result['reply'],
            ]);

            $this->safeSendTelegramMessage(
                $chatId,
                $result['reply'],
                $result['parse_mode'] ?? null
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

            if ($chatId) {
                $this->safeSendTelegramMessage(
                    $chatId,
                    'Maaf, Kobi sedang error di backend. Coba cek log Render ya 😅'
                );
            }

            return response()->json([
                'status' => 'fatal_error_handled',
            ], 200);
        }
    }

    // -------------------------------------------------------------------------
    //  Telegram-specific helpers
    // -------------------------------------------------------------------------

    private function isTaskCommand(string $text): bool
    {
        return (bool) preg_match('/^\/task(\s|$)/i', trim($text));
    }

    private function extractTaskContent(string $text): string
    {
        return trim(preg_replace('/^\/task(\s|$)/i', '', trim($text)));
    }

    private function safeSendTelegramMessage(
        string|int|null $chatId,
        string $text,
        ?string $parseMode = null
    ): bool {
        if (!$chatId) {
            Log::warning('Telegram chat_id is missing.');
            return false;
        }

        try {
            $sent = $this->telegramService->sendMessage((string) $chatId, $text, $parseMode);

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