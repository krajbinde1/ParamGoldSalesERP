<?php

namespace App\Services\Dealers;

final class DealerBulkImportResult
{
    /** @param  list<DealerBulkImportRowError>  $errors */
    public function __construct(
        public readonly int $imported,
        public readonly array $errors,
    ) {}

    public function failed(): int
    {
        return count($this->errors);
    }
}
