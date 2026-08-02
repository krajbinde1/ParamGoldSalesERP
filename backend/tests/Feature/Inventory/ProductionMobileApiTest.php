<?php

use App\Enums\BomItemType;
use App\Enums\BomStatus;
use App\Enums\UserRole;
use App\Models\Bom;
use App\Models\BomItem;
use App\Models\PackagingMaterial;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\User;
use Illuminate\Support\Str;

function mobileApiSupervisor(): User
{
    return User::query()->create([
        'name' => 'Mobile Supervisor',
        'email' => 'mobile.supervisor.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::ProductionSupervisor->value,
        'job_role' => 'Production Supervisor',
    ]);
}

function mobileApiEmployee(): User
{
    return User::query()->create([
        'name' => 'Mobile Employee',
        'email' => 'mobile.employee.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Sales Executive',
    ]);
}

function mobileApiDirector(): User
{
    return User::query()->create([
        'name' => 'Mobile Director',
        'email' => 'mobile.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
        'job_role' => 'Director',
    ]);
}

/**
 * @return array{product: Product, bom: Bom, raw: RawMaterial, pack: PackagingMaterial, altRaw: RawMaterial, bomItem: BomItem}
 */
function seedMobileProductionFixture(): array
{
    $raw = RawMaterial::query()->create([
        'material_name' => 'Primary Alloy',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 100,
        'minimum_stock' => 5,
        'purchase_rate' => 50,
        'average_rate' => 50,
        'status' => true,
    ]);

    $altRaw = RawMaterial::query()->create([
        'material_name' => 'Alternate Alloy',
        'category' => 'Metal',
        'unit' => 'Kg',
        'opening_stock' => 80,
        'minimum_stock' => 5,
        'purchase_rate' => 55,
        'average_rate' => 55,
        'status' => true,
    ]);

    $pack = PackagingMaterial::query()->create([
        'packaging_name' => 'Primary Carton',
        'category' => 'Boxes',
        'unit' => 'Nos',
        'opening_stock' => 100,
        'minimum_stock' => 5,
        'purchase_rate' => 5,
        'average_rate' => 5,
        'status' => true,
    ]);

    $product = Product::query()->create([
        'product_name' => 'Mobile Coin',
        'uom' => 'Nos',
        'dealer_price' => 100,
        'status' => true,
        'manufacturing_enabled' => true,
        'production_unit' => 'Nos',
        'standard_batch_size' => 1,
    ]);

    $bom = Bom::query()->create([
        'product_id' => $product->id,
        'standard_batch_size' => 1,
        'output_quantity' => 1,
        'batch_quantity' => 1,
        'batch_unit' => 'Nos',
        'effective_date' => now()->toDateString(),
        'status' => BomStatus::Active,
    ]);

    $item = BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::RawMaterial,
        'raw_material_id' => $raw->id,
        'required_quantity' => 1,
        'unit' => 'Kg',
        'wastage_percentage' => 0,
        'calculated_quantity' => 1,
        'is_optional' => false,
        'sort_order' => 1,
    ]);

    BomItem::query()->create([
        'bom_id' => $bom->id,
        'item_type' => BomItemType::PackagingMaterial,
        'packaging_material_id' => $pack->id,
        'required_quantity' => 1,
        'unit' => 'Nos',
        'wastage_percentage' => 0,
        'calculated_quantity' => 1,
        'is_optional' => false,
        'sort_order' => 2,
    ]);

    \App\Models\BomItemAlternate::query()->create([
        'bom_item_id' => $item->id,
        'item_type' => BomItemType::RawMaterial->value,
        'raw_material_id' => $altRaw->id,
        'conversion_ratio' => 1,
        'is_approved' => true,
        'priority' => 1,
    ]);

    return compact('product', 'bom', 'raw', 'pack', 'altRaw') + ['bomItem' => $item];
}

it('forbids employee from production inventory routes', function () {
    $employee = mobileApiEmployee();
    $this->actingAs($employee, 'sanctum')
        ->getJson('/api/production/inventory/dashboard')
        ->assertForbidden();
});

