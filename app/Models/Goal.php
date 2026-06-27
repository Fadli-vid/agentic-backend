<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Goal — a measurable objective with progress tracking.
 *
 * @property int         $id
 * @property string      $title
 * @property string|null $description
 * @property float|null  $target_value      e.g. 10000000 (save 10M)
 * @property float       $current_value     progress toward target
 * @property string|null $unit              rupiah, books, kg, etc.
 * @property string      $status            active, completed, archived
 * @property string      $priority          low, medium, high
 * @property \Carbon\Carbon|null $due_date
 * @property \Carbon\Carbon|null $completed_at
 * @property array|null  $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Goal extends Model
{
    protected $guarded = [];

    protected $casts = [
        'target_value' => 'decimal:2',
        'current_value' => 'decimal:2',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    // TODO: Add hasMany to Milestone when milestones table is introduced.
    // TODO: Add relationship to User when multi-user support is introduced.
}
