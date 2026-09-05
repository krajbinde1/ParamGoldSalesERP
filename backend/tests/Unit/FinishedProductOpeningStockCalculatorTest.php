<?php

use App\Services\Inventory\FinishedProductOpeningStockCalculator;

it('calculates opening qty and value from cases, nos per case, and average cost', function (): void {
    expect(FinishedProductOpeningStockCalculator::openingQtyNos(10, 20))->toBe(200.0)
        ->and(FinishedProductOpeningStockCalculator::openingStockValue(200, 25))->toBe(5000.0)
        ->and(FinishedProductOpeningStockCalculator::casesFromQty(200, 20))->toBe(10.0)
        ->and(FinishedProductOpeningStockCalculator::openingQtyNos(0, 20))->toBe(0.0)
        ->and(FinishedProductOpeningStockCalculator::openingStockValue(200, 0))->toBe(0.0);
});
