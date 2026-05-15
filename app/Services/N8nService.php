<?php

namespace App\Services;

use App\Models\AgentEvent;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class N8nService
{
    public function triggerWorkflow(string $workflowName, array $payload, ?AgentEvent $event = null): array
    {
        $webhookUrl = trim((string) env('N8N_WEBHOOK_URL', ''));

        $workflowRun = WorkflowRun::create([
            'agent_event_id' => $event?->id,
            'workflow_name' => $workflowName,
            'status' => 'pending',
            'input_payload' => $payload,
            'started_at' => now(),
        ]);

        if ($webhookUrl === '') {
            $workflowRun->update([
                'status' => 'failed',
                'error_message' => 'N8N webhook URL is not configured.',
                'finished_at' => now(),
            ]);

            return [
                'ok' => false,
                'status' => 'failed',
                'workflow_run_id' => $workflowRun->id,
                'workflow_name' => $workflowName,
                'error_message' => 'N8N webhook URL is not configured.',
            ];
        }

        $postPayload = [
            'source' => 'laravel',
            'event_id' => $event?->id,
            'workflow_run_id' => $workflowRun->id,
            'workflow_name' => $workflowName,
            'payload' => $payload,
        ];

        try {
            $response = Http::timeout(60)
                ->asJson()
                ->post($webhookUrl, $postPayload);
        } catch (\Throwable $exception) {
            Log::error('N8N webhook request exception.', [
                'workflow' => $workflowName,
                'error' => $exception->getMessage(),
            ]);

            $workflowRun->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            return [
                'ok' => false,
                'status' => 'failed',
                'workflow_run_id' => $workflowRun->id,
                'workflow_name' => $workflowName,
                'error_message' => $exception->getMessage(),
            ];
        }

        Log::info('N8N webhook response received.', [
            'workflow' => $workflowName,
            'status' => $response->status(),
        ]);

        $responseData = $response->json();
        $responseStatus = is_array($responseData) ? ($responseData['status'] ?? null) : null;

        if (!in_array($responseStatus, ['pending', 'running', 'completed', 'failed'], true)) {
            $responseStatus = $response->status() === 202 ? 'running' : ($response->successful() ? 'completed' : 'failed');
        }

        $updates = [
            'status' => $responseStatus,
        ];

        if (is_array($responseData)) {
            $updates['output_payload'] = $responseData;
        } elseif (is_string($response->body()) && $response->body() !== '') {
            $updates['output_payload'] = [
                'raw' => Str::limit($response->body(), 2000),
            ];
        }

        if ($responseStatus === 'completed' || $responseStatus === 'failed') {
            $updates['finished_at'] = now();
        }

        if (!$response->successful()) {
            $updates['status'] = 'failed';
            $updates['error_message'] = 'N8N webhook request failed.';
            $updates['finished_at'] = now();
        }

        $workflowRun->update($updates);

        return [
            'ok' => $workflowRun->status !== 'failed',
            'status' => $workflowRun->status,
            'workflow_run_id' => $workflowRun->id,
            'workflow_name' => $workflowName,
            'error_message' => $workflowRun->error_message,
        ];
    }
}
