<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AgentActionService;
use App\Services\AgentEventService;
use App\Services\GeminiService;
use App\Services\TelegramService;
use App\Models\AgentEvent;

class TelegramController extends Controller
{
    public function __construct(
        private GeminiService $geminiService,
        private TelegramService $telegramService,
        private AgentActionService $agentActionService,
        private AgentEventService $agentEventService
    ) {
    }

    public function handleWebhook(Request $request)
    {
        set_time_limit(120);

        $payload = $request->all();
        $chatId = data_get($payload, 'message.chat.id');
        $text = data_get($payload, 'message.text');

        if (!$chatId || $text === null) {
            return response()->json(['status' => 'success']);
        }

        $text = trim((string) $text);

        if ($text === '') {
            return response()->json(['status' => 'success']);
        }

        $userName = $this->resolveUserName($payload);
        $event = $this->agentEventService->createReceivedFromTelegram((string) $chatId, $text, $userName);

        $n8nReply = $this->agentActionService->handleN8nCommand($text, $event);

        if ($n8nReply) {
            $event->update([
                'action' => 'task_command',
                'payload' => [
                    'command' => 'task',
                    'text' => $text,
                ],
            ]);

            $this->finalizeEventFromAction($event, $n8nReply);

            $this->telegramService->sendMessage(
                $chatId,
                $n8nReply['reply'],
                $n8nReply['parse_mode'] ?? null
            );

            return response()->json(['status' => 'success']);
        }

        $this->agentEventService->markAnalyzing($event);

        $analysis = $this->geminiService->analyzeMessage($text);

        if (!($analysis['ok'] ?? false)) {
            $status = $analysis['error_type'] ?? 'failed_ai';

            $this->agentEventService->markFailed(
                $event,
                $status,
                $analysis['reply'] ?? 'AI error.',
                [
                    'analysis' => $analysis,
                ]
            );

            $this->telegramService->sendMessage(
                $chatId,
                $analysis['reply'] ?? 'Maaf, Kobi sedang mengalami kendala. Coba lagi ya.',
                $analysis['parse_mode'] ?? null
            );

            return response()->json(['status' => 'success']);
        }

        $event->update([
            'action' => $analysis['action'] ?? null,
            'payload' => [
                'analysis' => $analysis,
            ],
        ]);

        $actionReply = $this->agentActionService->execute($analysis, $event);

        $this->finalizeEventFromAction($event, $actionReply);

        $this->telegramService->sendMessage(
            $chatId,
            $actionReply['reply'],
            $actionReply['parse_mode'] ?? null
        );

        return response()->json(['status' => 'success']);
    }

    private function finalizeEventFromAction(AgentEvent $event, array $actionReply): void
    {
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
    }

    private function resolveUserName(array $payload): ?string
    {
        $username = trim((string) data_get($payload, 'message.from.username'));

        if ($username !== '') {
            return $username;
        }

        $firstName = trim((string) data_get($payload, 'message.from.first_name'));
        $lastName = trim((string) data_get($payload, 'message.from.last_name'));

        $fullName = trim($firstName . ' ' . $lastName);

        return $fullName !== '' ? $fullName : null;
    }
}