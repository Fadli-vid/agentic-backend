<?php

namespace App\Services;

use App\Models\AgentEvent;
use App\Models\Budget;
use App\Models\Expense;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\Memory;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Log;

/**
 * AnalyticsService — dashboard aggregation service.
 *
 * Reads from all domain models to produce summary data for frontend dashboards.
 * Does NOT modify any data — strictly read-only.
 * Does NOT know about Telegram, Gemini, or any transport layer.
 *
 * TODO: Used by future Dashboard API controllers.
 * TODO: Used by N8N — generate weekly/monthly report payloads.
 * TODO: Used by ReplyBuilder — enrich AI replies with productivity context.
 */
class AnalyticsService
{
    /**
     * Get a combined dashboard overview snapshot.
     *
     * Returns counts and highlights from every module for the frontend dashboard.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function dashboardOverview(): array
    {
        try {
            return $this->success('Dashboard overview retrieved.', [
                'tasks' => $this->tasksSummary(),
                'reminders' => $this->remindersSummary(),
                'habits' => $this->habitsSummary(),
                'goals' => $this->goalsSummary(),
                'budgets' => $this->budgetsSummary(),
                'memories' => $this->memoriesSummary(),
                'agent_activity' => $this->agentActivitySummary(),
                'workflows' => $this->workflowsSummary(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to build dashboard overview.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to build dashboard overview.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Calculate a productivity score (0-100) based on recent activity.
     *
     * Weighted scoring:
     * - Tasks completed today:   30%
     * - Habits completed today:  30%
     * - Active goals progress:   20%
     * - Reminders handled:       10%
     * - Memories stored:         10%
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function productivityScore(): array
    {
        try {
            $taskScore = $this->calculateTaskScore();
            $habitScore = $this->calculateHabitScore();
            $goalScore = $this->calculateGoalScore();
            $reminderScore = $this->calculateReminderScore();
            $memoryScore = $this->calculateMemoryScore();

            $total = round(
                ($taskScore * 0.30)
                + ($habitScore * 0.30)
                + ($goalScore * 0.20)
                + ($reminderScore * 0.10)
                + ($memoryScore * 0.10),
                1
            );

            return $this->success('Productivity score calculated.', [
                'score' => $total,
                'breakdown' => [
                    'tasks' => $taskScore,
                    'habits' => $habitScore,
                    'goals' => $goalScore,
                    'reminders' => $reminderScore,
                    'memories' => $memoryScore,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to calculate productivity score.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to calculate productivity score.');
        }
    }

    /**
     * Get a 7-day activity summary.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function weeklySummary(): array
    {
        $since = now()->subDays(7);

        return $this->success('Weekly summary retrieved.', [
            'period' => '7_days',
            'since' => $since->toDateString(),
            'tasks_created' => Task::where('created_at', '>=', $since)->count(),
            'tasks_completed' => Task::where('is_completed', true)->where('updated_at', '>=', $since)->count(),
            'expenses_total' => (float) Expense::where('created_at', '>=', $since)->sum('amount'),
            'expenses_count' => Expense::where('created_at', '>=', $since)->count(),
            'reminders_created' => Reminder::where('created_at', '>=', $since)->count(),
            'memories_stored' => Memory::where('created_at', '>=', $since)->count(),
            'goals_completed' => Goal::where('status', 'completed')->where('completed_at', '>=', $since)->count(),
            'agent_events' => AgentEvent::where('created_at', '>=', $since)->count(),
            'workflows_triggered' => WorkflowRun::where('created_at', '>=', $since)->count(),
        ]);
    }

    /**
     * Get a 30-day activity summary.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function monthlySummary(): array
    {
        $since = now()->subDays(30);

        return $this->success('Monthly summary retrieved.', [
            'period' => '30_days',
            'since' => $since->toDateString(),
            'tasks_created' => Task::where('created_at', '>=', $since)->count(),
            'tasks_completed' => Task::where('is_completed', true)->where('updated_at', '>=', $since)->count(),
            'expenses_total' => (float) Expense::where('created_at', '>=', $since)->sum('amount'),
            'expenses_count' => Expense::where('created_at', '>=', $since)->count(),
            'reminders_created' => Reminder::where('created_at', '>=', $since)->count(),
            'memories_stored' => Memory::where('created_at', '>=', $since)->count(),
            'goals_active' => Goal::where('status', 'active')->count(),
            'goals_completed' => Goal::where('status', 'completed')->where('completed_at', '>=', $since)->count(),
            'habits_active' => Habit::where('is_active', true)->count(),
            'agent_events' => AgentEvent::where('created_at', '>=', $since)->count(),
            'workflows_triggered' => WorkflowRun::where('created_at', '>=', $since)->count(),
        ]);
    }

    // -------------------------------------------------------------------------
    //  Private aggregation helpers
    // -------------------------------------------------------------------------

    private function tasksSummary(): array
    {
        return [
            'total' => Task::count(),
            'completed' => Task::where('is_completed', true)->count(),
            'pending' => Task::where('is_completed', false)->count(),
            'today_created' => Task::whereDate('created_at', today())->count(),
        ];
    }

    private function remindersSummary(): array
    {
        return [
            'total' => Reminder::count(),
            'pending' => Reminder::where('status', 'pending')->count(),
            'today' => Reminder::where('status', 'pending')
                ->whereNotNull('remind_at')
                ->whereDate('remind_at', today())
                ->count(),
        ];
    }

    private function habitsSummary(): array
    {
        $activeHabits = Habit::where('is_active', true)->get();
        $bestStreak = $activeHabits->max('current_streak') ?? 0;

        return [
            'active' => $activeHabits->count(),
            'completed_today' => $activeHabits->filter(fn ($h) => $h->last_completed_at?->isToday())->count(),
            'best_current_streak' => $bestStreak,
        ];
    }

    private function goalsSummary(): array
    {
        return [
            'active' => Goal::where('status', 'active')->count(),
            'completed' => Goal::where('status', 'completed')->count(),
            'upcoming_deadlines' => Goal::where('status', 'active')
                ->whereNotNull('due_date')
                ->where('due_date', '<=', now()->addDays(7)->toDateString())
                ->where('due_date', '>=', now()->toDateString())
                ->count(),
        ];
    }

    private function budgetsSummary(): array
    {
        $activeBudgets = Budget::where('is_active', true)->get();
        $totalBudget = $activeBudgets->sum('amount');
        $monthlySpent = (float) Expense::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->sum('amount');

        return [
            'active' => $activeBudgets->count(),
            'total_budget' => (float) $totalBudget,
            'monthly_spent' => $monthlySpent,
        ];
    }

    private function memoriesSummary(): array
    {
        return [
            'total' => Memory::count(),
            'recent' => Memory::where('created_at', '>=', now()->subDays(7))->count(),
            'most_important' => Memory::orderByDesc('importance')->value('title'),
        ];
    }

    private function agentActivitySummary(): array
    {
        return [
            'today' => AgentEvent::whereDate('created_at', today())->count(),
            'this_week' => AgentEvent::where('created_at', '>=', now()->subDays(7))->count(),
            'latest_status' => AgentEvent::orderByDesc('created_at')->value('status'),
        ];
    }

    private function workflowsSummary(): array
    {
        return [
            'total' => WorkflowRun::count(),
            'completed' => WorkflowRun::where('status', 'completed')->count(),
            'failed' => WorkflowRun::where('status', 'failed')->count(),
            'recent' => WorkflowRun::where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    // -------------------------------------------------------------------------
    //  Productivity scoring helpers
    // -------------------------------------------------------------------------

    /**
     * Task score: ratio of completed tasks in the last 7 days.
     */
    private function calculateTaskScore(): float
    {
        $total = Task::where('created_at', '>=', now()->subDays(7))->count();

        if ($total === 0) {
            return 0.0;
        }

        $completed = Task::where('is_completed', true)
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();

        return min(($completed / $total) * 100, 100.0);
    }