it('allows supervisor to load inventory dashboard and raw materials', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();

    $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/production/inventory/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true);

    $expectedTotal = round(
        (float) $fixture['raw']->fresh()->current_stock_value + (float) $fixture['altRaw']->fresh()->current_stock_value,
        2,
    );

    $response = $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/production/inventory/raw-materials')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data',
            'meta',
            'summary' => ['total_raw_material_value'],
        ]);

    // Production supervisors see RM WAVG stock value on mobile (same stored column as web).
    expect((float) $response->json('summary.total_raw_material_value'))->toBe($expectedTotal);

    $rows = collect($response->json('data'));
    $alt = $rows->firstWhere('id', $fixture['altRaw']->id);
    expect($alt)->not->toBeNull()
        ->and((float) $alt['available_quantity'])->toBe((float) $fixture['altRaw']->fresh()->current_stock)
        ->and((float) $alt['stock_value'])->toBe((float) $fixture['altRaw']->fresh()->current_stock_value)
        ->and((float) $alt['valuation_rate'])->toBe((float) $fixture['altRaw']->fresh()->average_rate)
        ->and($alt['valuation_source'])->toBe('weighted_average');
});

it('returns master view without stock qty value or ledger fields', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();

    $response = $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/production/inventory/raw-materials?view=master')
        ->assertOk()
        ->assertJsonPath('meta.view', 'master')
        ->assertJsonPath('meta.can_create', false)
        ->assertJsonPath('meta.can_update', false);

    expect($response->json('summary'))->toBeNull();

    $row = collect($response->json('data'))->firstWhere('id', $fixture['raw']->id);
    expect($row)->not->toBeNull()
        ->and($row)->toHaveKeys(['id', 'name', 'code', 'material_code', 'material_name', 'unit', 'status', 'status_label'])
        ->and($row)->not->toHaveKey('available_quantity')
        ->and($row)->not->toHaveKey('stock_value')
        ->and($row)->not->toHaveKey('stock_status')
        ->and($row['status'])->toBeTrue()
        ->and($row['status_label'])->toBe('Active');
});

it('forbids supervisor from creating raw material masters', function () {
    $supervisor = mobileApiSupervisor();

    $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/inventory/raw-materials', [
            'material_name' => 'Unauthorized Alloy',
            'unit' => 'Kg',
            'minimum_stock' => 1,
            'status' => true,
        ])
        ->assertForbidden();
});

it('returns structured inventory dashboard cards for production supervisor', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();

    $response = $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/production/inventory/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'cards' => [
                    'raw_material' => ['item_count', 'stock_value'],
                    'semi_finished' => ['item_count', 'stock_value'],
                    'finished_product' => ['item_count', 'stock_value'],
                    'today_production' => ['entry_count', 'produced_qty', 'produced_qty_unit_safe'],
                    'month_production' => ['entry_count'],
                    'low_stock' => ['item_count'],
                ],
                'quick_actions',
                'can_adjust_stock',
            ],
        ]);

    $cards = $response->json('data.cards');
    expect((int) $cards['raw_material']['item_count'])->toBeGreaterThanOrEqual(2)
        ->and((float) $cards['raw_material']['stock_value'])->toBeGreaterThan(0)
        ->and((int) $cards['finished_product']['item_count'])->toBeGreaterThanOrEqual(1)
        ->and($cards['today_production'])->toHaveKeys(['entry_count', 'produced_qty', 'produced_qty_unit_safe'])
        ->and($cards['month_production'])->toHaveKey('entry_count')
        ->and($cards['low_stock'])->toHaveKey('item_count');

    $actionKeys = collect($response->json('data.quick_actions'))->pluck('key')->all();
    expect($actionKeys)->toContain('stock_report')
        ->and($actionKeys)->toContain('new_production')
        ->and($actionKeys)->toContain('production_history')
        ->and($actionKeys)->toContain('stock_ledger')
        ->and($actionKeys)->not->toContain('rm_inward')
        ->and($actionKeys)->not->toContain('packaging_inward')
        ->and($actionKeys)->not->toContain('view_bom')
        ->and($response->json('data.can_adjust_stock'))->toBeFalse();

    // Fixture materials exist — keep reference so Pest does not flag unused.
    expect($fixture['raw']->id)->toBeGreaterThan(0);
});

