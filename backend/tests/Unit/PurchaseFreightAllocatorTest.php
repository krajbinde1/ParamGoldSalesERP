<?php

use App\Services\Inventory\PurchaseFreightAllocator;

it('allocates freight by taxable value and puts the remainder on the last item', function (): void {
    $allocator = new PurchaseFreightAllocator;

    $allocated = $allocator->allocate(10, [100, 200]);

    expect($allocated)->toBe([3.33, 6.67])
        ->and(round(array_sum($allocated), 2))->toBe(10.0)
        ->and($allocator->landedCost(100, 3.33))->toBe(103.33)
        ->and($allocator->effectiveLandedRate(10, 100, 3.33))->toBe(10.333);
});

it('does not add freight to landed cost when transport is zero', function (): void {
    $allocator = new PurchaseFreightAllocator;

    expect($allocator->allocate(0, [500, 500]))->toBe([0.0, 0.0])
        ->and($allocator->effectiveLandedRate(10, 100, 0))->toBe(10.0);
});
