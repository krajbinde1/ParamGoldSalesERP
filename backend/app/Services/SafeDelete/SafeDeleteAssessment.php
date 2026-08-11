<?php

namespace App\Services\SafeDelete;

use Illuminate\Database\Eloquent\Model;

final class SafeDeleteAssessment
{
    /**
     * @param  list<SafeDeleteDependency>  $dependencies
     */
    public function __construct(
        public readonly Model $record,
        public readonly string $entityLabel,
        public readonly bool $allowed,
        public readonly array $dependencies = [],
        public readonly bool $supportsDeactivate = true,
    ) {}

    public function blocked(): bool
    {
        return ! $this->allowed;
    }

    public function totalDependencyCount(): int
    {
        return collect($this->dependencies)->sum(fn (SafeDeleteDependency $dependency): int => $dependency->count);
    }

    public function message(): string
    {
        if ($this->allowed) {
            return "This {$this->entityLabel} can be deleted.";
        }

        $lines = [
            "Cannot delete this {$this->entityLabel} because it is already used in the system.",
        ];

        if ($this->dependencies !== []) {
            $lines[] = '';
            $lines[] = 'It is currently linked with:';

            foreach ($this->dependencies as $dependency) {
                $lines[] = "• {$dependency->label}: {$dependency->count}";
            }
        }

        if ($this->supportsDeactivate) {
            $lines[] = '';
            $lines[] = 'Please deactivate / mark it Inactive instead so historical records remain intact.';
        }

        return implode("\n", $lines);
    }

    public function shortMessage(): string
    {
        if ($this->allowed) {
            return "This {$this->entityLabel} can be deleted.";
        }

        return "Cannot delete this {$this->entityLabel} because it is already used in the system."
            .($this->supportsDeactivate ? ' You can deactivate it instead.' : '');
    }
}