it('forbids supervisor from creating or updating raw material inwards via mobile API', function () {
    $supervisor = mobileApiSupervisor();
    $fixture = seedMobileProductionFixture();

    $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/inwards', [
            'inward_date' => now()->toDateString(),
            'supplier_name' => 'Blocked Supplier',
            'supplier_invoice_number' => 'INV-BLOCK-'.uniqid(),
            'items' => [[
                'raw_material_id' => $fixture['raw']->id,
                'inward_quantity' => 1,
                'basic_rate' => 10,
            ]],
        ])
        ->assertForbidden();

    // Seed via service (Filament/web path) then deny mobile API update.
    $posted = app(\App\Services\Inventory\RawMaterialInwardService::class)->createAndPost(
        [
            'inward_date' => now()->toDateString(),
            'supplier_name' => 'Seed Supplier',
            'supplier_invoice_number' => 'INV-SEED-'.uniqid(),
        ],
        [[
            'raw_material_id' => $fixture['raw']->id,
            'inward_quantity' => 1,
            'basic_rate' => 10,
            'discount_amount' => 0,
            'freight_amount' => 0,
            'other_charges' => 0,
            'gst_percentage' => 0,
        ]],
        $supervisor,
    );

    $this->actingAs($supervisor, 'sanctum')
        ->putJson('/api/production/inwards/'.$posted->id, [
            'inward_date' => now()->toDateString(),
            'supplier_name' => 'Blocked',
            'items' => [],
        ])
        ->assertForbidden();
});

it('forbids supervisor from creating packaging material inwards via mobile API', function () {
    $supervisor = mobileApiSupervisor();
    $fixture = seedMobileProductionFixture();

    $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/packaging-inwards', [
            'inward_date' => now()->toDateString(),
            'supplier_name' => 'Blocked Pack Supplier',
            'items' => [[
                'packaging_material_id' => $fixture['pack']->id,
                'inward_quantity' => 1,
                'basic_rate' => 5,
            ]],
        ])
        ->assertForbidden();
});

it('forbids supervisor from updating raw material masters', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();

    $this->actingAs($supervisor, 'sanctum')
        ->putJson('/api/production/inventory/raw-materials/'.$fixture['raw']->id, [
            'material_name' => 'Hacked Name',
            'unit' => 'Kg',
            'minimum_stock' => 1,
            'status' => true,
        ])
        ->assertForbidden();
});

it('returns inventory stock report with RM fields and filtered total for supervisors', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();

    $expectedTotal = round(
        (float) $fixture['raw']->fresh()->current_stock_value + (float) $fixture['altRaw']->fresh()->current_stock_value,
        2,
    );

    $response = $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/production/inventory/stock-report?'.http_build_query([
            'inventory_type' => 'raw_material',
        ]))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.can_view_costs', true);

    $data = $response->json('data');
    expect((float) $data['summary']['total_raw_material_value'])->toBe($expectedTotal)
        ->and((float) $data['summary']['filtered_stock_value'])->toBe($expectedTotal);

    $alt = collect($data['items'])->firstWhere('item_id', $fixture['altRaw']->id);
    expect($alt)->not->toBeNull()
        ->and($alt['name'])->toBe($fixture['altRaw']->material_name)
        ->and($alt['code'])->toBe($fixture['altRaw']->material_code)
        ->and((float) $alt['available_quantity'])->toBe((float) $fixture['altRaw']->fresh()->current_stock)
        ->and((float) $alt['stock_value'])->toBe((float) $fixture['altRaw']->fresh()->current_stock_value)
        ->and((float) $alt['valuation_rate'])->toBe((float) $fixture['altRaw']->fresh()->average_rate)
        ->and($alt['stock_status'])->not->toBeEmpty();
});

