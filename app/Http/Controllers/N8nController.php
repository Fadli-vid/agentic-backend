<?php

namespace App\Http\Controllers;

use App\Models\AgentEvent;
use App\Models\WorkflowRun;
use Illuminate\Http\Request;

class N8nController extends Controller
{
    public function handleResult(Request $request)
    {
        $data = $request->validate([
            'event_id' => ['nullable', 'uuid'],
            'workflow_run_id' => ['nullable', 'uuid'],
            'workflow_name' => ['nullable', 'string'],
            'status' => ['required', 'string', 'in:completed,failed'],
            'output_payload' => ['nullable', 'array'],
            'error_message' => ['nullable', 'string'],
        ]);

        $workflowRun = null;
        $workflowRunId = $data['workflow_run_id'] ?? null;

        if (!$workflowRunId && empty($data['workflow_name']) && empty($data['event_id'])) {
            return response()->json(['message' => 'Missing identifiers.'], 422);
        }

        if ($workflowRunId) {
            $workflowRun = WorkflowRun::find($workflowRunId);
        }

        if (!$workflowRun) {
            $query = WorkflowRun::query();

            if (!empty($data['workflow_name'])) {
                $query->where('workflow_name', $data['workflow_name']);
            }

            if (!empty($data['event_id'])) {
                $query->where('agent_event_id', $data['event_id']);
            }

            $workflowRun = $query->latest()->first();
        }

        if (!$workflowRun) {
            return response()->json(['message' => 'Workflow run not found.'], 404);
        }

        $updates = [
            'status' => $data['status'],
            'finished_at' => now(),
        ];

        if (array_key_exists('output_payload', $data)) {
            $updates['output_payload'] = $data['output_payload'];
        }

        if ($data['status'] === 'failed') {
            $updates['error_message'] = $data['error_message'] ?? 'Workflow failed.';
        } else {
            $updates['error_message'] = null;
        }

        $workflowRun->update($updates);

        if (!empty($data['event_id'])) {
            $event = AgentEvent::find($data['event_id']);

            if ($event) {
                $existing = $event->result;
                $existing = is_array($existing) ? $existing : [];
                $existing['workflow_result'] = [
                    'workflow_run_id' => $workflowRun->id,
                    'workflow_name' => $workflowRun->workflow_name,
                    'status' => $workflowRun->status,
                    'output_payload' => $workflowRun->output_payload,
                    'error_message' => $workflowRun->error_message,
                ];

                $event->update([
                    'result' => $existing,
                ]);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
