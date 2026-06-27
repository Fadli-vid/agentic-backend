<?php

namespace App\Services;

use App\Models\Memory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * MemoryService — domain service for user memory management.
 *
 * Pure business logic. Knows nothing about Telegram, Gemini, or controllers.
 * Communicates only with the Memory Eloquent model.
 *
 * TODO: Used by ContextManager — retrieve user's most relevant memories before Gemini analysis.
 * TODO: Used by ActionRouter — store new memories from AI-detected facts and preferences.
 * TODO: Used by ReplyBuilder — inject memory context into personalized replies.
 */
class MemoryService
{
    /**
     * Store a new memory.
     *
     * @param  array{title: string, content: string, category?: string, source?: string, importance?: int, metadata?: array} $data
     * @return array{ok: bool, message: string, data: array}
     */
    public function storeMemory(array $data): array
    {
        try {
            $memory = Memory::create([
                'category' => trim((string) ($data['category'] ?? '')),
                'title' => trim((string) ($data['title'] ?? '')),
                'content' => trim((string) ($data['content'] ?? '')),
                'source' => trim((string) ($data['source'] ?? '')),
                'importance' => (int) ($data['importance'] ?? 5),
                'metadata' => $data['metadata'] ?? null,
            ]);

            Log::info('Memory stored.', ['memory_id' => $memory->id]);

            return $this->success('Memory stored.', ['memory' => $memory->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to store memory.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to store memory.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Update an existing memory.
     *
     * @param  int   $id
     * @param  array $data  Partial update fields
     * @return array{ok: bool, message: string, data: array}
     */
    public function updateMemory(int $id, array $data): array
    {
        try {
            $memory = Memory::find($id);

            if (!$memory) {
                return $this->failure('Memory not found.', ['id' => $id]);
            }

            $memory->update($data);

            return $this->success('Memory updated.', ['memory' => $memory->fresh()->toArray()]);
        } catch (\Throwable $exception) {
            Log::error('Failed to update memory.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to update memory.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Delete a memory.
     *
     * @param  int $id
     * @return array{ok: bool, message: string, data: array}
     */
    public function deleteMemory(int $id): array
    {
        try {
            $memory = Memory::find($id);

            if (!$memory) {
                return $this->failure('Memory not found.', ['id' => $id]);
            }

            $memory->delete();

            return $this->success('Memory deleted.', ['id' => $id]);
        } catch (\Throwable $exception) {
            Log::error('Failed to delete memory.', ['error' => $exception->getMessage()]);

            return $this->failure('Failed to delete memory.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * Search memories by matching title or content with a LIKE query.
     *
     * @param  string $query  Search term
     * @param  int    $limit  Maximum results
     * @return array{ok: bool, message: string, data: array}
     */
    public function searchMemory(string $query, int $limit = 10): array
    {
        $memories = Memory::where(function ($q) use ($query) {
            $q->where('title', 'ILIKE', "%{$query}%")
                ->orWhere('content', 'ILIKE', "%{$query}%");
        })
            ->orderByDesc('importance')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->success('Search completed.', ['memories' => $memories->toArray()]);
    }

    /**
     * Get the most relevant memories based on importance and recency.
     *
     * Combines importance weighting with last-access recency for AI context.
     *
     * @param  string $query  Contextual query to match against
     * @param  int    $limit  Maximum results
     * @return array{ok: bool, message: string, data: array}
     */
    public function getRelevantMemories(string $query, int $limit = 5): array
    {
        // TODO: When vector embeddings are available, replace LIKE with semantic similarity.
        $memories = Memory::where(function ($q) use ($query) {
            $q->where('title', 'ILIKE', "%{$query}%")
                ->orWhere('content', 'ILIKE', "%{$query}%");
        })
            ->orderByDesc('importance')
            ->orderByDesc('last_accessed_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $this->touchAccessedAt($memories->pluck('id')->toArray());

        return $this->success('Relevant memories retrieved.', ['memories' => $memories->toArray()]);
    }

    /**
     * Get the most recent memories.
     *
     * @param  int $limit
     * @return array{ok: bool, message: string, data: array}
     */
    public function getRecentMemories(int $limit = 10): array
    {
        $memories = Memory::orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->success('Recent memories retrieved.', ['memories' => $memories->toArray()]);
    }

    /**
     * Get the single most important memory.
     *
     * @return array{ok: bool, message: string, data: array}
     */
    public function getMostImportantMemory(): array
    {
        $memory = Memory::orderByDesc('importance')
            ->orderByDesc('created_at')
            ->first();

        if (!$memory) {
            return $this->failure('No memories found.');
        }

        return $this->success('Most important memory retrieved.', ['memory' => $memory->toArray()]);
    }

    /**
     * Get all memories in a specific category.
     *
     * @param  string $category
     * @param  int    $limit
     * @return array{ok: bool, message: string, data: array}
     */
    public function getMemoriesByCategory(string $category, int $limit = 20): array
    {
        $memories = Memory::where('category', $category)
            ->orderByDesc('importance')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->success('Memories by category retrieved.', ['memories' => $memories->toArray()]);
    }

    /**
     * Get memories above a minimum importance threshold.
     *
     * @param  int $minImportance  Minimum importance score (0-10)
     * @param  int $limit
     * @return array{ok: bool, message: string, data: array}
     */
    public function getImportantMemories(int $minImportance = 7, int $limit = 10): array
    {
        $memories = Memory::where('importance', '>=', $minImportance)
            ->orderByDesc('importance')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $this->success('Important memories retrieved.', ['memories' => $memories->toArray()]);
    }

    /**
     * Get memories optimized for AI context injection.
     *
     * TODO: Implement semantic retrieval using vector embeddings when available.
     * TODO: Used by ContextManager — this is the primary entry point for loading
     *       user memories into the Gemini system prompt.
     *
     * @param  int $limit
     * @return array{ok: bool, message: string, data: array}
     */
    public function getMemoriesForContext(int $limit = 5): array
    {
        // Placeholder: return top memories by importance until semantic search is ready.
        return $this->getImportantMemories(5, $limit);
    }

    // -------------------------------------------------------------------------
    //  Private helpers
    // -------------------------------------------------------------------------

    /**
     * Update last_accessed_at for the given memory IDs.
     *
     * @param  array<int> $ids
     */
    private function touchAccessedAt(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        try {
            Memory::whereIn('id', $ids)->update(['last_accessed_at' => now()]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to update last_accessed_at.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @return array{ok: true, message: string, data: array}
     */
    protected function success(string $message, array $data = []): array
    {
        return ['ok' => true, 'message' => $message, 'data' => $data];
    }

    /**
     * @return array{ok: false, message: string, data: array}
     */
    protected function failure(string $message, array $data = []): array
    {
        return ['ok' => false, 'message' => $message, 'data' => $data];
    }
}