it('returns raw material stock_value and filtered total for cost-permitted users', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();
    $supervisor->forceFill(['can_view_production_costs' => true])->save();

    $expectedTotal = round(
        (float) $fixture['raw']->fresh()->current_stock_value + (float) $fixture['altRaw']->fresh()->current_stock_value,
        2,
    );

    $response = $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/production/inventory/raw-materials')
        ->assertOk();

    expect((float) $response->json('summary.total_raw_material_value'))->toBe($expectedTotal);

    $rows = collect($response->json('data'));
    $alt = $rows->firstWhere('id', $fixture['altRaw']->id);
    expect($alt)->not->toBeNull()
        ->and((float) $alt['available_quantity'])->toBe((float) $fixture['altRaw']->fresh()->current_stock)
        ->and((float) $alt['stock_value'])->toBe((float) $fixture['altRaw']->fresh()->current_stock_value)
        ->and((float) $alt['average_rate'])->toBe((float) $fixture['altRaw']->fresh()->average_rate);
});

it('returns raw material item ledger matching web service for supervisors', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();
    $material = $fixture['raw']->fresh();

    $from = now()->subYear()->toDateString();
    $to = now()->toDateString();

    $serviceResult = app(\App\Services\Inventory\StockItemLedgerService::class)->build([
        'item_type' => 'raw_material',
        'item_id' => $material->id,
        'from' => $from,
        'to' => $to,
        'page' => 1,
        'per_page' => 200,
    ]);

    $response = $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/production/inventory/ledger?'.http_build_query([
            'item_type' => 'raw_material',
            'item_id' => $material->id,
            'from' => $from,
            'to' => $to,
        ]))
        ->assertOk()
        ->assertJsonPath('success', true);

    $data = $response->json('data');
    expect($data['can_view_costs'])->toBeTrue()
        ->and($data)->toHaveKeys([
            'item', 'filters', 'opening_balance', 'transactions', 'closing_balance',
            'display_rows', 'header', 'rows', 'totals', 'meta',
        ])
        ->and($data['item']['name'])->toBe($material->material_name)
        ->and($data['item']['code'])->toBe($material->material_code)
        ->and($data['item']['unit'])->toBe($material->unit)
        ->and($data['filters']['from'])->toBe($serviceResult->header['from'])
        ->and($data['filters']['to'])->toBe($serviceResult->header['to'])
        ->and($data['opening_balance']['particulars'])->toBe('Opening Balance')
        ->and($data['opening_balance']['row_type'])->toBe('opening_balance')
        ->and((float) $data['opening_balance']['closing_quantity'])->toBe((float) $serviceResult->header['opening_qty'])
        ->and((float) $data['opening_balance']['average_purchase_rate'])->toBe((float) $serviceResult->header['opening_rate'])
        ->and((float) $data['opening_balance']['closing_value'])->toBe((float) $serviceResult->header['opening_value'])
        ->and($data['closing_balance']['particulars'])->toBe('Closing Balance')
        ->and($data['closing_balance']['row_type'])->toBe('closing_balance')
        ->and((float) $data['closing_balance']['closing_quantity'])->toBe((float) $serviceResult->totals['closing_qty'])
        ->and((float) $data['closing_balance']['average_purchase_rate'])->toBe((float) $serviceResult->totals['closing_rate'])
        ->and((float) $data['closing_balance']['closing_value'])->toBe((float) $serviceResult->totals['closing_value'])
        ->and((float) $data['closing_balance']['total_inward_quantity'])->toBe((float) $serviceResult->totals['total_inward_qty'])
        ->and((float) $data['closing_balance']['total_outward_quantity'])->toBe((float) $serviceResult->totals['total_outward_qty'])
        ->and(count($data['transactions']))->toBe(count($serviceResult->rows))
        ->and(count($data['display_rows']))->toBe(count($serviceResult->rows) + 2)
        ->and($data['display_rows'][0]['row_type'])->toBe('opening_balance')
        ->and($data['display_rows'][0]['particulars_display'])->toBe('Opening Balance')
        ->and($data['display_rows'][0]['voucher_display'])->toBe('—')
        ->and($data['display_rows'][0]['inward_qty_display'])->toBe('—')
        ->and($data['display_rows'][array_key_last($data['display_rows'])]['row_type'])->toBe('closing_balance')
        ->and($data['display_rows'][array_key_last($data['display_rows'])]['particulars_display'])->toBe('Closing Balance')
        ->and((float) $data['header']['available_quantity'])->toBe((float) $material->current_stock)
        ->and((float) $data['header']['current_stock_value'])->toBe((float) $material->current_stock_value);

    if (count($serviceResult->rows) > 0) {
        $apiRow = $data['transactions'][0];
        $svcRow = $serviceResult->rows[0];
        expect($apiRow['date'])->toBe($svcRow['date'])
            ->and($apiRow['particulars'])->toBe($svcRow['particulars'])
            ->and($apiRow['voucher_reference_number'])->toBe($svcRow['voucher_reference_number'] ?? ($svcRow['voucher_no'] ?: null))
            ->and($apiRow['inward_quantity'])->toBe($svcRow['inward_qty'])
            ->and($apiRow['outward_quantity'])->toBe($svcRow['outward_qty'])
            ->and((float) $apiRow['closing_quantity'])->toBe((float) $svcRow['closing_qty'])
            ->and((float) $apiRow['average_purchase_rate'])->toBe((float) $svcRow['closing_rate'])
            ->and((float) $apiRow['closing_value'])->toBe((float) $svcRow['closing_value']);

        $displayTxn = $data['display_rows'][1];
        expect($displayTxn['row_type'])->toBe('transaction')
            ->and($displayTxn['date_display'])->toBe($svcRow['date'])
            ->and($displayTxn['particulars_display'])->toBe($svcRow['particulars'])
            ->and($displayTxn['closing_qty_display'])->toBe(\App\Filament\Pages\StockItemLedger::formatQty($svcRow['closing_qty']))
            ->and($displayTxn['average_purchase_rate_display'])->toBe(\App\Filament\Pages\StockItemLedger::formatRate($svcRow['closing_rate']))
            ->and($displayTxn['closing_value_display'])->toBe(\App\Filament\Pages\StockItemLedger::formatMoney($svcRow['closing_value']));
    }
});

