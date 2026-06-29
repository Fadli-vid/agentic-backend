<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private \App\Services\TaskService $taskService)
    {
    }

    public function index(\Illuminate\Http\Request $request)
    {
        $filters = $request->only(['status', 'priority', 'search']);
        $sort = $request->input('sort', 'created_at');
        $direction = $request->input('direction', 'desc');
        $perPage = (int) $request->input('per_page', 15);

        $tasks = $this->taskService->getTasks($filters, $sort, $direction, $perPage);

        return response()->json([
            'success' => true,
            'message' => 'Tasks retrieved successfully',
            'data' => \App\Http\Resources\TaskResource::collection($tasks)->response()->getData(true),
        ]);
    }

    public function store(\App\Http\Requests\StoreTaskRequest $request)
    {
        try {
            $data = \App\DTOs\TaskData::fromArray($request->validated());
            $task = $this->taskService->createTask($data);

            return response()->json([
                'success' => true,
                'message' => 'Task created successfully',
                'data' => new \App\Http\Resources\TaskResource($task),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create task',
                'errors' => ['server' => [$e->getMessage()]],
            ], 500);
        }
    }

    public function update(\App\Http\Requests\UpdateTaskRequest $request, \App\Models\Task $task)
    {
        try {
            $data = \App\DTOs\TaskData::fromArray($request->validated());
            $updatedTask = $this->taskService->updateTask($task, $data);

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully',
                'data' => new \App\Http\Resources\TaskResource($updatedTask),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task',
                'errors' => ['server' => [$e->getMessage()]],
            ], 500);
        }
    }

    public function destroy(\App\Models\Task $task)
    {
        try {
            $this->taskService->deleteTask($task);

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully',
                'data' => null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete task',
                'errors' => ['server' => [$e->getMessage()]],
            ], 500);
        }
    }

    public function statistics()
    {
        $stats = $this->taskService->getStatistics();

        return response()->json([
            'success' => true,
            'message' => 'Task statistics retrieved successfully',
            'data' => $stats,
        ]);
    }
}
