<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Habit — a recurring activity tracked for streak and consistency.
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $frequency          daily, weekly, monthly
 * @property int         $target_count       times per period
 * @property int         $current_streak     consecutive completions
 * @property int         $longest_streak     all-time best streak
 * @property \Carbon\Carbon|null $last_completed_at
 * @property bool        $is_active
 * @property array|null  $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Habit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'target_count' => 'integer',
        'current_streak' => 'integer',
        'longest_streak' => 'integer',
        'last_completed_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    // TODO: Add hasMany to HabitLog when habit_logs table is introduced for daily tracking.
    // TODO: Add relationship to User when multi-user support is introduced.
}
