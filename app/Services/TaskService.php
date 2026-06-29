<?php

namespace App\Services;

use App\DTOs\TaskData;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class TaskService
{
    /**
     * Get paginated list of tasks with filters and sorting.
     */
    public function getTasks(array $filters = [], string $sort = 'created_at', string $direction = 'desc', int $perPage = 15): LengthAwarePaginator
    {
        $query = Task::query();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'ilike', '%' . $filters['search'] . '%')
                  ->orWhere('description', 'ilike', '%' . $filters['search'] . '%');
            });
        }

        $allowedSorts = ['created_at', 'updated_at', 'deadline_at', 'priority', 'name'];
        if (in_array($sort, $allowedSorts)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        return $query->paginate($perPage);
    }

    /**
     * Resolve a task via staged fuzzy matching.
     */
    public function resolveTask(string $query): \App\DTOs\TaskResolutionResult
    {
        $query = trim($query);
        if ($query === '') {
            return new \App\DTOs\TaskResolutionResult(null, collect(), false, 0);
        }

        // 1. Exact Match (Score 100)
        $exact = Task::where('name', $query)->get();
        if ($exact->count() === 1) {
            return new \App\DTOs\TaskResolutionResult($exact->first(), $exact, false, 100);
        }
        if ($exact->count() > 1) {
            return new \App\DTOs\TaskResolutionResult(null, $exact, true, 100);
        }

        // 2. Case Insensitive (Score 95)
        $caseInsensitive = Task::where('name', 'ilike', $query)->get();
        if ($caseInsensitive->count() === 1) {
            return new \App\DTOs\TaskResolutionResult($caseInsensitive->first(), $caseInsensitive, false, 95);
        }
        if ($caseInsensitive->count() > 1) {
            return new \App\DTOs\TaskResolutionResult(null, $caseInsensitive, true, 95);
        }

        // 3. Trimmed Match (Score 90) - handled by exact and case-insensitive since we trim

        // 4. ILIKE / Partial Match (Score 80)
        $partial = Task::where('name', 'ilike', '%' . $query . '%')->get();
        if ($partial->count() === 1) {
            return new \App\DTOs\TaskResolutionResult($partial->first(), $partial, false, 80);
        }
        if ($partial->count() > 1) {
            return new \App\DTOs\TaskResolutionResult(null, $partial, true, 80);
        }

        // 5. Contains Match using split words (Score 70)
        $words = explode(' ', $query);
        $containsQuery = Task::query();
        foreach ($words as $word) {
            if (trim($word) !== '') {
                $containsQuery->where('name', 'ilike', '%' . trim($word) . '%');
            }
        }
        $contains = $containsQuery->get();
        if ($contains->count() === 1) {
            return new \App\DTOs\TaskResolutionResult($contains->first(), $contains, false, 70);
        }
        if ($contains->count() > 1) {
            return new \App\DTOs\TaskResolutionResult(null, $contains, true, 70);
        }

        return new \App\DTOs\TaskResolutionResult(null, collect(), false, 0);
    }

    /**
     * Create a new task safely.
     */
    public function createTask(TaskData $data): Task
    {
        return DB::transaction(function () use ($data) {
            $task = new Task([
                'name' => $data->name,
                'description' => $data->description,
                'status' => $data->status ?? Task::STATUS_PENDING,
                'priority' => $data->priority ?? Task::PRIORITY_MEDIUM,
                'deadline_at' => $data->deadline_at,
            ]);

            // Handle timestamp assignment for creation status
            $this->applyTimestampLogic($task, $task->status, null);
            
            $task->save();
            return $task;
        });
    }

    /**
     * Update an existing task safely.
     */
    public function updateTask(Task $task, TaskData $data): Task
    {
        return DB::transaction(function () use ($task, $data) {
            if ($data->isProvided('name')) {
                $task->name = $data->name;
            }
            if ($data->isProvided('description')) {
                $task->description = $data->description;
            }
            if ($data->isProvided('deadline_at')) {
                $task->deadline_at = $data->deadline_at;
            }
            if ($data->isProvided('priority')) {
                $task->priority = $data->priority;
            }

            if ($data->isProvided('status') && $data->status !== $task->status) {
                // Determine new status; if null passed explicitly, this is unusual but maybe fallback to pending
                $newStatus = $data->status ?? Task::STATUS_PENDING;
                $this->applyTimestampLogic($task, $newStatus, $task->status);
                $task->status = $newStatus;
            }

            $task->save();
            return $task;
        });
    }

    /**
     * Change only the status of a task.
     */
    public function updateStatus(Task $task, string $newStatus): Task
    {
        return DB::transaction(function () use ($task, $newStatus) {
            if ($task->status !== $newStatus) {
                $this->applyTimestampLogic($task, $newStatus, $task->status);
                $task->status = $newStatus;
                $task->save();
            }
            return $task;
        });
    }

    /**
     * Change only the priority of a task.
     */
    public function updatePriority(Task $task, string $newPriority): Task
    {
        $task->update(['priority' => $newPriority]);
        return $task;
    }

    /**
     * Change only the deadline of a task.
     */
    public function updateDeadline(Task $task, ?string $newDeadline): Task
    {
        $task->update(['deadline_at' => $newDeadline]);
        return $task;
    }

    /**
     * Delete a task.
     */
    public function deleteTask(Task $task): bool
    {
        return DB::transaction(fn() => $task->delete());
    }

    /**
     * Get task statistics.
     */
    public function getStatistics(): array
    {
        $total = Task::count();
        $pending = Task::pending()->count();
        $inProgress = Task::inProgress()->count();
        $completed = Task::completed()->count();

        $progressPercentage = $total > 0 ? (int) round(($completed / $total) * 100) : 0;

        return [
            'total' => $total,
            'pending' => $pending,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'progress_percentage' => $progressPercentage,
        ];
    }

    /**
     * Internal logic for maintaining correct timestamps during status transitions.
     */
    private function applyTimestampLogic(Task $task, string $newStatus, ?string $oldStatus): void
    {
        if ($newStatus === Task::STATUS_COMPLETED) {
            $task->completed_at = now();
            // Optional: if it goes straight to completed, we could set started_at if null
            if (empty($task->started_at)) {
                $task->started_at = now();
            }
        } else {
            // Reverting from completed
            if ($oldStatus === Task::STATUS_COMPLETED) {
                $task->completed_at = null;
            }

            if ($newStatus === Task::STATUS_IN_PROGRESS && empty($task->started_at)) {
                $task->started_at = now();
            }
        }
    }
}
