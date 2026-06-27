<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reminder — a scheduled reminder created by the Kobi agent.
 *
 * @property string      $id
 * @property string|null $agent_event_id
 * @property string      $title
 * @property string|null $description
 * @property \Carbon\Carbon|null $remind_at
 * @property string      $channel
 * @property string      $status
 * @property array|null  $payload
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Reminder extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'remind_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    //  Relationships (backed by real FK: agent_event_id)
    // -------------------------------------------------------------------------

    /**
     * The agent event that created this reminder.
     */
    public function agentEvent(): BelongsTo
    {
        return $this->belongsTo(AgentEvent::class, 'agent_event_id');
    }
}
