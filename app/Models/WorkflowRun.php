<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WorkflowRun — tracks a single n8n workflow execution.
 *
 * @property string      $id
 * @property string|null $agent_event_id
 * @property string      $workflow_name
 * @property string      $status
 * @property array|null  $input_payload
 * @property array|null  $output_payload
 * @property string|null $error_message
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class WorkflowRun extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'input_payload' => 'array',
        'output_payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    //  Relationships (backed by real FK: agent_event_id)
    // -------------------------------------------------------------------------

    /**
     * The agent event that triggered this workflow run.
     */
    public function agentEvent(): BelongsTo
    {
        return $this->belongsTo(AgentEvent::class, 'agent_event_id');
    }
}
