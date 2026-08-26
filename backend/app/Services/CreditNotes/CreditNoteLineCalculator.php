<?php

namespace App\Services\CreditNotes;

use App\Models\CreditNote;
use Illuminate\Validation\ValidationException;

final class CreditNoteLineCalculator
{
    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function calculateItem(string $type, array $item): array
    {
        $productId = (int) ($item['product_id'] ?? 0);
        $quantity = round((float) ($item['quantity'] ?? 0), 3);
        $reason = filled($item['reason'] ?? null) ? trim((string) $item['reason']) : null;

        if ($productId < 1) {
            throw ValidationException::withMessages([
                'items' => ['Each line must include a product.'],
            ]);
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'items' => ['Quantity must be greater than 0.'],
            ]);
        }

        if ($type === CreditNote::TYPE_SALES_RETURN) {
            $rate = round((float) ($item['rate'] ?? 0), 2);

            if ($rate < 0) {
                throw ValidationException::withMessages([
                    'items' => ['Return rate cannot be negative.'],
                ]);
            }

            return [
                'product_id' => $productId,
                'quantity' => $quantity,
                'rate' => $rate,
                'original_rate' => null,
                'revised_rate' => null,
                'amount' => round($quantity * $rate, 2),
                'reason' => $reason,
            ];
        }

        $originalRate = round((float) ($item['original_rate'] ?? 0), 2);
        $revisedRate = round((float) ($item['revised_rate'] ?? 0), 2);

        if ($originalRate < 0 || $revisedRate < 0) {
            throw ValidationException::withMessages([
                'items' => ['Rates cannot be negative.'],
            ]);
        }

        if (abs($originalRate - $revisedRate) < 0.01) {
            throw ValidationException::withMessages([
                'items' => ['Original rate and revised rate must be different.'],
            ]);
        }

        return [
            'product_id' => $productId,
            'quantity' => $quantity,
            'rate' => null,
            'original_rate' => $originalRate,
            'revised_rate' => $revisedRate,
            'amount' => round(abs($originalRate - $revisedRate) * $quantity, 2),
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{items: list<array<string, mixed>>, amount: float}
     */
    public function calculate(string $type, array $items): array
    {
        $calculated = array_map(
            fn (array $item): array => $this->calculateItem($type, $item),
            $items,
        );

        $amount = round(array_sum(array_column($calculated, 'amount')), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Credit Note amount must be greater than 0.'],
            ]);
        }

        return [
            'items' => $calculated,
            'amount' => $amount,
        ];
    }
}
