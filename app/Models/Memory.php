<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Memory — a piece of knowledge stored by the AI agent about the user.
 *
 * Supports multiple memories per category without key conflicts.
 * Designed for future AI context retrieval via ContextManager.
 *
 * @property int         $id
 * @property string|null $category        e.g. preference, fact, context, note
 * @property string      $title           short label for the memory
 * @property string      $content         the actual memory content
 * @property string|null $source          origin channel (telegram, web, system, ai)
 * @property int         $importance      0-10 scale, default 5
 * @property array|null  $metadata        extra structured data
 * @property \Carbon\Carbon|null $last_accessed_at  for relevance scoring
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Memory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'importance' => 'integer',
        'metadata' => 'array',
        'last_accessed_at' => 'datetime',
    ];

    // TODO: Add relationship to User when multi-user support is introduced.
}
