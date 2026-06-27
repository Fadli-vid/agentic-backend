<?php

namespace App\Services;

use App\Models\AgentEvent;
use Illuminate\Support\Facades\Log;

/**
 * Channel-agnostic orchestrator for the Kobi AI agent.
 *
 * Receives a generic user message, runs it through the AI pipeline
 * (event tracking → intent analysis → action execution), and returns
 * a structured result. Has zero awareness of Telegram, webhooks, or
 * any transport-layer concepts.
 *
 * TODO: Inject ContextManager to load conversation history and user memory before Gemini analysis.
 * TODO: Replace raw array returns with AgentResult DTO for type safety and IDE autocompletion.
 * TODO: Extract action routing to ActionRouter (Strategy pattern) so new actions don't grow this class.
 * TODO: Inject ReplyBuilder to format AI replies per channel requirements (Markdown, HTML, plain).
 */
class AgentOrchestratorService
{
    public function __construct(
        private AgentEventService $agentEventService,
        private GeminiService $geminiService,
        private AgentActionService $agentActionService,
    ) {
    }

    /**
     * Process a user message through the full AI pipeline.
     *
     * 1. Create AgentEvent
     * 2. Analyze intent via Gemini
     * 3. Execute the resolved action
     * 4. Finalize the event
     * 5. Return structured result
     *
     * @param  string      $source   Channel identifier (e.g. 'telegram', 'web', 'api')
     * @param  string      $chatId   Unique user/conversation identifier
     * @param  string      $text     The user's message
     * @param  string|null $userName Display name of the user
     * @return array{reply: string, parse_mode: string|null, status: string, result: array, error_message: string|null}
     */
    public function handleMessage(string $source, string $chatId, string $text, ?string $userName = null): array
    {
        $event = $this->safeCreateAgentEvent($source, $chatId, $text, $userName);

        // --- Gemini intent analysis ---

        $this->safeMarkAnalyzing($event);

        try {
            $analysis = $this->geminiService->analyzeMessage($text);
        } catch (\Throwable $exception) {
            Log::error('Gemini analysis exception.', [
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            $analysis = [
                'ok' => false,
                'error_type' => 'failed_ai',
                'reply' => 'Maaf, Kobi sedang gagal membaca pesan lewat Gemini. Coba lagi ya.',
            ];
        }

        Log::info('After Gemini.', [
            'event_id' => $event?->id,
            'ok' => $analysis['ok'] ?? false,
            'action' => $analysis['action'] ?? null,
        ]);

        // --- Handle Gemini failure ---

        if (!($analysis['ok'] ?? false)) {
            $this->safeMarkFailed(
                $event,
                $analysis['error_type'] ?? 'failed_ai',
                $analysis['reply'] ?? 'AI error.',
                ['analysis' => $analysis]
            );

            return $this->normalizeActionReply([
                'status' => $analysis['error_type'] ?? 'failed_ai',
                'reply' => $analysis['reply'] ?? 'Maaf, Kobi sedang mengalami kendala AI. Coba lagi ya.',
                'parse_mode' => $analysis['parse_mode'] ?? null,
            ]);
        }

        // --- Update event with analysis ---

        $this->safeUpdateEvent($event, [
            'action' => $analysis['action'] ?? null,
            'payload' => ['analysis' => $analysis],
        ]);

        // --- Execute action ---

        Log::info('Before action execute.', [
            'event_id' => $event?->id,
            'action' => $analysis['action'] ?? null,
        ]);

        try {
            $actionReply = $this->agentActionService->execute($analysis, $event);
        } catch (\Throwable $exception) {
            Log::error('Agent action exception.', [
                'action' => $analysis['action'] ?? null,
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            $actionReply = [
                'status' => 'failed_action',
                'reply' => 'Maaf, Kobi gagal menjalankan action tadi. Coba cek log backend ya.',
                'result' => [],
                'error_message' => $exception->getMessage(),
                'parse_mode' => null,
            ];
        }

        $actionReply = $this->normalizeActionReply($actionReply);

        $this->safeFinalizeEvent($event, $actionReply);

        return $actionReply;
    }

    /**
     * Execute a pre-parsed direct n8n task.
     *
     * The caller is responsible for detecting the command prefix (e.g. /task)
     * and stripping it. This method receives only the clean task content.
     *
     * @param  string      $taskContent The task body (without command prefix)
     * @param  string      $source      Channel identifier
     * @param  string      $chatId      Unique user/conversation identifier
     * @param  string|null $userName    Display name of the user
     * @return array{reply: string, parse_mode: string|null, status: string, result: array, error_message: string|null}
     */
    public function handleDirectN8nTask(string $taskContent, string $source, string $chatId, ?string $userName = null): array
    {
        $event = $this->safeCreateAgentEvent($source, $chatId, $taskContent, $userName);

        try {
            // Build the original command string that handleN8nCommand expects.
            $n8nReply = $this->agentActionService->handleN8nCommand('/task ' . $taskContent, $event);
            $n8nReply = $this->normalizeActionReply($n8nReply);

            $this->safeUpdateEvent($event, [
                'action' => 'task_command',
                'payload' => [
                    'command' => 'task',
                    'text' => $taskContent,
                ],
            ]);

            $this->safeFinalizeEvent($event, $n8nReply);

            return $n8nReply;
        } catch (\Throwable $exception) {
            Log::error('N8N task command failed.', [
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            $this->safeMarkFailed(
                $event,
                'failed_n8n_command',
                $exception->getMessage()
            );

            return $this->normalizeActionReply([
                'status' => 'n8n_error_handled',
                'reply' => 'Waduh, koneksi ke n8n lagi bermasalah. Coba cek workflow atau URL n8n ya 😅',
            ]);
        }
    }

    // -------------------------------------------------------------------------
    //  Private helpers — fault-tolerant event management
    // -------------------------------------------------------------------------

    private function safeCreateAgentEvent(string $source, string $chatId, string $text, ?string $userName): ?AgentEvent
    {
        try {
            $method = match ($source) {
                'telegram' => 'createReceivedFromTelegram',
                default => 'createReceivedFromTelegram', // TODO: Add source-specific factory methods as new channels are added.
            };

            $event = $this->agentEventService->{$method}($chatId, $text, $userName);

            Log::info('Agent event created.', [
                'event_id' => $event->id,
            ]);

            return $event;
        } catch (\Throwable $exception) {
            Log::error('Failed to create agent event.', [
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            return null;
        }
    }

    private function safeMarkAnalyzing(?AgentEvent $event): void
    {
        if (!$event) {
            return;
        }

        try {
            $this->agentEventService->markAnalyzing($event);
        } catch (\Throwable $exception) {
            Log::warning('Failed to mark event analyzing.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeFinalizeEvent(?AgentEvent $event, array $actionReply): void
    {
        if (!$event) {
            return;
        }

        try {
            $status = $actionReply['status'] ?? 'completed';
            $result = $actionReply['result'] ?? [];

            if ($status !== 'completed') {
                $this->agentEventService->markFailed(
                    $event,
                    $status,
                    $actionReply['error_message'] ?? 'Action failed.',
                    $result
                );

                return;
            }

            $this->agentEventService->markCompleted($event, $result);
        } catch (\Throwable $exception) {
            Log::error('Failed to finalize agent event.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeMarkFailed(?AgentEvent $event, string $status, string $errorMessage, array $payload = []): void
    {
        if (!$event) {
            return;
        }

        try {
            $this->agentEventService->markFailed($event, $status, $errorMessage, $payload);
        } catch (\Throwable $exception) {
            Log::error('Failed to mark event failed.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function safeUpdateEvent(?AgentEvent $event, array $data): void
    {
        if (!$event) {
            return;
        }

        try {
            $event->update($data);
        } catch (\Throwable $exception) {
            Log::warning('Failed to update agent event.', [
                'event_id' => $event->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function normalizeActionReply(?array $actionReply): array
    {
        $actionReply = is_array($actionReply) ? $actionReply : [];

        return [
            'status' => $actionReply['status'] ?? 'completed',
            'reply' => $actionReply['reply'] ?? 'Kobi sudah memproses pesanmu.',
            'result' => $actionReply['result'] ?? [],
            'parse_mode' => $actionReply['parse_mode'] ?? null,
            'error_message' => $actionReply['error_message'] ?? null,
        ];
    }
}
