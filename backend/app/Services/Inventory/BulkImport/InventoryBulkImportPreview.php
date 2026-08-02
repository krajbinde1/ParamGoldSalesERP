<?php

namespace App\Services\Inventory\BulkImport;

final class InventoryBulkImportPreview
{
    /**
     * @param  list<array{
     *     row_number:int,
     *     data:array<string, mixed>,
     *     is_valid:bool,
     *     action:?string,
     *     status:string,
     *     error:?string
     * }>  $rows
     * @param  array{
     *     total:int,
     *     valid:int,
     *     invalid:int,
     *     duplicate:int,
     *     to_import:int,
     *     to_skip:int
     * }  $counts
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $counts,
    ) {}
}
