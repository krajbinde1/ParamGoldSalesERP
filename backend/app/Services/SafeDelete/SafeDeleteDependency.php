<?php

namespace App\Services\SafeDelete;

final class SafeDeleteDependency
{
    public function __construct(
        public readonly string $label,
        public readonly int $count,
    ) {}
}
