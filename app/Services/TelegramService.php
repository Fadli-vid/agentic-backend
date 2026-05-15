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
            Log::warning('Telegram bot token is not configured.');
            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($parseMode) {
            $payload['parse_mode'] = $parseMode;
        }

        try {
            $response = Http::timeout(15)
                ->withoutVerifying()
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
        } catch (\Throwable $exception) {
            Log::error('Telegram API sendMessage exception.', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        Log::info('Telegram API sendMessage response.', [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body' => substr((string) $response->body(), 0, 500),
        ]);

        if (!$response->successful()) {
            Log::warning('Telegram API sendMessage failed.', [
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }
}
