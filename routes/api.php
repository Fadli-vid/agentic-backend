<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;
use App\Models\Task;
use App\Models\Expense;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/telegram-webhook', [TelegramController::class, 'handleWebhook']);

Route::get('/tasks', function () {
    return Task::latest()->get(); // Mengambil semua tugas dari yang terbaru
});

Route::get('/expenses', function () {
    return Expense::latest()->get(); // Mengambil semua pengeluaran dari yang terbaru
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