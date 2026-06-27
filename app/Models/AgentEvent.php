<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AgentEvent — tracks every inbound message processed by the Kobi agent.
 *
 * @property string      $id
 * @property string|null $source
 * @property string|null $user_name
 * @property string|null $chat_id
 * @property string|null $message
 * @property string|null $action
 * @property string      $status
 * @property array|null  $payload
 * @property array|null  $result
 * @property string|null $error_message
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AgentEvent extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
    ];

    // -------------------------------------------------------------------------
    //  Relationships (backed by real FK: workflow_runs.agent_event_id)
    // -------------------------------------------------------------------------

    /**
     * Workflow runs triggered by this event.
     */
    public function workflowRuns(): HasMany
    {
        return $this->hasMany(WorkflowRun::class, 'agent_event_id');
    }

    /**
     * Reminders created from this event.
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class, 'agent_event_id');
    }
}