    /**
     * Habit score: ratio of habits completed today.
     */
    private function calculateHabitScore(): float
    {
        $active = Habit::where('is_active', true)->count();

        if ($active === 0) {
            return 0.0;
        }

        $completedToday = Habit::where('is_active', true)
            ->whereNotNull('last_completed_at')
            ->whereDate('last_completed_at', today())
            ->count();

        return min(($completedToday / $active) * 100, 100.0);
    }

    /**
     * Goal score: average progress of all active goals.
     */
    private function calculateGoalScore(): float
    {
        $goals = Goal::where('status', 'active')
            ->whereNotNull('target_value')
            ->where('target_value', '>', 0)
            ->get();

        if ($goals->isEmpty()) {
            return 0.0;
        }

        $totalProgress = $goals->sum(function ($goal) {
            return min(((float) $goal->current_value / (float) $goal->target_value) * 100, 100.0);
        });

        return $totalProgress / $goals->count();
    }

    /**
     * Reminder score: ratio of handled reminders this week.
     */
    private function calculateReminderScore(): float
    {
        $total = Reminder::where('created_at', '>=', now()->subDays(7))->count();

        if ($total === 0) {
            return 0.0;
        }

        $handled = Reminder::where('status', '!=', 'pending')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return min(($handled / $total) * 100, 100.0);
    }

    /**
     * Memory score: capped at 100 based on memory count this week.
     */
    private function calculateMemoryScore(): float
    {
        $count = Memory::where('created_at', '>=', now()->subDays(7))->count();

        // 5 or more memories this week = 100%
        return min($count * 20, 100.0);
    }

    // -------------------------------------------------------------------------
    //  Response helpers
    // -------------------------------------------------------------------------

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
