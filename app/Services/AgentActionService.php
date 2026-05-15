<?php

namespace App\Services;

use App\Models\AgentEvent;
use App\Models\Expense;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AgentActionService
{
    public function __construct(private N8nService $n8nService)
    {
    }

    public function execute(array $aiData, ?AgentEvent $event = null): array
    {
        $action = $aiData['action'] ?? 'chat';

        if (!($aiData['ok'] ?? true)) {
            return $this->reply(
                $aiData['reply'] ?? 'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.',
                null,
                'failed_ai'
            );
        }

        Log::info('Kobi action selected.', [
            'action' => $action,
        ]);

        return match ($action) {
            'add_task' => $this->handleAddTask($aiData),
            'add_expense' => $this->handleAddExpense($aiData),
            'create_reminder' => $this->handleCreateReminder($aiData, $event),
            'trigger_workflow' => $this->handleTriggerWorkflow($aiData, $event),
            'natural_command',
            'goal_tracking',
            'study_planner',
            'habit_tracker',
            'memory_update' => $this->handleAutomationFallback($aiData, $event),
            'chat' => $this->reply($aiData['reply'] ?? 'Kobi siap bantu. Coba jelaskan lagi ya.'),
            default => $this->reply($aiData['reply'] ?? 'Kobi siap bantu. Coba jelaskan lagi ya.'),
        };
    }

    public function handleN8nCommand(string $text, ?AgentEvent $event = null): ?array
    {
        if (!preg_match('/^\/task(\s|$)/i', trim($text))) {
            return null;
        }

        $taskContent = trim(preg_replace('/^\/task(\s|$)/i', '', $text));

        if ($taskContent === '') {
            return $this->reply('Format /task belum ada isinya. Contoh: /task Follow up invoice.', null, 'failed_action');
        }

        $webhookUrl = trim((string) env('N8N_WEBHOOK_URL', ''));

        if ($webhookUrl === '') {
            Log::warning('N8N webhook URL is not configured.');
            return $this->reply('Webhook n8n belum dikonfigurasi.', null, 'failed_action');
        }

        if ($this->looksLikeDoubleUrl($webhookUrl)) {
            Log::warning('N8N webhook URL looks malformed.', [
                'url' => $this->sanitizeWebhookUrl($webhookUrl),
            ]);
            return $this->reply('URL n8n terlihat tidak valid. Cek konfigurasi dulu ya.', null, 'failed_action');
        }

        Log::info('Sending task to N8N webhook.', [
            'url' => $this->sanitizeWebhookUrl($webhookUrl),
        ]);

        try {
            $response = Http::timeout(45)
                ->asJson()
                ->post($webhookUrl, [
                    'source' => 'Telegram Kobi',
                    'user' => 'Rain',
                    'task' => $taskContent,
                    'timestamp' => now()->toDateTimeString(),
                ]);
        } catch (\Throwable $exception) {
            Log::error('N8N webhook request exception.', [
                'url' => $this->sanitizeWebhookUrl($webhookUrl),
                'error' => $exception->getMessage(),
            ]);

            return $this->reply(
                'Waduh, markas n8n sepertinya sedang sibuk atau tidak bisa dihubungi nih.',
                null,
                'failed_action',
                [
                    'error' => $exception->getMessage(),
                ]
            );
        }

        Log::info('N8N webhook response received.', [
            'status' => $response->status(),
            'body' => Str::limit((string) $response->body(), 500),
        ]);

        if ($response->successful()) {
            return $this->reply(
                "Siap! Tugas otomatisasi: *{$taskContent}* sudah Kobi lempar ke markas n8n!",
                'Markdown',
                'completed',
                [
                    'n8n_status' => $response->status(),
                ]
            );
        }

        Log::warning('N8N webhook request failed.', [
            'status' => $response->status(),
            'body' => Str::limit((string) $response->body(), 500),
            'url' => $this->sanitizeWebhookUrl($webhookUrl),
        ]);

        return $this->reply(
            'Waduh, markas n8n sepertinya sedang sibuk atau tidak bisa dihubungi nih.',
            null,
            'failed_action',
            [
                'n8n_status' => $response->status(),
            ],
            'N8N webhook request failed.'
        );
    }

    private function handleAddTask(array $aiData): array
    {
        $data = $this->normalizeData($aiData);
        $name = $data['name'] !== '' ? $data['name'] : $data['description'];

        if ($name === '') {
            Log::warning('Kobi add_task missing name.');
            return $this->reply('Nama tugasnya belum disebut. Coba ulang dengan lebih jelas ya.', null, 'failed_action');
        }

        try {
            $task = Task::create([
                'name' => $name,
                'is_completed' => false,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create task.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->reply(
                'Maaf, Kobi gagal menyimpan tugasnya. Coba lagi ya.',
                null,
                'failed_action',
                [
                    'error' => $exception->getMessage(),
                ]
            );
        }

        return $this->reply(
            $aiData['reply'] ?? 'Oke, tugasnya sudah Kobi catat.',
            null,
            'completed',
            [
                'task_id' => $task->id,
            ]
        );
    }

    private function handleAddExpense(array $aiData): array
    {
        $data = $this->normalizeData($aiData);
        $description = $data['description'] !== '' ? $data['description'] : $data['name'];
        $amountValue = $data['amount'] ?? null;

        if ($description === '') {
            Log::warning('Kobi add_expense missing description.');
            return $this->reply('Deskripsi pengeluarannya belum ada. Coba ulang ya.', null, 'failed_action');
        }

        if (!is_numeric($amountValue) || (float) $amountValue <= 0) {
            Log::warning('Kobi add_expense invalid amount.');
            return $this->reply('Nominal pengeluarannya belum valid. Pastikan angkanya lebih dari 0.', null, 'failed_action');
        }

        try {
            $expense = Expense::create([
                'amount' => (float) $amountValue,
                'description' => $description,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create expense.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->reply(
                'Maaf, Kobi gagal menyimpan pengeluaran. Coba lagi ya.',
                null,
                'failed_action',
                [
                    'error' => $exception->getMessage(),
                ]
            );
        }

        return $this->reply(
            $aiData['reply'] ?? 'Siap, pengeluarannya sudah Kobi catat.',
            null,
            'completed',
            [
                'expense_id' => $expense->id,
            ]
        );
    }

    private function handleCreateReminder(array $aiData, ?AgentEvent $event): array
    {
        $data = $this->normalizeData($aiData);
        $title = $data['name'] !== '' ? $data['name'] : $data['description'];

        if ($title === '') {
            Log::warning('Kobi create_reminder missing title.');
            return $this->reply('Judul pengingatnya belum ada. Coba ulang ya.', null, 'failed_action');
        }

        $remindAt = null;
        $remindAtRaw = $data['datetime'];

        if ($remindAtRaw !== '') {
            try {
                $remindAt = Carbon::parse($remindAtRaw);
            } catch (\Throwable $exception) {
                Log::warning('Kobi create_reminder invalid datetime.', [
                    'value' => $remindAtRaw,
                ]);
            }
        }

        try {
            $reminder = Reminder::create([
                'agent_event_id' => $event?->id,
                'title' => $title,
                'description' => $data['description'] !== '' ? $data['description'] : null,
                'remind_at' => $remindAt,
                'channel' => 'telegram',
                'status' => 'pending',
                'payload' => [
                    'data' => $data,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create reminder.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->reply(
                'Maaf, Kobi gagal menyimpan pengingat. Coba lagi ya.',
                null,
                'failed_action',
                [
                    'error' => $exception->getMessage(),
                ]
            );
        }

        $workflowPayload = [
            'reminder_id' => $reminder->id,
            'title' => $reminder->title,
            'description' => $reminder->description,
            'remind_at' => $remindAt?->toIso8601String(),
            'channel' => $reminder->channel,
        ];

        $workflowResult = $this->n8nService->triggerWorkflow('reminder', $workflowPayload, $event);

        return $this->reply(
            $aiData['reply'] ?? 'Siap, pengingatnya sudah Kobi catat.',
            null,
            'completed',
            [
                'reminder_id' => $reminder->id,
                'workflow' => $workflowResult,
            ]
        );
    }

    private function handleTriggerWorkflow(array $aiData, ?AgentEvent $event): array
    {
        $workflow = $this->normalizeWorkflow($aiData);
        $workflowName = $workflow['name'];

        if ($workflowName === '') {
            return $this->reply('Nama workflow belum disebut. Coba ulang ya.', null, 'failed_action');
        }

        $workflowResult = $this->n8nService->triggerWorkflow($workflowName, $workflow['payload'], $event);

        if (!($workflowResult['ok'] ?? false)) {
            return $this->reply(
                'Maaf, workflow belum bisa dipicu saat ini. Coba lagi ya.',
                null,
                'failed_action',
                [
                    'workflow' => $workflowResult,
                ],
                $workflowResult['error_message'] ?? 'N8N webhook failed.'
            );
        }

        return $this->reply(
            $aiData['reply'] ?? 'Siap, workflow sudah Kobi jalankan.',
            null,
            'completed',
            [
                'workflow' => $workflowResult,
            ]
        );
    }

    private function handleAutomationFallback(array $aiData, ?AgentEvent $event): array
    {
        $workflow = $this->normalizeWorkflow($aiData);

        if ($workflow['name'] !== '') {
            return $this->handleTriggerWorkflow($aiData, $event);
        }

        return $this->reply(
            $aiData['reply'] ?? 'Fitur ini sedang disiapkan. Nanti Kobi kabarin ya.',
            null,
            'completed',
            [
                'payload' => $aiData['data'] ?? [],
            ]
        );
    }

    private function normalizeData(array $aiData): array
    {
        $data = $aiData['data'] ?? [];
        $data = is_array($data) ? $data : [];

        return [
            'name' => trim((string) ($data['name'] ?? $aiData['data_name'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'amount' => $data['amount'] ?? $aiData['data_amount'] ?? 0,
            'category' => trim((string) ($data['category'] ?? '')),
            'datetime' => trim((string) ($data['datetime'] ?? '')),
            'priority' => trim((string) ($data['priority'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
        ];
    }

    private function normalizeWorkflow(array $aiData): array
    {
        $workflow = $aiData['workflow'] ?? [];
        $workflow = is_array($workflow) ? $workflow : [];

        $payload = $workflow['payload'] ?? [];
        $payload = is_array($payload) ? $payload : [];

        return [
            'name' => trim((string) ($workflow['name'] ?? $aiData['workflow_name'] ?? '')),
            'payload' => $payload,
        ];
    }

    private function looksLikeDoubleUrl(string $url): bool
    {
        $count = substr_count($url, 'http://') + substr_count($url, 'https://');

        return $count > 1;
    }

    private function sanitizeWebhookUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return '[invalid-url]';
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        if ($host === '') {
            $clean = preg_replace('/[?#].*/', '', $url);
            return $clean ?: '[invalid-url]';
        }

        return $scheme . '://' . $host . $port . $path;
    }

    private function reply(
        string $text,
        ?string $parseMode = null,
        string $status = 'completed',
        array $result = [],
        ?string $errorMessage = null
    ): array {
        return [
            'reply' => $text,
            'parse_mode' => $parseMode,
            'status' => $status,
            'result' => $result,
            'error_message' => $errorMessage,
        ];
    }
}
