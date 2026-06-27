<?php

namespace App\Jobs;

use App\Models\AgentEvent;
use App\Services\N8nService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Asynchronously trigger an N8N workflow after a domain action completes.
 *
 * This job is fire-and-forget: N8N failures are logged as warnings
 * but never propagate back to the user or affect database state.
 *
 * With QUEUE_CONNECTION=sync the job runs inline but inside its own
 * try/catch isolation. When switched to database/redis it becomes
 * fully non-blocking with zero code changes.
 */
class TriggerN8nWorkflowJob implements ShouldQueue
{
    use Queueable;

    /**
     * No automatic retries — N8N errors are non-critical.
     */
    public int $tries = 1;

    /**
     * N8nService uses a 60 s HTTP timeout internally.
     */
    public int $timeout = 65;

    public function __construct(
        public string $action,
        public string $workflowName,
        public array $payload,
        public ?string $eventId,
    ) {
    }

    public function handle(N8nService $n8nService): void
    {
        $event = $this->eventId ? AgentEvent::find($this->eventId) : null;

        $result = $n8nService->triggerWorkflow($this->workflowName, $this->payload, $event);

        $logContext = [
            'action' => $this->action,
            'workflow_name' => $this->workflowName,
            'event_id' => $this->eventId,
            'workflow_run_id' => $result['workflow_run_id'] ?? null,
            'n8n_ok' => $result['ok'] ?? false,
        ];

        if ($result['ok'] ?? false) {
            Log::info('N8N workflow triggered successfully (async).', $logContext);
        } else {
            Log::warning('N8N workflow failed (non-critical, async).', array_merge($logContext, [
                'error_message' => $result['error_message'] ?? 'Unknown',
            ]));
        }
    }

    /**
     * Handle a job failure — log and swallow so the queue worker stays healthy.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('N8N workflow job exception (non-critical).', [
            'action' => $this->action,
            'workflow_name' => $this->workflowName,
            'event_id' => $this->eventId,
            'error' => $exception->getMessage(),
        ]);
    }
}
