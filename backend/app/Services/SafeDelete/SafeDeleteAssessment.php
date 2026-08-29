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

        $lines = [$this->shortMessage()];

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

        if ($this->dependencies === []) {
            return "Cannot delete this {$this->entityLabel} because it is already used in the system."
                .($this->supportsDeactivate ? ' You can deactivate it instead.' : '');
        }

        $parts = array_map(
            fn (SafeDeleteDependency $dependency): string => $dependency->countPhrase(),
            $this->dependencies,
        );
        $verb = count($this->dependencies) === 1 && $this->dependencies[0]->count === 1
            ? 'is'
            : 'are';

        return 'Cannot delete: '.$this->joinPhrase($parts)." {$verb} linked to this {$this->entityLabel}.";
    }

    /**
     * @param  list<string>  $parts
     */
    private function joinPhrase(array $parts): string
    {
        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).' and '.$last;
    }
}
