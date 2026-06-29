<?php

namespace App\DTOs;

use App\Models\Task;
use Illuminate\Support\Collection;

readonly class TaskResolutionResult
{
    public function __construct(
        public ?Task $resolvedTask,
        public Collection $candidateMatches,
        public bool $isAmbiguous,
        public int $confidenceScore
    ) {}
}
