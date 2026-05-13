<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Task;
use App\Models\Expense;


class TelegramController extends Controller
{
    public function handleWebhook(Request $request)
    {   set_time_limit(120);
        $chatId = $request->input('message.chat.id');
        $text = $request->input('message.text');

        if ($chatId && $text) {
            $telegramToken = env('TELEGRAM_BOT_TOKEN');
            $geminiKey = env('GEMINI_API_KEY');

            // 1. Instruksi Sistem Kobi
            $systemPrompt = 'Kamu adalah Kobi, asisten AI pribadi yang cerdas. Tugas utamamu adalah menganalisis pesan dan membalasnya HANYA dalam format JSON.
            Aturan format JSON:
            {
                "action": "chat" | "add_task" | "add_expense",
                "reply": "Pesan balasan darimu yang santai, asik, dan membantu",
                "data_name": "Nama tugas (jika action=add_task) atau Deskripsi pengeluaran (jika action=add_expense). Kosongkan jika action=chat",
                "data_amount": 100000 (Wajib diisi angka pengeluaran jika action=add_expense, default 0)
            }';

            // 2. Konsultasi dengan Gemini API
            $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key={$geminiKey}";
            
            // Perbaikan: Menggunakan withOptions untuk bypass SSL
            $response = Http::withOptions(['verify' => false])
                ->timeout(60)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($geminiUrl, [
                    'contents' => [['parts' => [['text' => $text]]]],
                    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                    'generationConfig' => ['response_mime_type' => 'application/json']
                ]);

            // Cek jika Gemini gagal merespons
            if (!$response->successful()) {
                Log::error('Gemini API Error: ' . $response->body());
                $this->sendTelegramMessage($chatId, $telegramToken, 'Maaf, koneksi otak Kobi ke Gemini sedang terputus.');
                return response()->json(['status' => 'error']);
            }

            // 3. Baca Hasil Pikiran AI
            $aiData = json_decode($response->json('candidates.0.content.parts.0.text'), true);

            // 4. Eksekusi Tindakan ke Database Supabase
            if (isset($aiData['action'])) {
                if ($aiData['action'] === 'add_task') {
                    Task::create([
                        'name' => $aiData['data_name'], 
                        'is_completed' => false
                    ]);
                } elseif ($aiData['action'] === 'add_expense') {
                    Expense::create([
                        'amount' => (int) $aiData['data_amount'],
                        'description' => $aiData['data_name']
                    ]);
                }
            }

            // 5. Balas ke Telegram Rain
            $replyText = $aiData['reply'] ?? 'Kobi agak bingung sama pesanmu tadi.';
            $this->sendTelegramMessage($chatId, $telegramToken, $replyText);
        }

        return response()->json(['status' => 'success']);
    }

    // Fungsi terpisah agar kode lebih rapi dan aman
    private function sendTelegramMessage($chatId, $token, $text)
    {
        Http::withOptions(['verify' => false])
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text
            ]);
    }
}