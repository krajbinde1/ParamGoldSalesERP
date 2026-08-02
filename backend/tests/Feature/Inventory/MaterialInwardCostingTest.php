<?php

use App\Services\Inventory\MaterialInwardCosting;
use Illuminate\Validation\ValidationException;

it('excludes freight from taxable and total but includes freight in effective inventory value', function () {
    $calculated = (new MaterialInwardCosting)->calculateItemAmounts([
        'inward_quantity' => 5,
        'basic_rate' => 10000,
        'discount_amount' => 0,
        'freight_amount' => 1000,
        'other_charges' => 0,
        'gst_percentage' => 18,
    ]);

    // Base 50000; Taxable 50000; GST 9000; Total 59000; Effective 60000; Rate 12000
    expect((float) $calculated['taxable_amount'])->toBe(50000.0)
        ->and((float) $calculated['igst_amount'])->toBe(9000.0)
        ->and((float) $calculated['total_amount'])->toBe(59000.0)
        ->and((float) $calculated['landed_cost'])->toBe(60000.0)
        ->and((float) $calculated['effective_unit_rate'])->toBe(12000.0);
});

it('matches acceptance example total vs effective separation', function () {
    $calculated = (new MaterialInwardCosting)->calculateItemAmounts([
        'inward_quantity' => 5,
        'basic_rate' => 5000,
        'discount_amount' => 0,
        'freight_amount' => 500,
        'other_charges' => 0,
        'gst_percentage' => 18,
    ]);

    // Taxable 25000; GST 4500; Total 29500; Effective 30000; Rate 6000
    expect((float) $calculated['taxable_amount'])->toBe(25000.0)
        ->and((float) $calculated['igst_amount'])->toBe(4500.0)
        ->and((float) $calculated['total_amount'])->toBe(29500.0)
        ->and((float) $calculated['landed_cost'])->toBe(30000.0)
        ->and((float) $calculated['effective_unit_rate'])->toBe(6000.0);
});

it('computes taxable as base minus discount plus other without freight', function () {
    $calculated = (new MaterialInwardCosting)->calculateItemAmounts([
        'inward_quantity' => 10,
        'basic_rate' => 100,
        'discount_amount' => 50,
        'freight_amount' => 20,
        'other_charges' => 30,
        'gst_percentage' => 18,
    ]);

    // Taxable = 1000 - 50 + 30 = 980; GST = 176.4; Total = 1156.4; Effective = 1176.4
    expect((float) $calculated['taxable_amount'])->toBe(980.0)
        ->and((float) $calculated['igst_amount'])->toBe(176.4)
        ->and((float) $calculated['total_amount'])->toBe(1156.4)
        ->and((float) $calculated['landed_cost'])->toBe(1176.4)
        ->and((float) $calculated['effective_unit_rate'])->toBe(117.64);
});

it('rejects discount exceeding purchase value', function () {
    expect(fn () => (new MaterialInwardCosting)->calculateItemAmounts([
        'inward_quantity' => 10,
        'basic_rate' => 100,
        'discount_amount' => 1000.01,
        'freight_amount' => 0,
        'other_charges' => 0,
        'gst_percentage' => 0,
    ]))->toThrow(ValidationException::class);
});

it('rejects negative freight discount other or gst', function () {
    expect(fn () => (new MaterialInwardCosting)->calculateItemAmounts([
        'inward_quantity' => 10,
        'basic_rate' => 100,
        'freight_amount' => -1,
    ]))->toThrow(ValidationException::class);
});

it('rejects non finite amounts', function () {
    expect(fn () => (new MaterialInwardCosting)->calculateItemAmounts([
        'inward_quantity' => 10,
        'basic_rate' => 100,
        'freight_amount' => INF,
    ]))->toThrow(ValidationException::class);
});
