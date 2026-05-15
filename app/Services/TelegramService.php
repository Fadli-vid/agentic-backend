<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function sendMessage(string|int $chatId, string $text, ?string $parseMode = null): bool
    {
        $token = env('TELEGRAM_BOT_TOKEN');

        if (!$token) {
            Log::error('TELEGRAM_BOT_TOKEN is not set.');
            return false;
        }

        $payload = [
            'chat_id' => (string) $chatId,
            'text' => $text,
        ];

        if ($parseMode) {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            $response = Http::timeout(30)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

            Log::info('Telegram sendMessage response.', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return $response->successful();
        } catch (\Throwable $exception) {
            Log::error('Telegram sendMessage exception.', [
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return false;
        }
    }
}