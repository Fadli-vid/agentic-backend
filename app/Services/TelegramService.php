<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function sendMessage(string $chatId, string $text, ?string $parseMode = null): bool
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

        $response = Http::timeout(15)
            ->asJson()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

        if (!$response->successful()) {
            Log::warning('Telegram API sendMessage failed.', [
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }
}
