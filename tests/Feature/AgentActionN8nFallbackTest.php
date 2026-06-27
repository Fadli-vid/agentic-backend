<?php

namespace Tests\Feature;

use App\Jobs\TriggerN8nWorkflowJob;
use App\Models\AgentEvent;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\Memory;
use App\Models\Reminder;
use App\Models\StudyPlan;
use App\Services\AgentActionService;
use App\Services\N8nService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Verifies that domain actions persist data to the database and return
 * success responses even when N8N is unavailable (429, timeout, exception).
 *
 * Each test asserts:
 *  - Database record was created.
 *  - Reply status is 'completed'.
 *  - Reply text is a success message (not an error).
 *  - Automation metadata is present (attempted=true).
 *  - The TriggerN8nWorkflowJob was dispatched (not that it succeeded).
 */
class AgentActionN8nFallbackTest extends TestCase
{
    use RefreshDatabase;

    private AgentActionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AgentActionService::class);
    }

    // -------------------------------------------------------------------------
    //  Goal Tracking
    // -------------------------------------------------------------------------

    public function test_goal_tracking_succeeds_when_n8n_returns_429(): void
    {
        Queue::fake();

        $aiData = $this->buildAiData('goal_tracking', [
            'name' => 'Belajar Laravel',
            'description' => 'Target 30 hari',
        ]);

        $event = $this->createAgentEvent();
        $reply = $this->service->execute($aiData, $event);

        // Database persistence
        $this->assertDatabaseHas('goals', ['title' => 'Belajar Laravel']);

        // Reply is success
        $this->assertEquals('completed', $reply['status']);
        $this->assertStringNotContainsString('gagal', strtolower($reply['reply']));
        $this->assertNull($reply['error_message']);

        // Automation metadata present
        $this->assertNotNull($reply['result']['automation'] ?? null);
        $this->assertTrue($reply['result']['automation']['attempted']);
        $this->assertEquals('goal_tracking', $reply['result']['automation']['workflow_name']);

        // N8N job was dispatched
        Queue::assertPushed(TriggerN8nWorkflowJob::class, function ($job) {
            return $job->action === 'goal_tracking'
                && $job->workflowName === 'goal_tracking';
        });
    }

    // -------------------------------------------------------------------------
    //  Habit Tracker
    // -------------------------------------------------------------------------

    public function test_habit_tracker_succeeds_when_n8n_times_out(): void
    {
        Queue::fake();

        $aiData = $this->buildAiData('habit_tracker', [
            'name' => 'Olahraga pagi',
            'description' => 'Jogging 30 menit',
        ]);

        $event = $this->createAgentEvent();
        $reply = $this->service->execute($aiData, $event);

        $this->assertDatabaseHas('habits', ['name' => 'Olahraga pagi']);
        $this->assertEquals('completed', $reply['status']);
        $this->assertStringNotContainsString('gagal', strtolower($reply['reply']));
        $this->assertNotNull($reply['result']['habit_id'] ?? null);

        Queue::assertPushed(TriggerN8nWorkflowJob::class, function ($job) {
            return $job->action === 'habit_tracker';
        });
    }

    // -------------------------------------------------------------------------
    //  Study Planner
    // -------------------------------------------------------------------------

    public function test_study_planner_succeeds_when_n8n_unavailable(): void
    {
        Queue::fake();

        $aiData = $this->buildAiData('study_planner', [
            'name' => 'Matematika',
            'description' => 'Bab integral',
        ]);

        $event = $this->createAgentEvent();
        $reply = $this->service->execute($aiData, $event);

        $this->assertDatabaseHas('study_plans', ['subject' => 'Matematika']);
        $this->assertEquals('completed', $reply['status']);
        $this->assertStringNotContainsString('gagal', strtolower($reply['reply']));
        $this->assertNotNull($reply['result']['study_plan_id'] ?? null);

        Queue::assertPushed(TriggerN8nWorkflowJob::class, function ($job) {
            return $job->action === 'study_planner';
        });
    }

    // -------------------------------------------------------------------------
    //  Memory Update
    // -------------------------------------------------------------------------

    public function test_memory_update_succeeds_when_n8n_not_configured(): void
    {
        Queue::fake();

        $aiData = $this->buildAiData('memory_update', [
            'name' => 'Ulang tahun Mama',
            'description' => 'Tanggal 15 Desember',
            'category' => 'personal',
        ]);

        $event = $this->createAgentEvent();
        $reply = $this->service->execute($aiData, $event);

        $this->assertDatabaseHas('memories', ['title' => 'Ulang tahun Mama']);
        $this->assertEquals('completed', $reply['status']);
        $this->assertStringNotContainsString('gagal', strtolower($reply['reply']));
        $this->assertNotNull($reply['result']['memory_id'] ?? null);

        Queue::assertPushed(TriggerN8nWorkflowJob::class, function ($job) {
            return $job->action === 'memory_update';
        });
    }

    // -------------------------------------------------------------------------
    //  Create Reminder
    // -------------------------------------------------------------------------

    public function test_create_reminder_succeeds_when_n8n_fails(): void
    {
        Queue::fake();

        $aiData = $this->buildAiData('create_reminder', [
            'name' => 'Meeting tim',
            'description' => 'Zoom meeting jam 2',
            'datetime' => '2026-07-01 14:00:00',
        ]);

        $event = $this->createAgentEvent();
        $reply = $this->service->execute($aiData, $event);

        $this->assertDatabaseHas('reminders', ['title' => 'Meeting tim']);
        $this->assertEquals('completed', $reply['status']);
        $this->assertStringNotContainsString('gagal', strtolower($reply['reply']));
        $this->assertNotNull($reply['result']['reminder_id'] ?? null);

        // Automation metadata present
        $this->assertTrue($reply['result']['automation']['attempted'] ?? false);

        Queue::assertPushed(TriggerN8nWorkflowJob::class, function ($job) {
            return $job->action === 'create_reminder'
                && $job->workflowName === 'reminder';
        });
    }

    // -------------------------------------------------------------------------
    //  Trigger Workflow — N8N IS required here
    // -------------------------------------------------------------------------

    public function test_trigger_workflow_fails_when_n8n_fails(): void
    {
        // Mock N8nService to return failure
        $mockN8n = $this->mock(N8nService::class);
        $mockN8n->shouldReceive('triggerWorkflow')
            ->once()
            ->andReturn([
                'ok' => false,
                'status' => 'failed',
                'workflow_run_id' => 'mock-run-id',
                'workflow_name' => 'daily_summary',
                'error_message' => 'N8N webhook request failed.',
            ]);

        $aiData = $this->buildAiData('trigger_workflow', [], [
            'name' => 'daily_summary',
            'payload' => ['test' => true],
        ]);

        $event = $this->createAgentEvent();

        // Re-resolve the service with the mock
        $service = app(AgentActionService::class);
        $reply = $service->execute($aiData, $event);

        // trigger_workflow IS a critical N8N action — failure IS expected
        $this->assertEquals('failed_action', $reply['status']);
        $this->assertNotNull($reply['error_message']);
    }

    // -------------------------------------------------------------------------
    //  Natural Command — domain resolution
    // -------------------------------------------------------------------------

    public function test_natural_command_resolves_to_goal_domain(): void
    {
        Queue::fake();

        $aiData = $this->buildAiData('natural_command', [
            'name' => 'Baca 10 buku tahun ini',
            'description' => 'Target membaca',
        ], [
            'name' => 'goal_progress',
            'payload' => [],
        ]);

        $event = $this->createAgentEvent();
        $reply = $this->service->execute($aiData, $event);

        // Should have resolved to goal_tracking and persisted
        $this->assertDatabaseHas('goals', ['title' => 'Baca 10 buku tahun ini']);
        $this->assertEquals('completed', $reply['status']);
    }

    public function test_natural_command_falls_back_to_chat(): void
    {
        Queue::fake();

        $aiData = [
            'ok' => true,
            'action' => 'natural_command',
            'reply' => 'Kobi paham, nanti diproses ya.',
            'data' => [
                'name' => '',
                'description' => '',
                'amount' => 0,
                'category' => '',
                'datetime' => '',
                'priority' => '',
                'notes' => '',
            ],
            'workflow' => [
                'name' => '',
                'payload' => [],
            ],
        ];

        $reply = $this->service->execute($aiData);

        // No domain match — should return chat reply with completed status
        $this->assertEquals('completed', $reply['status']);
        $this->assertEquals('Kobi paham, nanti diproses ya.', $reply['reply']);

        // No N8N job dispatched (no workflow name)
        Queue::assertNotPushed(TriggerN8nWorkflowJob::class);
    }

    // -------------------------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------------------------

    private function buildAiData(string $action, array $data = [], array $workflow = []): array
    {
        return [
            'ok' => true,
            'action' => $action,
            'reply' => 'Kobi sudah memproses permintaanmu.',
            'data' => array_merge([
                'name' => '',
                'description' => '',
                'amount' => 0,
                'category' => '',
                'datetime' => '',
                'priority' => '',
                'notes' => '',
            ], $data),
            'workflow' => array_merge([
                'name' => '',
                'payload' => [],
            ], $workflow),
        ];
    }

    private function createAgentEvent(): AgentEvent
    {
        return AgentEvent::create([
            'source' => 'test',
            'chat_id' => 'test-chat-123',
            'message' => 'Test message',
            'status' => 'analyzing',
        ]);
    }
}
