<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Automation extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'config' => 'array',
        'is_enabled' => 'boolean',
    ];
}
