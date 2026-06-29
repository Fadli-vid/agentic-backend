<?php

namespace Tests\Unit;

use App\DTOs\TaskData;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskServiceTest extends TestCase
{
    use RefreshDatabase;

    private TaskService $taskService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taskService = app(TaskService::class);
    }

    public function test_task_resolution_exact_match()
    {
        Task::create(['name' => 'Buy Milk']);
        Task::create(['name' => 'Buy Bread']);

        $result = $this->taskService->resolveTask('Buy Milk');
        
        $this->assertFalse($result->isAmbiguous);
        $this->assertNotNull($result->resolvedTask);
        $this->assertEquals('Buy Milk', $result->resolvedTask->name);
        $this->assertEquals(100, $result->confidenceScore);
    }

    public function test_task_resolution_ambiguous_partial_match()
    {
        Task::create(['name' => 'Buy Milk']);
        Task::create(['name' => 'Buy Chocolate Milk']);

        $result = $this->taskService->resolveTask('Milk');
        
        $this->assertTrue($result->isAmbiguous);
        $this->assertNull($result->resolvedTask);
        $this->assertCount(2, $result->candidateMatches);
        $this->assertEquals(80, $result->confidenceScore); // ILIKE match
    }

    public function test_timestamp_logic_on_create()
    {
        $data = TaskData::fromArray([
            'name' => 'Test Task',
            'status' => 'in_progress'
        ]);

        $task = $this->taskService->createTask($data);

        $this->assertEquals('in_progress', $task->status);
        $this->assertNotNull($task->started_at);
        $this->assertNull($task->completed_at);
    }

    public function test_timestamp_logic_on_status_change()
    {
        $task = Task::create(['name' => 'Test', 'status' => 'pending']);
        $this->assertNull($task->started_at);

        // Update to in progress
        $task = $this->taskService->updateStatus($task, 'in_progress');
        $this->assertNotNull($task->started_at);
        $this->assertNull($task->completed_at);

        $startedAt = $task->started_at;

        // Update to completed
        $task = $this->taskService->updateStatus($task, 'completed');
        $this->assertNotNull($task->completed_at);
        $this->assertEquals($startedAt, $task->started_at); // Should not overwrite

        // Revert to pending
        $task = $this->taskService->updateStatus($task, 'pending');
        $this->assertNull($task->completed_at);
        $this->assertNotNull($task->started_at); // Started at is retained
    }

    public function test_multi_field_update()
    {
        $task = Task::create(['name' => 'Old', 'status' => 'pending', 'priority' => 'low']);

        $data = TaskData::fromArray([
            'name' => 'New Name',
            'status' => 'completed',
            'priority' => 'high'
        ]);

        $updatedTask = $this->taskService->updateTask($task, $data);

        $this->assertEquals('New Name', $updatedTask->name);
        $this->assertEquals('completed', $updatedTask->status);
        $this->assertEquals('high', $updatedTask->priority);
        $this->assertNotNull($updatedTask->completed_at);
    }
}
