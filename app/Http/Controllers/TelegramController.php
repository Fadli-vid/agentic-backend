<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    public function handleWebhook(Request $request)
    {
        try {
            Log::info('Telegram webhook received', [
                'payload' => $request->all(),
            ]);

            $chatId = data_get($request->all(), 'message.chat.id');
            $text = data_get($request->all(), 'message.text');

            if (!$chatId || !$text) {
                return response()->json(['status' => 'ignored'], 200);
            }

            $token = env('TELEGRAM_BOT_TOKEN');

            if (!$token) {
                Log::error('TELEGRAM_BOT_TOKEN is not set');
                return response()->json(['status' => 'token_missing'], 200);
            }

            $response = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => 'Tes koneksi berhasil. Kobi sudah terhubung lagi ✅',
            ]);

            Log::info('Telegram sendMessage response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return response()->json(['status' => 'success'], 200);
        } catch (\Throwable $e) {
            Log::error('Telegram webhook debug failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json(['status' => 'error'], 200);
        }
    }
}