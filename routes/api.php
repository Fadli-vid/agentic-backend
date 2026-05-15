<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\N8nController;
use App\Http\Controllers\TelegramController;
use App\Models\AgentEvent;
use App\Models\Automation;
use App\Models\Task;
use App\Models\Expense;
use App\Models\Reminder;
use App\Models\WorkflowRun;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/telegram-webhook', [TelegramController::class, 'handleWebhook']);

Route::post('/internal/n8n-result', [N8nController::class, 'handleResult'])
    ->middleware('n8n.key');

Route::middleware('kobi.key')->group(function () {
    Route::get('/tasks', function () {
        return Task::latest()->get(); // Mengambil semua tugas dari yang terbaru
    });

    Route::get('/expenses', function () {
        return Expense::latest()->get(); // Mengambil semua pengeluaran dari yang terbaru
    });

    Route::get('/agent-events', function () {
        return AgentEvent::latest()->get();
    });

    Route::get('/workflow-runs', function () {
        return WorkflowRun::latest()->get();
    });

    Route::get('/reminders', function () {
        return Reminder::latest()->get();
    });

    Route::get('/automations', function () {
        return Automation::latest()->get();
    });

    Route::patch('/automations/{automation}', function (Request $request, Automation $automation) {
        $data = $request->validate([
            'name' => ['sometimes', 'string'],
            'description' => ['nullable', 'string'],
            'is_enabled' => ['sometimes', 'boolean'],
            'trigger_type' => ['nullable', 'string'],
            'config' => ['nullable', 'array'],
        ]);

        $automation->update($data);

        return response()->json($automation);
    });

    Route::match(['put', 'patch'], '/tasks/{task}', function (Request $request, Task $task) {
        $data = $request->validate([
            'is_completed' => ['required', 'boolean'],
        ]);

        $task->update($data);

        return response()->json($task);
    });

    Route::delete('/tasks/{task}', function (Task $task) {
        $task->delete();

        return response()->json(['deleted' => true]);
    });

    Route::delete('/expenses/{expense}', function (Expense $expense) {
        $expense->delete();

        return response()->json(['deleted' => true]);
    });
});