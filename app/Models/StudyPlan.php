<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * StudyPlan — a structured study plan with schedule and progress tracking.
 *
 * The `schedule` JSON column stores a weekly timetable (e.g. {"monday": ["Math 09:00-10:00"]}).
 *
 * TODO: When calendar functionality is introduced, recurring schedules should be
 *       moved into a dedicated `study_sessions` table with proper datetime fields,
 *       recurrence rules, and a foreign key back to `study_plans`.
 *
 * @property int         $id
 * @property string      $subject
 * @property string|null $description
 * @property array|null  $schedule         structured weekly schedule as JSON
 * @property string      $status           active, completed, paused
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $target_date
 * @property string|null $notes
 * @property array|null  $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class StudyPlan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'schedule' => 'array',
        'started_at' => 'datetime',
        'target_date' => 'date',
        'metadata' => 'array',
    ];

    // TODO: Add hasMany to StudySession when study_sessions table is introduced.
    // TODO: Add relationship to User when multi-user support is introduced.
}