it('exports item stock ledger excel for supervisors via mobile api', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();
    $material = $fixture['raw']->fresh();

    $from = now()->subYear()->toDateString();
    $to = now()->toDateString();

    $response = $this->actingAs($supervisor, 'sanctum')
        ->get('/api/production/inventory/ledger/export?'.http_build_query([
            'item_type' => 'raw_material',
            'item_id' => $material->id,
            'from' => $from,
            'to' => $to,
        ]));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('Stock_Ledger_')
        ->and($response->headers->get('content-disposition'))->toContain('.xlsx');
});

it('returns item stock ledger print html for supervisors via mobile api', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();
    $material = $fixture['raw']->fresh();

    $from = now()->subYear()->toDateString();
    $to = now()->toDateString();

    $response = $this->actingAs($supervisor, 'sanctum')
        ->get('/api/production/inventory/ledger/print?'.http_build_query([
            'item_type' => 'raw_material',
            'item_id' => $material->id,
            'from' => $from,
            'to' => $to,
        ]));

    $response->assertOk();
    $html = $response->getContent();
    expect($html)->toContain('Stock Ledger')
        ->and($html)->toContain($material->material_name)
        ->and($html)->toContain('Opening Balance')
        ->and($html)->toContain('Closing Balance')
        ->and($html)->toContain('Average Purchase Rate');
});

