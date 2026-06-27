<?php

namespace App\Services;

use App\Models\Habit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * HabitService — domain service for habit tracking and streak management.
 *
 * Pure business logic. Knows nothing about Telegram, Gemini, or controllers.
 * Communicates only with the Habit Eloquent model.
 *
 * TODO: Used by ActionRouter — create/complete habits from AI-parsed user intents.
 * TODO: Used by ContextManager — load today's habit status for proactive suggestions.
 * TODO: Used by N8N — trigger habit reminders and streak notifications.
 */
class HabitService
{
    /**
     * Create a new habit.
     *
     * @param  array{name: string, description?: string, frequency?: string, target_count?: int, metadata?: array} $data
     * @return array{ok: bool, message: string, data: array}
     */
    public function createHabit(array $data): array
    {
        try {
            $habit = Habit::create([
                'name' => trim((string) ($data['name'] ?? '')),
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'frequency' => $data['frequency'] ?? 'daily',
                'target_count' => (int) ($data['target_count'] ?? 1),
                'current_streak' => 0,
                'longest_streak' => 0,
                'is_active' => true,
                'metadata' => $data['metadata'] ?? null,
            ]);

            Log::info('Habit created.', ['habit_id' => $habit->id]);

            return $this->success('Habit created.', ['habit' => $habit->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create habit.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to create habit.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Mark a habit as completed for today.
     *
     * Updates current_streak and longest_streak accordingly.
     *
     * @param  int $id
     * @return array{ok: bool, message: string, data: array}
     */
    public function completeHabit(int $id): array
    {
        try {
            $habit = Habit::find($id);

            if (!$habit) {
                return $this->failure('Habit not found.', ['id' => $id]);
            }

            $wasCompletedToday = $this->wasCompletedToday($habit);

            if ($wasCompletedToday) {
                return $this->success('Habit already completed today.', ['habit' => $habit->toArray()]);
            }

            $newStreak = $this->calculateNewStreak($habit);
            $longestStreak = max($habit->longest_streak, $newStreak);

            $habit->update([
                'current_streak' => $newStreak,
                'longest_streak' => $longestStreak,
                'last_completed_at' => now(),
            ]);

            Log::info('Habit completed.', [
                'habit_id' => $habit->id,
                'current_streak' => $newStreak,
            ]);

            return $this->success('Habit completed.', ['habit' => $habit->fresh()->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to complete habit.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to complete habit.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Skip a habit for today, resetting the current streak.
     *
     * @param  int $id
     * @return array{ok: bool, message: string, data: array}
     */
    public function skipHabit(int $id): array
    {
        try {
            $habit = Habit::find($id);

            if (!$habit) {
                return $this->failure('Habit not found.', ['id' => $id]);
            }

            $habit->update([
                'current_streak' => 0,
            ]);

            Log::info('Habit skipped, streak reset.', ['habit_id' => $habit->id]);

            return $this->success('Habit skipped.', ['habit' => $habit->fresh()->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to skip habit.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to skip habit.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Get all active habits that are due today.
     *
     * For daily habits, all active ones are returned.
     * For weekly/monthly, we check if they haven't been completed in the current period.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function getTodayHabits(): array
    {
        $habits = Habit::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Habit $habit) => $this->isDueToday($habit))
            ->values();

        return $this->success('Today habits retrieved.', ['habits' => $habits->toArray()]);
    }

    /**
     * Get the current streak for a specific habit.
     *
     * @param  int $id
     * @return array{ok: bool, message: string, data: array}
     */
    public function calculateCurrentStreak(int $id): array
    {
        $habit = Habit::find($id);

        if (!$habit) {
            return $this->failure('Habit not found.', ['id' => $id]);
        }

        return $this->success('Current streak retrieved.', [
            'habit_id' => $habit->id,
            'current_streak' => $habit->current_streak,
        ]);
    }

    /**
     * Get the longest streak for a specific habit.
     *
     * @param  int $id
     * @return array{ok: bool, message: string, data: array}
     */
    public function calculateLongestStreak(int $id): array
    {
        $habit = Habit::find($id);

        if (!$habit) {
            return $this->failure('Habit not found.', ['id' => $id]);
        }

        return $this->success('Longest streak retrieved.', [
            'habit_id' => $habit->id,
            'longest_streak' => $habit->longest_streak,
        ]);
    }

    /**
     * Get all active habits.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function getActiveHabits(): array
    {
        $habits = Habit::where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->success('Active habits retrieved.', ['habits' => $habits->toArray()]);
    }

    // -------------------------------------------------------------------------
    //  Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check if the habit was already completed today.
     */
    private function wasCompletedToday(Habit $habit): bool
    {
        if (!$habit->last_completed_at) {
            return false;
        }

        return $habit->last_completed_at->isToday();
    }

    /**
     * Calculate the new streak value after a completion.
     *
     * If last completion was yesterday (for daily), streak increments.
     * Otherwise, streak resets to 1.
     */
    private function calculateNewStreak(Habit $habit): int
    {
        if (!$habit->last_completed_at) {
            return 1;
        }

        $isConsecutive = match ($habit->frequency) {
            'daily' => $habit->last_completed_at->isYesterday(),
            'weekly' => $habit->last_completed_at->diffInWeeks(now()) <= 1,
            'monthly' => $habit->last_completed_at->diffInMonths(now()) <= 1,
            default => false,
        };

        return $isConsecutive ? $habit->current_streak + 1 : 1;
    }

    /**
     * Check if a habit is due today based on its frequency.
     */
    private function isDueToday(Habit $habit): bool
    {
        if (!$habit->last_completed_at) {
            return true;
        }

        return match ($habit->frequency) {
            'daily' => !$habit->last_completed_at->isToday(),
            'weekly' => !$habit->last_completed_at->isSameWeek(now()),
            'monthly' => !$habit->last_completed_at->isSameMonth(now()),
            default => true,
        };
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
