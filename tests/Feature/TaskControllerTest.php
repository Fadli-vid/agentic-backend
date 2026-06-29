<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\KobiApiKeyMiddleware::class);
    }

    public function test_can_get_all_tasks()
    {
        $this->withoutExceptionHandling();
        Task::create(['name' => 'Task 1']);
        Task::create(['name' => 'Task 2']);
        Task::create(['name' => 'Task 3']);

        $response = $this->getJson('/api/tasks');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'success',
                     'message',
                     'data' => [
                         'data',
                         'meta',
                         'links'
                     ]
                 ]);
        
        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_can_filter_and_sort_tasks()
    {
        Task::create(['name' => 'Alpha Task', 'priority' => 'high', 'status' => 'pending']);
        Task::create(['name' => 'Beta Task', 'priority' => 'low', 'status' => 'completed']);
        Task::create(['name' => 'Charlie', 'priority' => 'high', 'status' => 'in_progress']);

        $response = $this->getJson('/api/tasks?priority=high&sort=name&direction=asc');
        
        $response->assertStatus(200);
        $data = $response->json('data.data');
        
        $this->assertCount(2, $data);
        $this->assertEquals('Alpha Task', $data[0]['name']);
        $this->assertEquals('Charlie', $data[1]['name']);
    }

    public function test_can_create_task()
    {
        $payload = [
            'name' => 'New Test Task',
            'priority' => 'high',
            'status' => 'pending',
        ];

        $response = $this->postJson('/api/tasks', $payload);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.name', 'New Test Task');

        $this->assertDatabaseHas('tasks', ['name' => 'New Test Task']);
    }

    public function test_can_update_task()
    {
        $task = Task::create(['name' => 'Old Name', 'status' => 'pending']);

        $response = $this->patchJson('/api/tasks/' . $task->id, [
            'name' => 'Updated Name',
            'status' => 'in_progress'
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'name' => 'Updated Name',
            'status' => 'in_progress'
        ]);
        
        // Assert timestamp logic from update
        $task->refresh();
        $this->assertNotNull($task->started_at);
    }

    public function test_can_delete_task()
    {
        $task = Task::create(['name' => 'To delete']);

        $response = $this->deleteJson('/api/tasks/' . $task->id);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_can_get_statistics()
    {
        Task::create(['name' => 'T1', 'status' => 'pending']);
        Task::create(['name' => 'T2', 'status' => 'pending']);
        Task::create(['name' => 'T3', 'status' => 'in_progress']);
        Task::create(['name' => 'T4', 'status' => 'completed']);
        Task::create(['name' => 'T5', 'status' => 'completed']);
        Task::create(['name' => 'T6', 'status' => 'completed']);

        $response = $this->getJson('/api/tasks/statistics');

        $response->assertStatus(200)
                 ->assertJsonPath('data.total', 6)
                 ->assertJsonPath('data.pending', 2)
                 ->assertJsonPath('data.in_progress', 1)
                 ->assertJsonPath('data.completed', 3)
                 ->assertJsonPath('data.progress_percentage', 50);
    }
}