it('downloads item stock ledger pdf matching web service totals for supervisors', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();
    $material = $fixture['raw']->fresh();

    $from = now()->subYear()->toDateString();
    $to = now()->toDateString();

    // Ensure the PDF has real ledger rows (same service as web print).
    \App\Models\StockLedger::query()->create([
        'transaction_date' => now()->toDateString(),
        'transaction_type' => \App\Enums\StockTransactionType::RawMaterialInward->value,
        'item_type' => \App\Enums\StockItemType::RawMaterial->value,
        'raw_material_id' => $material->id,
        'reference_number' => 'RMI-PDF-TEST',
        'quantity_in' => 25,
        'quantity_out' => 0,
        'stock_before' => 0,
        'stock_after' => 25,
        'rate' => 50,
        'old_average_rate' => 0,
        'new_average_rate' => 50,
        'average_rate_before' => 0,
        'average_rate_after' => 50,
        'opening_value' => 0,
        'closing_value' => 1250,
        'transaction_value' => 1250,
        'inward_value' => 1250,
        'outward_value' => 0,
        'remarks' => 'PDF test inward',
    ]);

    $filters = [
        'item_type' => 'raw_material',
        'item_id' => $material->id,
        'from' => $from,
        'to' => $to,
    ];

    $serviceResult = app(\App\Services\Inventory\StockItemLedgerService::class)->build([
        ...$filters,
        'page' => 1,
        'per_page' => 1,
    ], requireItem: true);

    $streamed = iterator_to_array(
        app(\App\Services\Inventory\StockItemLedgerService::class)->streamRows($filters),
        false,
    );

    $viewHtml = view('filament.pages.stock-item-ledger-pdf', [
        'companyName' => (string) config('app.name', 'Param Gold Sales ERP'),
        'header' => $serviceResult->header,
        'totals' => $serviceResult->totals,
        'rows' => $streamed,
    ])->render();

    expect($viewHtml)->toContain('Item Stock Ledger')
        ->and($viewHtml)->toContain($material->material_name)
        ->and($viewHtml)->toContain('Opening Balance')
        ->and($viewHtml)->toContain('Closing Balance')
        ->and($viewHtml)->toContain('25.000');

    $response = $this->actingAs($supervisor, 'sanctum')
        ->get('/api/production/inventory/ledger/pdf?'.http_build_query($filters));

    $response->assertOk();
    $pdfBytes = (string) $response->getContent();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('Item_Stock_Ledger_')
        ->and($response->headers->get('content-disposition'))->toContain($serviceResult->header['from'])
        ->and($response->headers->get('content-disposition'))->toContain($serviceResult->header['to'])
        ->and($response->headers->get('content-disposition'))->toContain('.pdf')
        ->and(substr($pdfBytes, 0, 4))->toBe('%PDF')
        ->and(strlen($pdfBytes))->toBeGreaterThan(5000)
        ->and($streamed[0]['row_type'] ?? null)->toBe('opening')
        ->and($streamed[0]['particulars'] ?? null)->toBe('Opening Balance')
        ->and((float) $serviceResult->totals['closing_qty'])->toBe(
            (float) $streamed[array_key_last($streamed)]['closing_qty'],
        )
        ->and((float) $serviceResult->totals['total_inward_qty'])->toBe(25.0)
        ->and((float) $serviceResult->totals['total_outward_qty'])->toBeGreaterThanOrEqual(0.0);

    $decoded = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdfBytes, $m)) {
        foreach ($m[1] as $stream) {
            $try = @gzuncompress($stream);
            if ($try === false) {
                $try = @gzinflate($stream);
            }
            $decoded .= ($try !== false ? $try : $stream)."\n";
        }
    }
    $readable = preg_replace('/\x00/', '', $decoded);

    expect($readable)->toContain('Opening Balance')
        ->and($readable)->toContain('Closing Balance')
        ->and($readable)->toContain($material->material_name)
        ->and($readable)->toContain('Item Stock Ledger');

    $this->actingAs(mobileApiEmployee(), 'sanctum')
        ->get('/api/production/inventory/ledger/pdf?'.http_build_query($filters))
        ->assertForbidden();
});

