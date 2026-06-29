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
            $task->name = $data->name;
            if ($data->description !== null) {
                $task->description = $data->description;
            }
            if ($data->deadline_at !== null) {
                $task->deadline_at = $data->deadline_at;
            }
            if ($data->priority !== null) {
                $task->priority = $data->priority;
            }

            if ($data->status !== null && $data->status !== $task->status) {
                $this->applyTimestampLogic($task, $data->status, $task->status);
                $task->status = $data->status;
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
