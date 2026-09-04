<?php

use App\Enums\PackagingType;
use App\Models\PackagingMaterial;

it('labels bom packaging selection as material name and packaging type', function () {
    $pouch = new PackagingMaterial([
        'packaging_name' => 'BOROFIT 1 KG',
        'packaging_type' => PackagingType::Pouch,
    ]);
    $box = new PackagingMaterial([
        'packaging_name' => 'BOROFIT 1 KG',
        'packaging_type' => PackagingType::Box,
    ]);
    $sticker = new PackagingMaterial([
        'packaging_name' => 'BOROFIT 1 KG',
        'packaging_type' => PackagingType::Sticker,
    ]);

    expect($pouch->bomSelectionLabel())->toBe('BOROFIT 1 KG — Pouch')
        ->and($box->bomSelectionLabel())->toBe('BOROFIT 1 KG — Box')
        ->and($sticker->bomSelectionLabel())->toBe('BOROFIT 1 KG — Sticker');
});
