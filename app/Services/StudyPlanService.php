<?php

namespace App\Services;

use App\Models\StudyPlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * StudyPlanService — domain service for study plan management.
 *
 * Pure business logic. Knows nothing about Telegram, Gemini, or controllers.
 * Communicates only with the StudyPlan Eloquent model.
 *
 * The `schedule` JSON column stores a weekly timetable, e.g.:
 * {"monday": ["Math 09:00-10:00", "Physics 10:00-11:00"], "tuesday": [...]}
 *
 * TODO: When calendar functionality is introduced, recurring schedules should
 *       be moved into a dedicated `study_sessions` table.
 * TODO: Used by ActionRouter — create/update study plans from AI intents.
 * TODO: Used by ContextManager — load today's study schedule for proactive nudges.
 * TODO: Used by N8N — trigger study session reminders.
 */
class StudyPlanService
{
    /**
     * Create a new study plan.
     *
     * @param  array{subject: string, description?: string, schedule?: array, target_date?: string, notes?: string, metadata?: array} $data
     * @return array{ok: bool, message: string, data: array}
     */
    public function createStudyPlan(array $data): array
    {
        try {
            $plan = StudyPlan::create([
                'subject' => trim((string) ($data['subject'] ?? '')),
                'description' => trim((string) ($data['description'] ?? '')) ?: null,
                'schedule' => $data['schedule'] ?? null,
                'status' => 'active',
                'started_at' => now(),
                'target_date' => $data['target_date'] ?? null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            Log::info('Study plan created.', ['study_plan_id' => $plan->id]);

            return $this->success('Study plan created.', ['study_plan' => $plan->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create study plan.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to create study plan.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Update a study plan's details.
     *
     * @param  int   $id
     * @param  array $data  Partial update fields
     * @return array{ok: bool, message: string, data: array}
     */
    public function updateStudyPlan(int $id, array $data): array
    {
        try {
            $plan = StudyPlan::find($id);

            if (!$plan) {
                return $this->failure('Study plan not found.', ['id' => $id]);
            }

            $plan->update($data);

            return $this->success('Study plan updated.', ['study_plan' => $plan->fresh()->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to update study plan.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to update study plan.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Get today's study schedule from all active plans.
     *
     * Reads the `schedule` JSON and filters for today's day name.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function todaySchedule(): array
    {
        $dayName = strtolower(now()->format('l')); // monday, tuesday, etc.

        $plans = StudyPlan::where('status', 'active')
            ->whereNotNull('schedule')
            ->get();

        $todayItems = [];

        foreach ($plans as $plan) {
            $schedule = $plan->schedule;

            if (!is_array($schedule) || empty($schedule[$dayName])) {
                continue;
            }

            $todayItems[] = [
                'study_plan_id' => $plan->id,
                'subject' => $plan->subject,
                'items' => $schedule[$dayName],
            ];
        }

        return $this->success('Today schedule retrieved.', [
            'day' => $dayName,
            'schedules' => $todayItems,
        ]);
    }

    /**
     * Get the full weekly schedule from all active plans.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function weeklySchedule(): array
    {
        $plans = StudyPlan::where('status', 'active')
            ->whereNotNull('schedule')
            ->get();

        $weekly = [];

        foreach ($plans as $plan) {
            $weekly[] = [
                'study_plan_id' => $plan->id,
                'subject' => $plan->subject,
                'schedule' => $plan->schedule,
            ];
        }

        return $this->success('Weekly schedule retrieved.', ['schedules' => $weekly]);
    }

    /**
     * Get all active study plans.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function getActivePlans(): array
    {
        $plans = StudyPlan::where('status', 'active')
            ->orderBy('subject')
            ->get();

        return $this->success('Active plans retrieved.', ['study_plans' => $plans->toArray()]);
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
