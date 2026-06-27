<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Expense;
use Illuminate\Support\Facades\Log;

/**
 * BudgetService — domain service for budget management and spending analysis.
 *
 * Pure business logic. Knows nothing about Telegram, Gemini, or controllers.
 * Communicates with Budget and Expense Eloquent models.
 *
 * This service does NOT create or own Expense records.
 * Expense creation is the responsibility of the Expense domain (AgentActionService).
 * BudgetService only reads Expense data for budget calculations.
 *
 * TODO: Used by ActionRouter — create/update budgets from AI intents.
 * TODO: Used by ContextManager — load budget status for proactive spending alerts.
 * TODO: Used by N8N — trigger budget overspend notifications.
 */
class BudgetService
{
    /**
     * Create a new budget.
     *
     * @param  array{name: string, amount: float, period?: string, category?: string, start_date?: string, end_date?: string, metadata?: array} $data
     * @return array{ok: bool, message: string, data: array}
     */
    public function createBudget(array $data): array
    {
        try {
            $budget = Budget::create([
                'name' => trim((string) ($data['name'] ?? '')),
                'amount' => (float) ($data['amount'] ?? 0),
                'period' => $data['period'] ?? 'monthly',
                'category' => trim((string) ($data['category'] ?? '')) ?: null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'is_active' => true,
                'metadata' => $data['metadata'] ?? null,
            ]);

            Log::info('Budget created.', ['budget_id' => $budget->id]);

            return $this->success('Budget created.', ['budget' => $budget->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create budget.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to create budget.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Update a budget's details.
     *
     * @param  int   $id
     * @param  array $data  Partial update fields
     * @return array{ok: bool, message: string, data: array}
     */
    public function updateBudget(int $id, array $data): array
    {
        try {
            $budget = Budget::find($id);

            if (!$budget) {
                return $this->failure('Budget not found.', ['id' => $id]);
            }

            $budget->update($data);

            return $this->success('Budget updated.', ['budget' => $budget->fresh()->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to update budget.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to update budget.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Calculate remaining budget for a specific budget.
     *
     * Computes: budget.amount - sum(expenses in the budget's date range).
     *
     * @param  int $budgetId
     * @return array{ok: bool, message: string, data: array}
     */
    public function remainingBudget(int $budgetId): array
    {
        $budget = Budget::find($budgetId);

        if (!$budget) {
            return $this->failure('Budget not found.', ['id' => $budgetId]);
        }

        $spent = $this->calculateSpentForBudget($budget);
        $remaining = (float) $budget->amount - $spent;

        return $this->success('Remaining budget calculated.', [
            'budget_id' => $budget->id,
            'budget_amount' => (float) $budget->amount,
            'spent' => $spent,
            'remaining' => $remaining,
        ]);
    }

    /**
     * Get a monthly summary of expenses grouped by description.
     *
     * Since expenses don't have a category column yet, we group by description.
     *
     * @param  int $year
     * @param  int $month
     * @return array{ok: bool, message: string, data: array}
     */
    public function monthlySummary(int $year, int $month): array
    {
        $expenses = Expense::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->get();

        $total = $expenses->sum('amount');

        $grouped = $expenses->groupBy('description')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'total' => $group->sum('amount'),
            ])
            ->toArray();

        return $this->success('Monthly summary retrieved.', [
            'year' => $year,
            'month' => $month,
            'total' => $total,
            'breakdown' => $grouped,
        ]);
    }

    /**
     * Calculate budget progress as a percentage of spent vs total.
     *
     * @param  int $budgetId
     * @return array{ok: bool, message: string, data: array}
     */
    public function budgetProgress(int $budgetId): array
    {
        $budget = Budget::find($budgetId);

        if (!$budget) {
            return $this->failure('Budget not found.', ['id' => $budgetId]);
        }

        $spent = $this->calculateSpentForBudget($budget);
        $progress = (float) $budget->amount > 0
            ? min(round(($spent / (float) $budget->amount) * 100, 2), 100.0)
            : 0.0;

        return $this->success('Budget progress calculated.', [
            'budget_id' => $budget->id,
            'spent' => $spent,
            'budget_amount' => (float) $budget->amount,
            'progress' => $progress,
        ]);
    }

    /**
     * Get all active budgets.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function getActiveBudgets(): array
    {
        $budgets = Budget::where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->success('Active budgets retrieved.', ['budgets' => $budgets->toArray()]);
    }

    // -------------------------------------------------------------------------
    //  Private helpers
    // -------------------------------------------------------------------------

    /**
     * Calculate total expenses within a budget's date range.
     *
     * If no date range is set, falls back to current month.
     * TODO: Improve when budget_id FK is added to expenses table.
     */
    private function calculateSpentForBudget(Budget $budget): float
    {
        $query = Expense::query();

        if ($budget->start_date && $budget->end_date) {
            $query->whereBetween('created_at', [$budget->start_date, $budget->end_date]);
        } else {
            // Default: current month
            $query->whereYear('created_at', now()->year)
                ->whereMonth('created_at', now()->month);
        }

        return (float) $query->sum('amount');
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
