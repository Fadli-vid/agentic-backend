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
    protected $guarded = [];

    protected $casts = [
        'is_completed' => 'boolean',
    ];

    // TODO: Add relationship to User when multi-user support is introduced.
    // TODO: Add relationship to AgentEvent when task_event_id FK is added.
}
