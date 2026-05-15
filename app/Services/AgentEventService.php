<?php

namespace App\Services;

use App\Models\AgentEvent;

class AgentEventService
{
    public function createReceivedFromTelegram(string $chatId, string $text, ?string $userName = null): AgentEvent
    {
        return AgentEvent::create([
            'source' => 'telegram',
            'user_name' => $userName,
            'chat_id' => $chatId,
            'message' => $text,
            'status' => 'received',
        ]);
    }

    public function markAnalyzing(AgentEvent $event): AgentEvent
    {
        $event->update([
            'status' => 'analyzing',
        ]);

        return $event;
    }

    public function markCompleted(AgentEvent $event, array $result = []): AgentEvent
    {
        $updates = [
            'status' => 'completed',
        ];

        if ($result !== []) {
            $updates['result'] = $result;
        }

        $event->update($updates);

        return $event;
    }

    public function markFailed(AgentEvent $event, string $status, string $errorMessage, array $payload = []): AgentEvent
    {
        $updates = [
            'status' => $status,
            'error_message' => $errorMessage,
        ];

        if ($payload !== []) {
            $updates['payload'] = $payload;
        }

        $event->update($updates);

        return $event;
    }
}
