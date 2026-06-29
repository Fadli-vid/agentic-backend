<?php

namespace App\DTOs;

readonly class TaskData
{
    private function __construct(
        public ?string $name,
        public ?string $description,
        public ?string $status,
        public ?string $priority,
        public ?string $deadline_at,
        private array $payload
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            name: $payload['name'] ?? null,
            description: $payload['description'] ?? null,
            status: $payload['status'] ?? null,
            priority: $payload['priority'] ?? null,
            deadline_at: $payload['deadline_at'] ?? null,
            payload: $payload
        );
    }

    public static function fromAI(array $aiPayload): self
    {
        return new self(
            name: $aiPayload['name'] ?? null,
            description: $aiPayload['description'] ?? null,
            status: $aiPayload['status'] ?? null,
            priority: $aiPayload['priority'] ?? null,
            deadline_at: $aiPayload['deadline_at'] ?? null,
            payload: $aiPayload
        );
    }

    public function isProvided(string $field): bool
    {
        return array_key_exists($field, $this->payload);
    }

    public function has(string $field): bool
    {
        return !empty($this->payload[$field]);
    }

    public function toArray(): array
    {
        return $this->payload;
    }
}
