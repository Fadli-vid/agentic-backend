<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Budget — a spending limit for a given period and/or category.
 *
 * @property int         $id
 * @property string      $name            e.g. "June 2026", "Groceries"
 * @property float       $amount          total budget amount
 * @property string      $period          daily, weekly, monthly, yearly
 * @property string|null $category
 * @property \Carbon\Carbon|null $start_date
 * @property \Carbon\Carbon|null $end_date
 * @property bool        $is_active
 * @property array|null  $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Budget extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    // TODO: Add hasMany to Expense when budget_id FK is added to expenses table.
    // TODO: Add relationship to User when multi-user support is introduced.
}
