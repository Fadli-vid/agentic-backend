<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeminiService
{
    private const MODEL = 'gemini-3-flash-preview';
    private const ALLOWED_ACTIONS = [
        'chat',
        'add_task',
        'create_task',
        'update_task',
        'delete_task',
        'update_status',
        'update_priority',
        'update_deadline',
        'search_task',
        'add_expense',
        'create_reminder',
        'trigger_workflow',
        'natural_command',
        'goal_tracking',
        'study_planner',
        'habit_tracker',
        'memory_update',
    ];

    public function analyzeMessage(string $text): array
    {
        $apiKey = env('GEMINI_API_KEY');

        if (!$apiKey) {
            Log::warning('Gemini API key is not configured.');
            return $this->fallback('Maaf, Kobi belum siap merespons karena konfigurasi AI belum lengkap.', 'failed_ai');
        }

        $systemPrompt = 'Kamu adalah Kobi, asisten AI pribadi yang cerdas. Tugas utamamu adalah menganalisis pesan dan membalasnya HANYA dalam format JSON.'
            . ' Output harus JSON valid saja tanpa teks tambahan.'
            . ' Gunakan schema:'
            . ' {'
            . ' "action": "chat | create_task | add_task | update_task | delete_task | update_status | update_priority | update_deadline | search_task | add_expense | create_reminder | trigger_workflow | natural_command | goal_tracking | study_planner | habit_tracker | memory_update",'
            . ' "reply": "Balasan natural untuk user",'
            . ' "data": {'
            . '   "name": "",'
            . '   "description": "",'
            . '   "status": "",'
            . '   "amount": 0,'
            . '   "category": "",'
            . '   "datetime": "",'
            . '   "priority": "",'
            . '   "notes": ""'
            . ' },'
            . ' "workflow": {'
            . '   "name": "",'
            . '   "payload": {}'
            . ' }'
            . ' }'
            . ' Aturan: jika user hanya ngobrol, action chat. Jika mencatat/membuat tugas, action create_task atau add_task. Untuk mengubah status tugas, action update_status. Mengubah prioritas tugas, action update_priority. Mengubah deadline, action update_deadline.'
            . ' PENTING: Lakukan normalisasi nilai secara ketat! Untuk tanggal/waktu (seperti "besok", "jumat", "minggu depan"), konversi menjadi format YYYY-MM-DD. Untuk status (seperti "start working", "selesai"), konversi ke enum: "pending", "in_progress", atau "completed". Untuk prioritas (seperti "tinggi", "rendah"), konversi ke enum: "low", "medium", atau "high".'
            . ' Jika mencatat pengeluaran, action add_expense. Jika meminta pengingat, action create_reminder.'
            . ' Jika meminta automation seperti daily summary, weekly review, budget alert, study planner, habit follow-up, atau proactive suggestion,'
            . ' gunakan trigger_workflow dengan workflow.name yang sesuai.';

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            self::MODEL,
            $apiKey
        );

        $startTime = microtime(true);

        try {
            $response = Http::timeout(60)
                ->connectTimeout(15)
                ->retry(3, 1000)
                ->withoutVerifying()
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
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);
            Log::error('Gemini connection exception (timeout/connect).', [
                'error' => $e->getMessage(),
                'elapsed_ms' => $elapsedMs,
            ]);
            return $this->fallback('Maaf, Kobi tidak bisa terhubung ke Gemini karena koneksi terputus (timeout). Coba lagi nanti ya.', 'failed_ai_timeout');
        } catch (\Throwable $e) {
            $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);
            Log::error('Gemini request exception.', [
                'error' => $e->getMessage(),
                'elapsed_ms' => $elapsedMs,
            ]);
            return $this->fallback('Maaf, Kobi mengalami masalah saat menghubungi Gemini. Coba lagi nanti ya.', 'failed_ai_exception');
        }

        $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

        if (!$response->successful()) {
            Log::warning('Gemini API request failed.', [
                'status' => $response->status(),
                'elapsed_ms' => $elapsedMs,
            ]);

            return $this->fallback('Maaf, Kobi sedang mengalami gangguan dari Gemini. Coba lagi sebentar ya.', 'failed_ai');
        }

        $json = $response->json();
        
        Log::info('Gemini response received.', [
            'elapsed_ms' => $elapsedMs,
            'finishReason' => data_get($json, 'candidates.0.finishReason'),
            'usageMetadata' => data_get($json, 'usageMetadata'),
        ]);

        $rawText = data_get($json, 'candidates.0.content.parts.0.text');

        if (!is_string($rawText)) {
            Log::warning('Gemini response missing text.');
            return $this->fallback('Maaf, jawaban dari Gemini tidak bisa dibaca. Coba lagi ya.', 'failed_ai');
        }

        $decoded = json_decode($rawText, true);

        if (!is_array($decoded)) {
            Log::warning('Gemini returned invalid JSON.', [
                'raw' => Str::limit($rawText, 2000),
            ]);

            return $this->fallback('Maaf, format jawaban dari Gemini tidak sesuai. Coba lagi ya.', 'failed_json');
        }

        $action = $decoded['action'] ?? 'chat';

        if (!in_array($action, self::ALLOWED_ACTIONS, true)) {
            $action = 'chat';
        }

        $reply = trim((string) ($decoded['reply'] ?? ''));
        $rawData = $decoded['data'] ?? [];
        $rawWorkflow = $decoded['workflow'] ?? [];

        $data = is_array($rawData) ? $rawData : [];
        $workflow = is_array($rawWorkflow) ? $rawWorkflow : [];

        $dataNameFallback = trim((string) ($decoded['data_name'] ?? ''));
        $dataAmountFallback = $decoded['data_amount'] ?? 0;

        $workflowName = trim((string) ($workflow['name'] ?? $decoded['workflow_name'] ?? ''));
        $workflowPayload = $workflow['payload'] ?? [];
        $workflowPayload = is_array($workflowPayload) ? $workflowPayload : [];

        return [
            'ok' => true,
            'action' => $action,
            'reply' => $reply !== '' ? $reply : 'Kobi agak bingung sama pesanmu tadi.',
            'data' => [
                'name' => trim((string) ($data['name'] ?? $dataNameFallback)),
                'description' => trim((string) ($data['description'] ?? '')),
                'status' => trim((string) ($data['status'] ?? '')),
                'amount' => $data['amount'] ?? $dataAmountFallback,
                'category' => trim((string) ($data['category'] ?? '')),
                'datetime' => trim((string) ($data['datetime'] ?? '')),
                'priority' => trim((string) ($data['priority'] ?? '')),
                'notes' => trim((string) ($data['notes'] ?? '')),
            ],
            'workflow' => [
                'name' => $workflowName,
                'payload' => $workflowPayload,
            ],
        ];
    }

    private function fallback(string $reply, string $errorType): array
    {
        return [
            'ok' => false,
            'action' => 'chat',
            'reply' => $reply,
            'data' => [
                'name' => '',
                'description' => '',
                'amount' => 0,
                'category' => '',
                'datetime' => '',
                'priority' => '',
                'notes' => '',
            ],
            'workflow' => [
                'name' => '',
                'payload' => [],
            ],
            'error_type' => $errorType,
        ];
    }
}
