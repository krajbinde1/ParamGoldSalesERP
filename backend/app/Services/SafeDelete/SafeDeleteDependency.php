<?php

namespace App\Services\SafeDelete;

final class SafeDeleteDependency
{
    public function __construct(
        public readonly string $label,
        public readonly int $count,
    ) {}

    public function countPhrase(): string
    {
        $noun = $this->noun($this->count);

        return $this->count.' '.$noun;
    }

    private function noun(int $count): string
    {
        $singular = match ($this->label) {
            'Assigned Dealers' => 'assigned dealer',
            'Orders' => 'order',
            'Collections' => 'collection',
            'Attendance' => 'attendance record',
            'Route Points' => 'route point',
            'Dealer Visits' => 'dealer visit',
            'Field Activities' => 'field activity',
            'TA/DA Claims' => 'TA/DA claim',
            'Weekly Targets' => 'weekly target',
            'Direct Reports' => 'direct report',
            'Material Inward Lines' => 'material inward line',
            'Material Batches' => 'material batch',
            'Inward Returns' => 'inward return',
            'BOM Components' => 'BOM component',
            'BOM Alternates' => 'BOM alternate',
            'Production Consumptions' => 'production consumption',
            'Stock Ledger' => 'stock ledger entry',
            'Stock Adjustments' => 'stock adjustment',
            'Bill of Materials' => 'bill of materials',
            'Production Batches' => 'production batch',
            'BOMs (as output)' => 'BOM',
            'Raw Material Inwards' => 'raw material inward',
            'Packaging Material Inwards' => 'packaging material inward',
            default => strtolower($this->label),
        };

        if ($count === 1) {
            return $singular;
        }

        return match ($singular) {
            'field activity' => 'field activities',
            'bill of materials' => 'bills of materials',
            'BOM' => 'BOMs',
            default => $singular.'s',
        };
    }
}
