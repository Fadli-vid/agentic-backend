<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Expense — a recorded financial expenditure.
 *
 * @property int    $id
 * @property int    $amount
 * @property string $description
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Expense extends Model
{
    protected $guarded = [];

    // TODO: Add relationship to Budget when budget_id FK is introduced.
    // TODO: Add category column and relationship for expense categorization.
}
