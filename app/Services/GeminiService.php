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
            . '   "target_task": "nama tugas yang ingin dicari/diubah/dihapus",'
            . '   "name": "nama tugas baru (jika ingin mengubah judul, atau membuat baru)",'
            . '   "description": "...",'
            . '   "status": "...",'
            . '   "amount": 0,'
            . '   "category": "...",'
            . '   "datetime": "...",'
            . '   "priority": "...",'
            . '   "notes": "..."'
            . ' },'
            . ' "workflow": {'
            . '   "name": "",'
            . '   "payload": {}'
            . ' }'
            . ' }'
            . ' Aturan: jika user hanya ngobrol, action chat. Jika mencatat/membuat tugas, action create_task. Untuk menghapus tugas, action delete_task. Untuk mencari tugas, action search_task.'
            . ' Jika mengubah satu atau lebih field dari sebuah tugas (seperti mengganti nama, prioritas, dan deadline sekaligus), gunakan action update_task. Jika spesifik hanya status/priority/deadline, boleh gunakan update_status/update_priority/update_deadline.'
            . ' PENTING: Wajib sertakan "target_task" untuk operasi update/delete/search.'
            . ' PENTING: Hanya masukkan key di dalam "data" jika user memintanya untuk diubah. Jika user meminta MENGHAPUS suatu nilai (contoh: "hapus deskripsi", "belum selesai"), isi key tersebut dengan null atau string kosong "".'
            . ' Lakukan normalisasi nilai secara ketat! Untuk tanggal/waktu, konversi menjadi format YYYY-MM-DD. Untuk status, konversi ke enum: "pending", "in_progress", atau "completed". Untuk prioritas, konversi ke enum: "low", "medium", atau "high".'
            . ' Jika mencatat pengeluaran, action add_expense. Jika meminta pengingat, action create_reminder.'
            . ' Jika meminta automation, gunakan trigger_workflow dengan workflow.name yang sesuai.';

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

        $normalizedData = [];
        if (array_key_exists('target_task', $data)) {
            $normalizedData['target_task'] = $data['target_task'] === null ? null : trim((string) $data['target_task']);
        }
        if (array_key_exists('name', $data)) {
            $normalizedData['name'] = $data['name'] === null ? null : trim((string) $data['name']);
        } elseif ($dataNameFallback !== '') {
            $normalizedData['name'] = $dataNameFallback;
        }

        if (array_key_exists('description', $data)) {
            $normalizedData['description'] = $data['description'] === null ? null : trim((string) $data['description']);
        }
        if (array_key_exists('status', $data)) {
            $normalizedData['status'] = $data['status'] === null ? null : trim((string) $data['status']);
        }
        if (array_key_exists('amount', $data) || $dataAmountFallback !== 0) {
            $normalizedData['amount'] = $data['amount'] ?? $dataAmountFallback;
        }
        if (array_key_exists('category', $data)) {
            $normalizedData['category'] = $data['category'] === null ? null : trim((string) $data['category']);
        }
        if (array_key_exists('datetime', $data)) {
            $normalizedData['deadline_at'] = $data['datetime'] === null ? null : trim((string) $data['datetime']);
        } elseif (array_key_exists('deadline_at', $data)) {
            $normalizedData['deadline_at'] = $data['deadline_at'] === null ? null : trim((string) $data['deadline_at']);
        }
        if (array_key_exists('priority', $data)) {
            $normalizedData['priority'] = $data['priority'] === null ? null : trim((string) $data['priority']);
        }
        if (array_key_exists('notes', $data)) {
            $normalizedData['notes'] = $data['notes'] === null ? null : trim((string) $data['notes']);
        }

        return [
            'ok' => true,
            'action' => $action,
            'reply' => $reply !== '' ? $reply : 'Kobi agak bingung sama pesanmu tadi.',
            'data' => $normalizedData,
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
