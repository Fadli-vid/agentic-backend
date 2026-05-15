<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'remind_at' => 'datetime',
    ];
}
