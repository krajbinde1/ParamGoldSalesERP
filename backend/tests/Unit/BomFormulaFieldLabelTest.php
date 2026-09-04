<?php

use App\Enums\BomOutputType;
use App\Enums\InventoryUnit;

it('labels the bom formula field from the selected batch unit', function () {
    expect(InventoryUnit::formulaFieldLabel('Kg'))->toBe('Formula For Kg')
        ->and(InventoryUnit::formulaFieldLabel('Litre'))->toBe('Formula For Ltr')
        ->and(InventoryUnit::formulaFieldLabel('Ltr'))->toBe('Formula For Ltr')
        ->and(InventoryUnit::formulaFieldLabel('Nos'))->toBe('Formula For Quantity')
        ->and(InventoryUnit::formulaFieldLabel('Piece'))->toBe('Formula For Quantity')
        ->and(InventoryUnit::formulaFieldLabel(null))->toBe('Formula For Quantity');
});

it('describes semi-finished formula quantity using the batch unit', function () {
    expect(InventoryUnit::formulaFieldHelper(BomOutputType::SemiFinished->value, 'Kg'))
        ->toBe('Total Kg of semi-finished output this BOM formula produces.')
        ->and(InventoryUnit::formulaFieldHelper(BomOutputType::SemiFinished->value, 'Litre'))
        ->toBe('Total Ltr of semi-finished output this BOM formula produces.')
        ->and(InventoryUnit::formulaFieldHelper(BomOutputType::FinishedProduct->value, 'Nos'))
        ->toBe('Number of finished packs this packing recipe is for (e.g. 1 Nos of 5 KG bags).');
});
