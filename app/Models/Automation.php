<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Automation — a registered automation rule (e.g. daily summary, budget alert).
 *
 * @property string      $id
 * @property string      $name
 * @property string|null $description
 * @property bool        $is_enabled
 * @property string|null $trigger_type
 * @property array|null  $config
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Automation extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'config' => 'array',
        'is_enabled' => 'boolean',
    ];

    // TODO: Add relationship to WorkflowRun when automation_id FK is added.
}