it('exports inventory stock report pdf with same filtered summary as json', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();

    $filters = [
        'inventory_type' => 'raw_material',
        'search' => $fixture['altRaw']->material_name,
    ];

    $json = $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/production/inventory/stock-report?'.http_build_query($filters))
        ->assertOk()
        ->json('data');

    expect($json['meta']['total'])->toBe(1)
        ->and($json['items'][0]['name'])->toBe($fixture['altRaw']->material_name);

    $expectedTotal = round((float) $json['summary']['filtered_stock_value'], 2);
    $expectedName = (string) $json['items'][0]['name'];
    $expectedCode = (string) $json['items'][0]['code'];

    $response = $this->actingAs($supervisor, 'sanctum')
        ->withHeaders(['Accept' => 'application/pdf'])
        ->get('/api/production/inventory/stock-report/pdf?'.http_build_query($filters));

    $response->assertOk();
    $pdfBytes = (string) $response->getContent();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('Inventory_Stock_Report_')
        ->and($response->headers->get('content-disposition'))->toContain('Raw_Material')
        ->and($response->headers->get('content-disposition'))->toContain('.pdf')
        ->and(substr($pdfBytes, 0, 4))->toBe('%PDF')
        ->and(strlen($pdfBytes))->toBeGreaterThan(500);

    $viewHtml = view('filament.pages.inventory-stock-report-pdf', [
        'companyName' => (string) config('app.name', 'Param Gold Sales ERP'),
        'generatedAt' => now('Asia/Kolkata')->format('d M Y, h:i A'),
        'inventoryTypeLabel' => 'Raw Material',
        'appliedFilters' => $json['applied_filters'] ?? [],
        'rows' => [[
            'sr_no' => 1,
            'name' => $expectedName,
            'code' => $expectedCode,
            'inventory_type' => 'Raw Material',
            'available_quantity' => (float) $json['items'][0]['available_quantity'],
            'unit' => $json['items'][0]['unit'] ?? '',
            'stock_value' => (float) $json['items'][0]['stock_value'],
            'stock_status' => $json['items'][0]['stock_status'],
            'stock_status_label' => 'In',
        ]],
        'totals' => [[
            'label' => 'Total Raw Material Value',
            'value' => $expectedTotal,
            'bold' => true,
        ]],
        'showCosts' => true,
    ])->render();

    expect($viewHtml)->toContain('Inventory Stock Report')
        ->and($viewHtml)->toContain($expectedName)
        ->and($viewHtml)->toContain($expectedCode)
        ->and($viewHtml)->toContain('Total Raw Material Value');

    $decoded = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdfBytes, $m)) {
        foreach ($m[1] as $stream) {
            $try = @gzuncompress($stream);
            if ($try === false) {
                $try = @gzinflate($stream);
            }
            $decoded .= ($try !== false ? $try : $stream)."\n";
        }
    }
    $readable = preg_replace('/\x00/', '', $decoded);

    expect($readable)->toContain('Inventory Stock Report')
        ->and($readable)->toContain($expectedName)
        ->and($readable)->toContain('Total Raw Material Value');

    $this->actingAs(mobileApiEmployee(), 'sanctum')
        ->get('/api/production/inventory/stock-report/pdf?'.http_build_query($filters))
        ->assertForbidden();
});

it('returns a valid empty-range item stock ledger pdf with no-transactions marker', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();
    $material = $fixture['raw']->fresh();

    $from = now()->addYears(2)->startOfYear()->toDateString();
    $to = now()->addYears(2)->startOfYear()->addDays(2)->toDateString();

    $filters = [
        'item_type' => 'raw_material',
        'item_id' => $material->id,
        'from' => $from,
        'to' => $to,
    ];

    $response = $this->actingAs($supervisor, 'sanctum')
        ->withHeaders(['Accept' => 'application/pdf'])
        ->get('/api/production/inventory/ledger/pdf?'.http_build_query($filters));

    $response->assertOk();
    $pdfBytes = (string) $response->getContent();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and(substr($pdfBytes, 0, 4))->toBe('%PDF')
        ->and(strlen($pdfBytes))->toBeGreaterThan(500);

    $decoded = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdfBytes, $m)) {
        foreach ($m[1] as $stream) {
            $try = @gzuncompress($stream);
            if ($try === false) {
                $try = @gzinflate($stream);
            }
            $decoded .= ($try !== false ? $try : $stream)."\n";
        }
    }
    $readable = preg_replace('/\x00/', '', $decoded);

    expect($readable)->toContain('Item Stock Ledger')
        ->and($readable)->toContain($material->material_name)
        ->and($readable)->toContain('Opening Balance')
        ->and($readable)->toContain('Closing Balance');
});

