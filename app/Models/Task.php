<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Task — a simple to-do item.
 *
 * @property int    $id
 * @property string $name
 * @property bool   $is_completed
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Task extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';

    protected $fillable = [
        'name',
        'description',
        'status',
        'priority',
        'deadline_at',
        'started_at',
        'completed_at',
        'is_completed', // Deprecated but kept for backward compatibility
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'deadline_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', self::PRIORITY_HIGH);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', '!=', self::STATUS_COMPLETED)
                     ->whereNotNull('deadline_at')
                     ->where('deadline_at', '<', now());
    }

    // TODO: Add relationship to User when multi-user support is introduced.
    // TODO: Add relationship to AgentEvent when task_event_id FK is added.
}
