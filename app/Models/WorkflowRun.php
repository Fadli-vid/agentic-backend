<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

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
}
