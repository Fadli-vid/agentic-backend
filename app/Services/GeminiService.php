<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeminiService
{
    private const MODEL = 'gemini-3-flash-preview';

    public function analyzeMessage(string $text): array
    {
        $apiKey = env('GEMINI_API_KEY');

        if (!$apiKey) {
            Log::warning('Gemini API key is not configured.');
            return $this->fallback('Maaf, Kobi belum siap merespons karena konfigurasi AI belum lengkap.');
        }

        $systemPrompt = 'Kamu adalah Kobi, asisten AI pribadi yang cerdas. Tugas utamamu adalah menganalisis pesan dan membalasnya HANYA dalam format JSON.'
            . ' Aturan format JSON:'
            . ' {'
            . ' "action": "chat" | "add_task" | "add_expense",'
            . ' "reply": "Pesan balasan darimu yang santai, asik, dan membantu",'
            . ' "data_name": "Nama tugas (jika action=add_task) atau Deskripsi pengeluaran (jika action=add_expense). Kosongkan jika action=chat",'
            . ' "data_amount": 100000 (Wajib diisi angka pengeluaran jika action=add_expense, default 0)'
            . ' }';

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            self::MODEL,
            $apiKey
        );

        $response = Http::timeout(30)
            ->acceptJson()
            ->asJson()
            ->post($url, [
                'contents' => [[
                    'parts' => [[
                        'text' => $text,
                    ]],
                ]],
                'system_instruction' => [
                    'parts' => [[
                        'text' => $systemPrompt,
                    ]],
                ],
                'generationConfig' => [
                    'response_mime_type' => 'application/json',
                ],
            ]);

        if (!$response->successful()) {
            Log::warning('Gemini API request failed.', [
                'status' => $response->status(),
            ]);

            return $this->fallback('Maaf, Kobi sedang mengalami gangguan dari Gemini. Coba lagi sebentar ya.');
        }

        $rawText = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (!is_string($rawText)) {
            Log::warning('Gemini response missing text.');
            return $this->fallback('Maaf, jawaban dari Gemini tidak bisa dibaca. Coba lagi ya.');
        }

        $decoded = json_decode($rawText, true);

        if (!is_array($decoded)) {
            Log::warning('Gemini returned invalid JSON.', [
                'raw' => Str::limit($rawText, 2000),
            ]);

            return $this->fallback('Maaf, format jawaban dari Gemini tidak sesuai. Coba lagi ya.');
        }

        $action = $decoded['action'] ?? 'chat';

        if (!in_array($action, ['chat', 'add_task', 'add_expense'], true)) {
            $action = 'chat';
        }

        $reply = trim((string) ($decoded['reply'] ?? ''));
        $dataName = trim((string) ($decoded['data_name'] ?? ''));
        $dataAmount = $decoded['data_amount'] ?? 0;

        return [
            'ok' => true,
            'action' => $action,
            'reply' => $reply !== '' ? $reply : 'Kobi agak bingung sama pesanmu tadi.',
            'data_name' => $dataName,
            'data_amount' => $dataAmount,
        ];
    }

    private function fallback(string $reply): array
    {
        return [
            'ok' => false,
            'action' => 'chat',
            'reply' => $reply,
            'data_name' => '',
            'data_amount' => 0,
        ];
    }
}
