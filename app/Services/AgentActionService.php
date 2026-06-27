<?php

namespace App\Services;

use App\Jobs\TriggerN8nWorkflowJob;
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
    public function __construct(
        private N8nService $n8nService,
        private GoalService $goalService,
        private HabitService $habitService,
        private StudyPlanService $studyPlanService,
        private MemoryService $memoryService,
    ) {
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
            'goal_tracking' => $this->handleGoalTracking($aiData, $event),
            'study_planner' => $this->handleStudyPlanner($aiData, $event),
            'habit_tracker' => $this->handleHabitTracker($aiData, $event),
            'memory_update' => $this->handleMemoryUpdate($aiData, $event),
            'natural_command' => $this->handleNaturalCommand($aiData, $event),
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
                ->withoutVerifying()
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

    // -------------------------------------------------------------------------
    //  Domain handlers — Database-first, N8N optional
    // -------------------------------------------------------------------------

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

        // N8N is optional — dispatched asynchronously after DB persistence.
        $this->dispatchN8n('create_reminder', 'reminder', [
            'reminder_id' => $reminder->id,
            'title' => $reminder->title,
            'description' => $reminder->description,
            'remind_at' => $remindAt?->toIso8601String(),
            'channel' => $reminder->channel,
        ], $event);

        return $this->reply(
            $aiData['reply'] ?? 'Siap, pengingatnya sudah Kobi catat.',
            null,
            'completed',
            [
                'reminder_id' => $reminder->id,
                'automation' => $this->automationMeta('reminder'),
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

    /**
     * Handle goal_tracking — persist via GoalService, then async N8N.
     */
    private function handleGoalTracking(array $aiData, ?AgentEvent $event): array
    {
        $data = $this->normalizeData($aiData);
        $title = $data['name'] !== '' ? $data['name'] : $data['description'];

        if ($title === '') {
            Log::warning('Kobi goal_tracking missing title.');
            return $this->reply('Nama goal belum disebut. Coba ulang ya.', null, 'failed_action');
        }

        $result = $this->goalService->createGoal([
            'title' => $title,
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'priority' => $data['priority'] !== '' ? $data['priority'] : 'medium',
        ]);

        if (!$result['ok']) {
            Log::error('Failed to create goal via GoalService.', [
                'error' => $result['message'] ?? 'Unknown',
            ]);

            return $this->reply(
                'Maaf, Kobi gagal menyimpan goal. Coba lagi ya.',
                null,
                'failed_action',
                ['error' => $result['message'] ?? 'Unknown']
            );
        }

        $goalId = $result['data']['goal']['id'] ?? null;

        $this->dispatchN8n('goal_tracking', 'goal_tracking', [
            'goal_id' => $goalId,
            'title' => $title,
        ], $event);

        return $this->reply(
            $aiData['reply'] ?? 'Goal sudah Kobi catat.',
            null,
            'completed',
            [
                'goal_id' => $goalId,
                'automation' => $this->automationMeta('goal_tracking'),
            ]
        );
    }

    /**
     * Handle habit_tracker — persist via HabitService, then async N8N.
     */
    private function handleHabitTracker(array $aiData, ?AgentEvent $event): array
    {
        $data = $this->normalizeData($aiData);
        $name = $data['name'] !== '' ? $data['name'] : $data['description'];

        if ($name === '') {
            Log::warning('Kobi habit_tracker missing name.');
            return $this->reply('Nama habit belum disebut. Coba ulang ya.', null, 'failed_action');
        }

        $result = $this->habitService->createHabit([
            'name' => $name,
            'description' => $data['description'] !== '' ? $data['description'] : null,
        ]);

        if (!$result['ok']) {
            Log::error('Failed to create habit via HabitService.', [
                'error' => $result['message'] ?? 'Unknown',
            ]);

            return $this->reply(
                'Maaf, Kobi gagal menyimpan habit. Coba lagi ya.',
                null,
                'failed_action',
                ['error' => $result['message'] ?? 'Unknown']
            );
        }

        $habitId = $result['data']['habit']['id'] ?? null;

        $this->dispatchN8n('habit_tracker', 'habit_tracker', [
            'habit_id' => $habitId,
            'name' => $name,
        ], $event);

        return $this->reply(
            $aiData['reply'] ?? 'Habit sudah Kobi catat.',
            null,
            'completed',
            [
                'habit_id' => $habitId,
                'automation' => $this->automationMeta('habit_tracker'),
            ]
        );
    }

    /**
     * Handle study_planner — persist via StudyPlanService, then async N8N.
     */
    private function handleStudyPlanner(array $aiData, ?AgentEvent $event): array
    {
        $data = $this->normalizeData($aiData);
        $subject = $data['name'] !== '' ? $data['name'] : $data['description'];

        if ($subject === '') {
            Log::warning('Kobi study_planner missing subject.');
            return $this->reply('Nama mata pelajaran belum disebut. Coba ulang ya.', null, 'failed_action');
        }

        $result = $this->studyPlanService->createStudyPlan([
            'subject' => $subject,
            'description' => $data['description'] !== '' ? $data['description'] : null,
            'notes' => $data['notes'] !== '' ? $data['notes'] : null,
        ]);

        if (!$result['ok']) {
            Log::error('Failed to create study plan via StudyPlanService.', [
                'error' => $result['message'] ?? 'Unknown',
            ]);

            return $this->reply(
                'Maaf, Kobi gagal menyimpan rencana belajar. Coba lagi ya.',
                null,
                'failed_action',
                ['error' => $result['message'] ?? 'Unknown']
            );
        }

        $planId = $result['data']['study_plan']['id'] ?? null;

        $this->dispatchN8n('study_planner', 'study_planner', [
            'study_plan_id' => $planId,
            'subject' => $subject,
        ], $event);

        return $this->reply(
            $aiData['reply'] ?? 'Rencana belajar sudah Kobi catat.',
            null,
            'completed',
            [
                'study_plan_id' => $planId,
                'automation' => $this->automationMeta('study_planner'),
            ]
        );
    }

    /**
     * Handle memory_update — persist via MemoryService, then async N8N.
     */
    private function handleMemoryUpdate(array $aiData, ?AgentEvent $event): array
    {
        $data = $this->normalizeData($aiData);
        $title = $data['name'] !== '' ? $data['name'] : $data['description'];
        $content = $data['description'] !== '' ? $data['description'] : $data['name'];

        if ($title === '' && $content === '') {
            Log::warning('Kobi memory_update missing content.');
            return $this->reply('Isi memori belum ada. Coba ulang ya.', null, 'failed_action');
        }

        $result = $this->memoryService->storeMemory([
            'title' => $title !== '' ? $title : 'Untitled',
            'content' => $content !== '' ? $content : $title,
            'category' => $data['category'] !== '' ? $data['category'] : 'general',
            'source' => 'agent',
        ]);

        if (!$result['ok']) {
            Log::error('Failed to store memory via MemoryService.', [
                'error' => $result['message'] ?? 'Unknown',
            ]);

            return $this->reply(
                'Maaf, Kobi gagal menyimpan memori. Coba lagi ya.',
                null,
                'failed_action',
                ['error' => $result['message'] ?? 'Unknown']
            );
        }

        $memoryId = $result['data']['memory']['id'] ?? null;

        $this->dispatchN8n('memory_update', 'memory_update', [
            'memory_id' => $memoryId,
            'title' => $title,
        ], $event);

        return $this->reply(
            $aiData['reply'] ?? 'Memori sudah Kobi simpan.',
            null,
            'completed',
            [
                'memory_id' => $memoryId,
                'automation' => $this->automationMeta('memory_update'),
            ]
        );
    }

    /**
     * Handle natural_command — attempt lightweight domain resolution
     * before falling back to a chat-like reply.
     *
     * If the AI response clearly represents a Goal, Habit, Reminder, Memory,
     * or Study action, route it to the corresponding domain handler instead
     * of replying as plain chat.
     */
    private function handleNaturalCommand(array $aiData, ?AgentEvent $event): array
    {
        $data = $this->normalizeData($aiData);
        $workflow = $this->normalizeWorkflow($aiData);
        $name = $data['name'] !== '' ? $data['name'] : $data['description'];

        // Lightweight domain resolution: if AI provided structured data with a name,
        // attempt to route to the appropriate domain handler based on workflow hints.
        if ($name !== '' && $workflow['name'] !== '') {
            $resolved = $this->resolveDomainFromWorkflow($workflow['name']);

            if ($resolved !== null) {
                return $this->execute(array_merge($aiData, ['action' => $resolved]), $event);
            }
        }

        // Fallback: treat as chat with optional async N8N trigger.
        if ($workflow['name'] !== '') {
            $this->dispatchN8n('natural_command', $workflow['name'], $workflow['payload'], $event);
        }

        return $this->reply(
            $aiData['reply'] ?? 'Fitur ini sedang disiapkan. Nanti Kobi kabarin ya.',
            null,
            'completed',
            [
                'payload' => $aiData['data'] ?? [],
                'automation' => $workflow['name'] !== '' ? $this->automationMeta($workflow['name']) : null,
            ]
        );
    }

    // -------------------------------------------------------------------------
    //  Data normalization helpers
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    //  N8N async dispatch helpers
    // -------------------------------------------------------------------------

    /**
     * Fire-and-forget N8N workflow dispatch.
     *
     * Dispatched outside any database transaction. The job's internal
     * try/catch ensures N8N failures never propagate to the caller.
     */
    private function dispatchN8n(string $action, string $workflowName, array $payload, ?AgentEvent $event): void
    {
        TriggerN8nWorkflowJob::dispatch($action, $workflowName, $payload, $event?->id);
    }

    /**
     * Build standard automation metadata for domain responses.
     *
     * This metadata is informational only — it never changes the business status.
     */
    private function automationMeta(string $workflowName): array
    {
        return [
            'attempted' => true,
            'async' => true,
            'workflow_name' => $workflowName,
        ];
    }

    /**
     * Map known workflow name patterns to domain actions for natural_command resolution.
     */
    private function resolveDomainFromWorkflow(string $workflowName): ?string
    {
        $map = [
            'goal' => 'goal_tracking',
            'habit' => 'habit_tracker',
            'study' => 'study_planner',
            'memory' => 'memory_update',
            'remind' => 'create_reminder',
        ];

        $lower = strtolower($workflowName);

        foreach ($map as $keyword => $action) {
            if (str_contains($lower, $keyword)) {
                return $action;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    //  URL validation helpers
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    //  Reply builder
    // -------------------------------------------------------------------------

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
