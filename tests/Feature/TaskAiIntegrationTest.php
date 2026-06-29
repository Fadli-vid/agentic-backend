<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Services\AgentActionService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private AgentActionService $agentActionService;
    private TaskService $taskService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taskService = app(TaskService::class);
        $this->agentActionService = app(AgentActionService::class);
    }

    public function test_resolve_task_exact_match()
    {
        $task = Task::create(['name' => 'Laporan AI', 'status' => 'pending', 'priority' => 'medium']);
        $resolution = $this->taskService->resolveTask('Laporan AI');
        $this->assertNotNull($resolution->resolvedTask);
        $this->assertEquals($task->id, $resolution->resolvedTask->id);
        $this->assertEquals(100, $resolution->confidenceScore);
    }

    public function test_resolve_task_case_insensitive_match()
    {
        $task = Task::create(['name' => 'Laporan AI', 'status' => 'pending', 'priority' => 'medium']);
        $resolution = $this->taskService->resolveTask('laporan ai');
        $this->assertNotNull($resolution->resolvedTask);
        $this->assertEquals($task->id, $resolution->resolvedTask->id);
        $this->assertEquals(95, $resolution->confidenceScore);
    }

    public function test_resolve_task_ambiguous_match()
    {
        Task::create(['name' => 'Laporan AI Bagian 1', 'status' => 'pending', 'priority' => 'medium']);
        Task::create(['name' => 'Laporan AI Bagian 2', 'status' => 'pending', 'priority' => 'medium']);

        $resolution = $this->taskService->resolveTask('Laporan AI');
        $this->assertNull($resolution->resolvedTask);
        $this->assertTrue($resolution->isAmbiguous);
        $this->assertEquals(2, $resolution->candidateMatches->count());
    }

    public function test_handle_update_task_multiple_fields()
    {
        $task = Task::create([
            'name' => 'Tugas Awal',
            'description' => 'Desc lama',
            'status' => 'pending',
            'priority' => 'low',
        ]);

        $aiData = [
            'action' => 'update_task',
            'ok' => true,
            'reply' => 'Done',
            'data' => [
                'target_task' => 'Tugas Awal',
                'name' => 'Tugas Baru',
                'description' => 'Desc baru',
                'status' => 'completed',
                'priority' => 'high',
                'deadline_at' => '2026-12-31'
            ]
        ];

        $response = $this->agentActionService->execute($aiData);
        $this->assertEquals('completed', $response['error_type'] ?? 'completed');

        $task->refresh();
        $this->assertEquals('Tugas Baru', $task->name);
        $this->assertEquals('Desc baru', $task->description);
        $this->assertEquals('completed', $task->status);
        $this->assertEquals('high', $task->priority);
        $this->assertEquals('2026-12-31 00:00:00', $task->deadline_at);
        $this->assertNotNull($task->completed_at);
    }

    public function test_handle_update_task_clearing_null_fields()
    {
        $task = Task::create([
            'name' => 'Tugas Bersih',
            'description' => 'Hapus ini',
            'status' => 'completed',
            'priority' => 'medium',
        ]);

        $task->completed_at = now();
        $task->save();

        $aiData = [
            'action' => 'update_task',
            'ok' => true,
            'data' => [
                'target_task' => 'Tugas Bersih',
                'description' => null, // Explicitly requesting null
                'status' => 'pending' // Should auto-clear completed_at
            ]
        ];

        $response = $this->agentActionService->execute($aiData);
        $this->assertEquals('completed', $response['error_type'] ?? 'completed');

        $task->refresh();
        $this->assertNull($task->description);
        $this->assertEquals('pending', $task->status);
        $this->assertNull($task->completed_at);
    }

    public function test_handle_delete_task()
    {
        $task = Task::create(['name' => 'Hapus Saya', 'status' => 'pending', 'priority' => 'medium']);

        $aiData = [
            'action' => 'delete_task',
            'ok' => true,
            'data' => ['target_task' => 'Hapus Saya']
        ];

        $response = $this->agentActionService->execute($aiData);
        $this->assertEquals('completed', $response['error_type'] ?? 'completed');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }
}
