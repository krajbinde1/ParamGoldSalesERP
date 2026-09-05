<?php

use App\Services\Inventory\WeightedAverageCosting;

it('calculates the GST-exclusive weighted average from the purchase example', function (): void {
    $costing = new WeightedAverageCosting;

    $average = $costing->newAverageRate(100, 80, 100, 100);

    expect($average)->toBe(90.0)
        ->and($costing->stockValue(200, $average))->toBe(18000.0)
        ->and($costing->formatRate($average, 'Kg'))->toBe('₹90.00/Kg');
});

it('uses the purchase rate when there is no existing stock', function (): void {
    $costing = new WeightedAverageCosting;

    expect($costing->newAverageRate(0, 0, 50, 120))->toBe(120.0)
        ->and($costing->stockValue(50, 120))->toBe(6000.0)
        ->and($costing->stockValue(0, 90))->toBe(0.0);
});
