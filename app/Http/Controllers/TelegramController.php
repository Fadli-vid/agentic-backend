<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\AgentActionService;
use App\Services\GeminiService;
use App\Services\TelegramService;

class TelegramController extends Controller
{
    public function __construct(
        private GeminiService $geminiService,
        private TelegramService $telegramService,
        private AgentActionService $agentActionService
    ) {
    }

    public function handleWebhook(Request $request)
    {
        set_time_limit(120);

        $chatId = data_get($request->all(), 'message.chat.id');
        $text = data_get($request->all(), 'message.text');

        if (!$chatId || !$text) {
            return response()->json(['status' => 'success']);
        }

        $text = trim((string) $text);

        if ($text === '') {
            return response()->json(['status' => 'success']);
        }

        $n8nReply = $this->agentActionService->handleN8nCommand($text);

        if ($n8nReply) {
            $this->telegramService->sendMessage(
                $chatId,
                $n8nReply['reply'],
                $n8nReply['parse_mode'] ?? null
            );

            return response()->json(['status' => 'success']);
        }

        $analysis = $this->geminiService->analyzeMessage($text);
        $actionReply = $this->agentActionService->execute($analysis);

        $this->telegramService->sendMessage(
            $chatId,
            $actionReply['reply'],
            $actionReply['parse_mode'] ?? null
        );

        return response()->json(['status' => 'success']);
    }
}