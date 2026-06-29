<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\N8nController;
use App\Http\Controllers\TelegramController;
use App\Models\AgentEvent;
use App\Models\Automation;
use App\Models\Task;
use App\Models\Expense;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\Memory;
use App\Models\Reminder;
use App\Models\StudyPlan;
use App\Models\WorkflowRun;
use App\Services\AnalyticsService;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/telegram-webhook', [TelegramController::class, 'handleWebhook']);

Route::post('/internal/n8n-result', [N8nController::class, 'handleResult'])
    ->middleware('n8n.key');

Route::middleware('kobi.key')->group(function () {
    Route::get('/tasks/statistics', [\App\Http\Controllers\TaskController::class, 'statistics']);
    Route::apiResource('tasks', \App\Http\Controllers\TaskController::class)->except(['show']);

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

    Route::get('/habits', function () {
        return Habit::latest()->get();
    });

    Route::get('/goals', function () {
        return Goal::latest()->get();
    });

    Route::get('/memories', function () {
        return Memory::latest()->get();
    });

    Route::get('/study-plans', function () {
        return StudyPlan::latest()->get();
    });

    Route::get('/analytics/dashboard', function (AnalyticsService $analyticsService) {
        $result = $analyticsService->dashboardOverview();
        return response()->json($result['ok'] ? $result['data'] : $result);
    });

    Route::get('/analytics/productivity-score', function (AnalyticsService $analyticsService) {
        $result = $analyticsService->productivityScore();
        return response()->json($result['ok'] ? $result['data'] : $result);
    });

    Route::get('/analytics/weekly-summary', function (AnalyticsService $analyticsService) {
        $result = $analyticsService->weeklySummary();
        return response()->json($result['ok'] ? $result['data'] : $result);
    });

    Route::get('/analytics/monthly-summary', function (AnalyticsService $analyticsService) {
        $result = $analyticsService->monthlySummary();
        return response()->json($result['ok'] ? $result['data'] : $result);
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



    Route::delete('/expenses/{expense}', function (Expense $expense) {
        $expense->delete();

        return response()->json(['deleted' => true]);
    });
});

use App\Services\TelegramService;

Route::get('/test-telegram', function (TelegramService $telegramService) {
    $chatId = request('chat_id');

    if (!$chatId) {
        return response()->json([
            'ok' => false,
            'message' => 'chat_id is required',
        ], 400);
    }

    $sent = $telegramService->sendMessage(
        $chatId,
        'Tes langsung dari Laravel ke Telegram ✅'
    );

    return response()->json([
        'ok' => $sent,
    ]);
});