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
            . ' "action": "chat | add_task | add_expense | create_reminder | trigger_workflow | natural_command | goal_tracking | study_planner | habit_tracker | memory_update",'
            . ' "reply": "Balasan natural untuk user",'
            . ' "data": {'
            . '   "name": "",'
            . '   "description": "",'
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
            . ' Aturan: jika user hanya ngobrol, action chat. Jika mencatat tugas, action add_task.'
            . ' Jika mencatat pengeluaran, action add_expense. Jika meminta pengingat, action create_reminder.'
            . ' Jika meminta automation seperti daily summary, weekly review, budget alert, study planner, habit follow-up, atau proactive suggestion,'
            . ' gunakan trigger_workflow dengan workflow.name yang sesuai.';

        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            self::MODEL,
            $apiKey
        );

        $response = Http::timeout(30)
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

        if (!$response->successful()) {
            Log::warning('Gemini API request failed.', [
                'status' => $response->status(),
            ]);

            return $this->fallback('Maaf, Kobi sedang mengalami gangguan dari Gemini. Coba lagi sebentar ya.', 'failed_ai');
        }

        $rawText = data_get($response->json(), 'candidates.0.content.parts.0.text');

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
