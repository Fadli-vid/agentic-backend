<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Task;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AgentActionService
{
    public function execute(array $aiData): array
    {
        $action = $aiData['action'] ?? 'chat';

        if (!($aiData['ok'] ?? true)) {
            return $this->reply($aiData['reply'] ?? 'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.');
        }

        Log::info('Kobi action selected.', [
            'action' => $action,
        ]);

        return match ($action) {
            'add_task' => $this->handleAddTask($aiData),
            'add_expense' => $this->handleAddExpense($aiData),
            default => $this->reply($aiData['reply'] ?? 'Kobi siap bantu. Coba jelaskan lagi ya.'),
        };
    }

    public function handleN8nCommand(string $text): ?array
    {
        if (!preg_match('/^\/task(\s|$)/i', trim($text))) {
            return null;
        }

        $taskContent = trim(preg_replace('/^\/task(\s|$)/i', '', $text));

        if ($taskContent === '') {
            return $this->reply('Format /task belum ada isinya. Contoh: /task Follow up invoice.', null);
        }

        $webhookUrl = trim((string) env('N8N_WEBHOOK_URL', ''));

        if ($webhookUrl === '') {
            Log::warning('N8N webhook URL is not configured.');
            return $this->reply('Webhook n8n belum dikonfigurasi.');
        }

        if ($this->looksLikeDoubleUrl($webhookUrl)) {
            Log::warning('N8N webhook URL looks malformed.');
            return $this->reply('URL n8n terlihat tidak valid. Cek konfigurasi dulu ya.');
        }

        $response = Http::timeout(15)
            ->asJson()
            ->post($webhookUrl, [
                'source' => 'Telegram Kobi',
                'user' => 'Rain',
                'task' => $taskContent,
                'timestamp' => now()->toDateTimeString(),
            ]);

        if ($response->successful()) {
            return $this->reply(
                "Siap! Tugas otomatisasi: *{$taskContent}* sudah Kobi lempar ke markas n8n!",
                'Markdown'
            );
        }

        Log::warning('N8N webhook request failed.', [
            'status' => $response->status(),
        ]);

        return $this->reply('Waduh, markas n8n sepertinya sedang sibuk atau tidak bisa dihubungi nih.');
    }

    private function handleAddTask(array $aiData): array
    {
        $name = trim((string) ($aiData['data_name'] ?? ''));

        if ($name === '') {
            Log::warning('Kobi add_task missing name.');
            return $this->reply('Nama tugasnya belum disebut. Coba ulang dengan lebih jelas ya.');
        }

        try {
            Task::create([
                'name' => $name,
                'is_completed' => false,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create task.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->reply('Maaf, Kobi gagal menyimpan tugasnya. Coba lagi ya.');
        }

        return $this->reply($aiData['reply'] ?? 'Oke, tugasnya sudah Kobi catat.');
    }

    private function handleAddExpense(array $aiData): array
    {
        $description = trim((string) ($aiData['data_name'] ?? ''));
        $amountValue = $aiData['data_amount'] ?? null;

        if ($description === '') {
            Log::warning('Kobi add_expense missing description.');
            return $this->reply('Deskripsi pengeluarannya belum ada. Coba ulang ya.');
        }

        if (!is_numeric($amountValue) || (float) $amountValue <= 0) {
            Log::warning('Kobi add_expense invalid amount.');
            return $this->reply('Nominal pengeluarannya belum valid. Pastikan angkanya lebih dari 0.');
        }

        try {
            Expense::create([
                'amount' => (float) $amountValue,
                'description' => $description,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Failed to create expense.', [
                'error' => $exception->getMessage(),
            ]);

            return $this->reply('Maaf, Kobi gagal menyimpan pengeluaran. Coba lagi ya.');
        }

        return $this->reply($aiData['reply'] ?? 'Siap, pengeluarannya sudah Kobi catat.');
    }

    private function looksLikeDoubleUrl(string $url): bool
    {
        $count = substr_count($url, 'http://') + substr_count($url, 'https://');

        return $count > 1;
    }

    private function reply(string $text, ?string $parseMode = null): array
    {
        return [
            'reply' => $text,
            'parse_mode' => $parseMode,
        ];
    }
}
