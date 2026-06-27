<?php

namespace App\Services;

use App\Models\Goal;
use Illuminate\Support\Facades\Log;

/**
 * GoalService — domain service for goal management and progress tracking.
 *
 * Pure business logic. Knows nothing about Telegram, Gemini, or controllers.
 * Communicates only with the Goal Eloquent model.
 *
 * TODO: Used by ActionRouter — create/update goals from AI-parsed user intents.
 * TODO: Used by ContextManager — load active goals for proactive AI suggestions.
 * TODO: Used by N8N — trigger deadline reminders and progress notifications.
 */
class GoalService
{
    /**
     * Create a new goal.
     *
     * @param  array{title: string, description?: string, target_value?: float, unit?: string, priority?: string, due_date?: string, metadata?: array} $data
     * @return array{ok: bool, message: string, data: array}
     */
    public function createGoal(array $data): array
    {
        try {
            $goal = Goal::create([
                'title' => trim((string) ($data['title'] ?? '')),
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'target_value' => isset($data['target_value']) ? (float) $data['target_value'] : null,
                'current_value' => 0,
                'unit' => trim((string) ($data['unit'] ?? '')) ?: null,
                'status' => 'active',
                'priority' => $data['priority'] ?? 'medium',
                'due_date' => $data['due_date'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            Log::info('Goal created.', ['goal_id' => $goal->id]);

            return $this->success('Goal created.', ['goal' => $goal->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create goal.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to create goal.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Update a goal's details or progress.
     *
     * If current_value reaches or exceeds target_value, the goal is auto-completed.
     *
     * @param  int   $id
     * @param  array $data  Partial update fields
     * @return array{ok: bool, message: string, data: array}
     */
    public function updateGoal(int $id, array $data): array
    {
        try {
            $goal = Goal::find($id);

            if (!$goal) {
                return $this->failure('Goal not found.', ['id' => $id]);
            }

            $goal->update($data);

            $this->autoCompleteIfReached($goal->fresh());

            return $this->success('Goal updated.', ['goal' => $goal->fresh()->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to update goal.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to update goal.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Archive a goal (set status to 'archived').
     *
     * @param  int $id
     * @return array{ok: bool, message: string, data: array}
     */
    public function archiveGoal(int $id): array
    {
        try {
            $goal = Goal::find($id);

            if (!$goal) {
                return $this->failure('Goal not found.', ['id' => $id]);
            }

            $goal->update(['status' => 'archived']);

            return $this->success('Goal archived.', ['goal' => $goal->fresh()->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to archive goal.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to archive goal.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Get all active goals.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function getActiveGoals(): array
    {
        $goals = Goal::where('status', 'active')
            ->orderBy('priority')
            ->orderBy('due_date')
            ->get();

        return $this->success('Active goals retrieved.', ['goals' => $goals->toArray()]);
    }

    /**
     * Calculate the progress percentage for a specific goal.
     *
     * Returns 0 if no target_value is set.
     *
     * @param  int $id
     * @return array{ok: bool, message: string, data: array}
     */
    public function calculateProgress(int $id): array
    {
        $goal = Goal::find($id);

        if (!$goal) {
            return $this->failure('Goal not found.', ['id' => $id]);
        }

        $progress = $this->computeProgressPercentage($goal);

        return $this->success('Progress calculated.', [
            'goal_id' => $goal->id,
            'progress' => $progress,
            'current_value' => (float) $goal->current_value,
            'target_value' => $goal->target_value ? (float) $goal->target_value : null,
        ]);
    }

    /**
     * Get goals with upcoming deadlines within the given number of days.
     *
     * @param  int $days   Number of days ahead to look
     * @param  int $limit  Maximum results
     * @return array{ok: bool, message: string, data: array}
     */
    public function getUpcomingDeadlines(int $days = 7, int $limit = 10): array
    {
        $goals = Goal::where('status', 'active')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', now()->addDays($days)->toDateString())
            ->where('due_date', '>=', now()->toDateString())
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        return $this->success('Upcoming deadlines retrieved.', ['goals' => $goals->toArray()]);
    }

    // -------------------------------------------------------------------------
    //  Private helpers
    // -------------------------------------------------------------------------

    /**
     * Calculate progress as a percentage (0-100).
     */
    private function computeProgressPercentage(Goal $goal): float
    {
        if (!$goal->target_value || (float) $goal->target_value <= 0) {
            return 0.0;
        }

        $progress = ((float) $goal->current_value / (float) $goal->target_value) * 100;

        return min(round($progress, 2), 100.0);
    }

    /**
     * Auto-complete a goal if current_value has reached or exceeded target_value.
     */
    private function autoCompleteIfReached(Goal $goal): void
    {
        if (!$goal->target_value) {
            return;
        }

        if ((float) $goal->current_value >= (float) $goal->target_value && $goal->status === 'active') {
            $goal->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('Goal auto-completed.', ['goal_id' => $goal->id]);
        }
    }

    /**
     * @return array{ok: true, message: string, data: array}
     */
    protected function success(string $message, array $data = []): array
    {
        return ['ok' => true, 'message' => $message, 'data' => $data];
    }

    /**
     * @return array{ok: false, message: string, data: array}
     */
    protected function failure(string $message, array $data = []): array
    {
        return ['ok' => false, 'message' => $message, 'data' => $data];
    }
}