it('returns active bom for manufacturable product', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();

    $this->actingAs($supervisor, 'sanctum')
        ->getJson('/api/production/boms/active?product_id='.$fixture['product']->id)
        ->assertOk()
        ->assertJsonPath('data.bom.bom_number', $fixture['bom']->bom_number)
        ->assertJsonPath('data.bom.batch_quantity', 1)
        ->assertJsonPath('data.bom.batch_unit', 'Nos')
        ->assertJsonPath('success', true);
});

it('creates a production draft and completes with stock deduction', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();
    $token = (string) Str::uuid();

    $draft = $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/batches', [
            'product_id' => $fixture['product']->id,
            'production_quantity' => 5,
            'production_date' => now()->toDateString(),
            'labour_cost' => 10,
        ])
        ->assertCreated()
        ->json('data');

    expect($draft['status'])->toBe('draft')
        ->and((float) $draft['production_quantity'])->toBe(5.0);

    $completed = $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/batches/'.$draft['id'].'/complete', [
            'posting_token' => $token,
        ])
        ->assertOk()
        ->json('data');

    expect($completed['status'])->toBe('completed')
        ->and((float) $fixture['raw']->fresh()->current_stock)->toBe(95.0)
        ->and((float) $fixture['product']->fresh()->current_finished_stock)->toBe(5.0);

    // Duplicate completion is rejected without double posting
    $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/batches/'.$draft['id'].'/complete', [
            'posting_token' => $token,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['production']);

    expect((float) $fixture['raw']->fresh()->current_stock)->toBe(95.0)
        ->and((float) $fixture['product']->fresh()->current_finished_stock)->toBe(5.0);
});

it('rejects complete when stock is insufficient', function () {
    $fixture = seedMobileProductionFixture();
    $fixture['raw']->update(['current_stock' => 1, 'opening_stock' => 1]);
    $supervisor = mobileApiSupervisor();

    $draft = $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/batches', [
            'product_id' => $fixture['product']->id,
            'production_quantity' => 10,
            'production_date' => now()->toDateString(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/batches/'.$draft['id'].'/complete', [
            'posting_token' => (string) Str::uuid(),
        ])
        ->assertStatus(422);
});

it('requires approval for material substitution and allows director approve then complete', function () {
    $fixture = seedMobileProductionFixture();
    $supervisor = mobileApiSupervisor();
    $director = mobileApiDirector();
    $bomItemId = $fixture['bomItem']->id;

    $draft = $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/batches', [
            'product_id' => $fixture['product']->id,
            'production_quantity' => 2,
            'production_date' => now()->toDateString(),
            'materials' => [
                [
                    'bom_item_id' => $bomItemId,
                    'is_substituted' => true,
                    'raw_material_id' => $fixture['altRaw']->id,
                    'consumed_quantity' => 2,
                    'conversion_ratio' => 1,
                    'substitution_reason' => 'insufficient_stock',
                ],
            ],
        ])
        ->assertCreated()
        ->json('data');

    expect($draft['requires_approval'])->toBeTrue();

    $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/batches/'.$draft['id'].'/submit-approval')
        ->assertOk()
        ->assertJsonPath('data.status', 'deviation_pending_approval');

    $this->actingAs($director, 'sanctum')
        ->postJson('/api/director/production-batches/'.$draft['id'].'/approve-deviation', [
            'notes' => 'Approved alternate',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $this->actingAs($supervisor, 'sanctum')
        ->postJson('/api/production/batches/'.$draft['id'].'/complete', [
            'posting_token' => (string) Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect((float) $fixture['altRaw']->fresh()->current_stock)->toBe(78.0)
        ->and((float) $fixture['raw']->fresh()->current_stock)->toBe(100.0);
});
