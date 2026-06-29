<?php

namespace App\DTOs;

class TaskData
{
    public function __construct(
        public string $name,
        public ?string $description = null,
        public ?string $status = null,
        public ?string $priority = null,
        public ?string $deadline_at = null,
        // Future extensions can be added here (e.g. parent_id, labels)
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? null,
            status: $data['status'] ?? null,
            priority: $data['priority'] ?? null,
            deadline_at: $data['deadline_at'] ?? null,
        );
    }
}
