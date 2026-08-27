<?php

use App\Enums\UserRole;
use App\Exports\Dealers\EmployeeOutstandingExport;
use App\Filament\Pages\TotalOutstanding;
use App\Filament\Widgets\AdminDirectorCollectionOutstandingWidget;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\DealerTallyLedger;
use App\Models\Employee;
use App\Models\User;
use App\Services\Dealers\DealerOutstandingService;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Support\IndianCurrency;
use Barryvdh\DomPDF\Facade\Pdf;
use Livewire\Livewire;
use Maatwebsite\Excel\Facades\Excel;

function outstandingPageDirector(): User
{
    return User::query()->create([
        'name' => 'Outstanding Director',
        'email' => 'outstanding.director.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Director->value,
    ]);
}

function outstandingPageEmployee(string $name, string $mobile): Employee
{
    static $n = 800;
    $n++;

    $employee = Employee::query()->create([
        'full_name' => $name,
        'mobile' => $mobile,
        'department' => 'Sales',
        'designation' => 'Executive',
        'joining_date' => '2026-01-01',
        'salary' => 25000,
        'base_location' => 'Pune',
        'daily_allowance' => 0,
        'travel_allowance' => 0,
        'aadhaar_number' => str_pad((string) (800000000000 + $n), 12, '0', STR_PAD_LEFT),
        'pan_number' => 'OOOOO'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).'Z',
        'bank_name' => 'Test Bank',
        'account_number' => str_pad((string) (800000000000 + $n), 12, '0', STR_PAD_LEFT),
        'ifsc_code' => 'TEST0123456',
        'status' => true,
    ]);

    User::query()->create([
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'.'.$mobile.'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'employee_id' => $employee->id,
    ]);

    return $employee;
}

function outstandingPageDealer(array $overrides = []): Dealer
{
    return Dealer::query()->create(array_merge([
        'firm_name' => 'Outstanding Dealer '.uniqid(),
        'owner_name' => 'Owner',
        'mobile' => '97'.random_int(10000000, 99999999),
        'address' => '123 Test Street',
        'state' => 'Maharashtra',
        'district' => 'Pune',
        'taluka' => 'Haveli',
        'pincode' => '411001',
        'village' => 'Test Village',
        'status' => true,
        'credit_limit' => 0,
        'outstanding' => 0,
    ], $overrides));
}

/**
 * @param  list<array{debit?: float, credit?: float, entry_date?: string, particulars?: string, voucher_type?: string, voucher_no?: string}>  $entries
 */
function outstandingTallyAccount(
    Dealer $dealer,
    float $openingBalance,
    string $openingType = 'debit',
    array $entries = [],
): void {
    DealerTallyLedger::query()->create([
        'dealer_id' => $dealer->id,
        'opening_balance' => $openingBalance,
        'opening_balance_type' => $openingType,
        'opening_balance_explicit' => true,
        'financial_start_date' => '2026-04-01',
        'last_imported_at' => now(),
    ]);

    foreach (array_values($entries) as $index => $entry) {
        $debit = (float) ($entry['debit'] ?? 0);
        $credit = (float) ($entry['credit'] ?? 0);
        $date = (string) ($entry['entry_date'] ?? '2026-04-10');
        $particulars = (string) ($entry['particulars'] ?? 'Sales');
        $voucherType = (string) ($entry['voucher_type'] ?? 'Sales');
        $voucherNo = (string) ($entry['voucher_no'] ?? 'SL-'.$dealer->id.'-'.$index);

        DealerTallyEntry::query()->create([
            'dealer_id' => $dealer->id,
            'entry_date' => $date,
            'particulars' => $particulars,
            'voucher_type' => $voucherType,
            'voucher_no' => $voucherNo,
            'debit' => $debit,
            'credit' => $credit,
            'source' => 'tally_import',
            'fingerprint' => DealerTallyEntry::makeFingerprint(
                (int) $dealer->id,
                $date,
                $voucherType,
                $voucherNo,
                $debit,
                $credit,
                $particulars,
            ),
        ]);
    }
}

it('links the admin total outstanding card to the employee-wise outstanding page', function (): void {
    $director = outstandingPageDirector();

    $this->actingAs($director);

    expect(TotalOutstanding::canAccess())->toBeTrue();

    Livewire::actingAs($director)
        ->test(AdminDirectorCollectionOutstandingWidget::class)
        ->assertSuccessful()
        ->assertSee(TotalOutstanding::getUrl(), false);
});

it('shows employee-wise outstanding by default and dealer-wise outstanding for a selected employee', function (): void {
    $director = outstandingPageDirector();
    $akash = outstandingPageEmployee('Akash Outstanding', '9940000001');
    $ganesh = outstandingPageEmployee('Ganesh Outstanding', '9940000002');

    $high = outstandingPageDealer([
        'firm_name' => 'High Balance Dealer',
        'village' => 'Wagholi',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 49242,
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($high, 200000);
    $mid = outstandingPageDealer([
        'firm_name' => 'Mid Balance Dealer',
        'village' => 'Kharadi',
        'assigned_employee_id' => $ganesh->id,
        'opening_balance' => 1000,
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($mid, 80000);
    $zero = outstandingPageDealer([
        'firm_name' => 'Zero Balance Dealer',
        'village' => 'Hadapsar',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 0,
        'opening_balance_date' => '2026-04-01',
    ]);
    $low = outstandingPageDealer([
        'firm_name' => 'Low Balance Dealer',
        'village' => 'Mundhwa',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 25000,
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($low, 25000);

    $companyTotal = app(DealerOutstandingService::class)->summary()['outstanding'];

    $page = Livewire::actingAs($director)
        ->test(TotalOutstanding::class)
        ->assertSuccessful()
        ->assertSee('All Employees')
        ->assertSee('Outstanding by Employee')
        ->assertSee('Export PDF')
        ->assertSee('Export Excel')
        ->assertSee('Akash Outstanding')
        ->assertSee('Ganesh Outstanding')
        ->assertSee(IndianCurrency::format($companyTotal))
        ->assertSee(IndianCurrency::format(225000))
        ->assertSee(IndianCurrency::format(80000))
        ->assertCanSeeTableRecords([$high, $mid, $low])
        ->assertCanNotSeeTableRecords([$zero]);

    $page->call('selectEmployee', $akash->id)
        ->assertSee(IndianCurrency::format(225000))
        ->assertDontSee('Outstanding by Employee')
        ->assertSee('Export PDF')
        ->assertSee('Export Excel')
        ->assertCanSeeTableRecords([$high, $low, $zero])
        ->assertCanNotSeeTableRecords([$mid]);
});

it('exports selected employee outstanding to excel with all assigned dealers and a total', function (): void {
    Excel::fake();

    $director = outstandingPageDirector();
    $akash = outstandingPageEmployee('Akash Outstanding', '9940000003');

    $high = outstandingPageDealer([
        'firm_name' => 'High Balance Dealer',
        'village' => 'Wagholi',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 1,
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($high, 200000);
    outstandingPageDealer([
        'firm_name' => 'Zero Balance Dealer',
        'village' => 'Hadapsar',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 0,
        'opening_balance_date' => '2026-04-01',
    ]);

    $payload = app(DealerOutstandingService::class)
        ->employeeExportPayload($akash->id);

    expect($payload['employee_name'])->toBe('Akash Outstanding')
        ->and($payload['total'])->toBe(200000.0)
        ->and(collect($payload['rows'])->pluck('dealer_name')->all())
        ->toEqual(['High Balance Dealer', 'Zero Balance Dealer'])
        ->and($payload['rows'][0])->toMatchArray([
            'employee_name' => 'Akash Outstanding',
            'dealer_name' => 'High Balance Dealer',
            'village' => 'Wagholi',
            'outstanding' => 200000.0,
            'credit_balance' => 0.0,
        ]);

    $export = new EmployeeOutstandingExport(
        payload: $payload,
        generatedAt: '22 Aug 2026, 08:00 PM',
    );
    $exportRows = $export->array();
    $last = $exportRows[array_key_last($exportRows)];

    expect($export->headings())->toBe([
        'Employee Name',
        'Dealer Code',
        'Dealer Name',
        'Village',
        'Outstanding Amount',
        'Credit Balance',
    ])
        ->and($last[3])->toBe('Total Outstanding')
        ->and($last[4])->toBe(200000.0);

    Livewire::actingAs($director)
        ->test(TotalOutstanding::class)
        ->call('selectEmployee', $akash->id)
        ->call('exportExcel')
        ->assertHasNoErrors();

    Excel::assertDownloaded(
        'Total_Outstanding_akash-outstanding_'.now('Asia/Kolkata')->format('Y-m-d').'.xlsx'
    );
});

it('exports all employees outstanding to excel with dealer rows and a total', function (): void {
    Excel::fake();

    $director = outstandingPageDirector();
    $akash = outstandingPageEmployee('Akash Outstanding', '9940000007');
    $ganesh = outstandingPageEmployee('Ganesh Outstanding', '9940000008');

    $high = outstandingPageDealer([
        'firm_name' => 'High Balance Dealer',
        'village' => 'Wagholi',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 1,
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($high, 200000);
    $mid = outstandingPageDealer([
        'firm_name' => 'Mid Balance Dealer',
        'village' => 'Kharadi',
        'assigned_employee_id' => $ganesh->id,
        'opening_balance' => 1,
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($mid, 80000);

    $payload = app(DealerOutstandingService::class)->exportPayload(null);

    expect($payload['scope_label'])->toBe('All Employees')
        ->and($payload['total'])->toBe(280000.0)
        ->and(collect($payload['rows'])->pluck('dealer_name')->all())
        ->toEqual(['High Balance Dealer', 'Mid Balance Dealer']);

    Livewire::actingAs($director)
        ->test(TotalOutstanding::class)
        ->call('exportExcel')
        ->assertHasNoErrors();

    Excel::assertDownloaded(
        'Total_Outstanding_all-employees_'.now('Asia/Kolkata')->format('Y-m-d').'.xlsx'
    );
});

it('builds a pdf export for the selected employee outstanding list', function (): void {
    $director = outstandingPageDirector();
    $akash = outstandingPageEmployee('Akash Outstanding', '9940000004');

    $high = outstandingPageDealer([
        'firm_name' => 'High Balance Dealer',
        'village' => 'Wagholi',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 1,
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($high, 125000);

    $this->actingAs($director);

    $payload = app(DealerOutstandingService::class)
        ->employeeExportPayload($akash->id);

    $html = view('filament.pages.employee-outstanding-pdf', [
        'companyName' => 'ParamGold ERP',
        'payload' => $payload,
        'generatedAt' => '22 Aug 2026, 08:00 PM',
    ])->render();

    expect($html)->toContain('Akash Outstanding')
        ->toContain('High Balance Dealer')
        ->toContain('Wagholi')
        ->toContain('Dealer Code')
        ->toContain('Dealer Name')
        ->toContain('Village')
        ->toContain('Outstanding Amount')
        ->toContain('Total Outstanding')
        ->toContain('Rs.');

    $pdf = Pdf::loadView('filament.pages.employee-outstanding-pdf', [
        'companyName' => 'ParamGold ERP',
        'payload' => $payload,
        'generatedAt' => '22 Aug 2026, 08:00 PM',
    ])->setPaper('a4', 'landscape');

    expect($pdf->output())->toStartWith('%PDF');

    $response = $this->actingAs($director)
        ->get(TotalOutstanding::pdfUrl($akash->id));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF');
});

it('builds a pdf export for all employees outstanding', function (): void {
    $director = outstandingPageDirector();
    $akash = outstandingPageEmployee('Akash Outstanding', '9940000005');
    $ganesh = outstandingPageEmployee('Ganesh Outstanding', '9940000006');

    $high = outstandingPageDealer([
        'firm_name' => 'High Balance Dealer',
        'village' => 'Wagholi',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 1,
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($high, 200000);
    $mid = outstandingPageDealer([
        'firm_name' => 'Mid Balance Dealer',
        'village' => 'Kharadi',
        'assigned_employee_id' => $ganesh->id,
        'opening_balance' => 1,
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($mid, 80000);

    $this->actingAs($director);

    $payload = app(DealerOutstandingService::class)->exportPayload(null);

    expect($payload['scope_label'])->toBe('All Employees')
        ->and(collect($payload['rows'])->pluck('dealer_name')->all())
        ->toEqual(['High Balance Dealer', 'Mid Balance Dealer']);

    $pdf = Pdf::loadView('filament.pages.employee-outstanding-pdf', [
        'companyName' => 'ParamGold ERP',
        'payload' => $payload,
        'generatedAt' => '22 Aug 2026, 08:00 PM',
    ])->setPaper('a4', 'landscape');

    $html = view('filament.pages.employee-outstanding-pdf', [
        'companyName' => 'ParamGold ERP',
        'payload' => $payload,
        'generatedAt' => '22 Aug 2026, 08:00 PM',
    ])->render();

    expect($html)->toContain('All Employees')
        ->toContain('Akash Outstanding')
        ->toContain('Ganesh Outstanding')
        ->toContain('Employee')
        ->toContain('Dealer Code')
        ->toContain('Outstanding Amount')
        ->toContain('Total Outstanding')
        ->and($pdf->output())->toStartWith('%PDF');

    $response = $this->actingAs($director)
        ->get(TotalOutstanding::pdfUrl());

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect($response->getContent())->toStartWith('%PDF');
});

it('keeps credit opening balances out of total outstanding and lists them separately', function (): void {
    $director = outstandingPageDirector();
    $akash = outstandingPageEmployee('Akash Credit Split', '9940000009');

    $debitDealer = outstandingPageDealer([
        'firm_name' => 'Debit Opening Dealer',
        'village' => 'Wagholi',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 1,
        'opening_balance_type' => 'debit',
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($debitDealer, 200000, 'debit');
    $creditDealer = outstandingPageDealer([
        'firm_name' => 'Credit Opening Dealer',
        'village' => 'Kharadi',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 1,
        'opening_balance_type' => 'credit',
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($creditDealer, 40000, 'credit');

    $summary = app(DealerOutstandingService::class)->summary($akash->id);
    expect($summary)->toMatchArray([
        'outstanding' => 200000.0,
        'credit' => 40000.0,
        'net' => 160000.0,
    ]);

    $employeeRow = collect(app(DealerOutstandingService::class)->totalsByAssignedEmployee())
        ->firstWhere('employee_id', $akash->id);

    expect($employeeRow)->toMatchArray([
        'total_outstanding' => 200000.0,
        'total_credit' => 40000.0,
        'net_balance' => 160000.0,
    ]);

    $page = Livewire::actingAs($director)
        ->test(TotalOutstanding::class)
        ->assertSuccessful()
        ->assertSee('Total Outstanding')
        ->assertSee('Total Credit Balance')
        ->assertSee('Net Balance')
        ->assertSee(IndianCurrency::format(200000))
        ->assertSee(IndianCurrency::format(40000))
        ->assertSee(IndianCurrency::format(160000))
        ->assertSee('Credit Opening Dealer')
        ->assertDontSee(IndianCurrency::format(-40000))
        ->assertCanSeeTableRecords([$debitDealer, $creditDealer]);

    $page->call('selectEmployee', $akash->id)
        ->assertSee(IndianCurrency::format(200000))
        ->assertSee(IndianCurrency::format(40000))
        ->assertSee(IndianCurrency::format(160000))
        ->assertCanSeeTableRecords([$debitDealer, $creditDealer]);

    $payload = app(DealerOutstandingService::class)->employeeExportPayload($akash->id);
    $creditRow = collect($payload['rows'])->firstWhere('dealer_name', 'Credit Opening Dealer');

    expect($payload['total'])->toBe(200000.0)
        ->and($payload['credit_total'])->toBe(40000.0)
        ->and($payload['net_total'])->toBe(160000.0)
        ->and($creditRow)->toMatchArray([
            'outstanding' => 0.0,
            'credit_balance' => 40000.0,
        ]);

    $html = view('filament.pages.employee-outstanding-pdf', [
        'companyName' => 'ParamGold ERP',
        'payload' => $payload,
        'generatedAt' => '22 Aug 2026, 08:00 PM',
    ])->render();

    expect($html)->toContain('Credit Balance')
        ->toContain('Credit Opening Dealer')
        ->toContain('Net Balance')
        ->not->toContain('-40,000');
});

it('uses the dealer ledger tally outstanding on the total outstanding page', function (): void {
    $director = outstandingPageDirector();
    $akash = outstandingPageEmployee('Akash Tally Outstanding', '9940000010');

    $dealer = outstandingPageDealer([
        'firm_name' => 'Avdhoot Agro Mart (Wadod bazar)',
        'village' => 'Wadod Bazar',
        'assigned_employee_id' => $akash->id,
        'opening_balance' => 49242,
        'opening_balance_type' => 'debit',
        'opening_balance_date' => '2026-04-01',
    ]);
    outstandingTallyAccount($dealer, 50000, 'debit', [
        ['debit' => 38888, 'credit' => 0, 'particulars' => 'Sales', 'voucher_type' => 'Sales', 'voucher_no' => 'SL-1'],
        ['debit' => 0, 'credit' => 10000, 'particulars' => 'Receipt', 'voucher_type' => 'Receipt', 'voucher_no' => 'RT-1'],
    ]);

    $ledgerSigned = app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer);
    $listed = app(DealerOutstandingService::class)
        ->assignedDealersQuery($akash->id)
        ->whereKey($dealer->id)
        ->value('current_outstanding');
    $summary = app(DealerOutstandingService::class)->summary($akash->id);
    $employeeRow = collect(app(DealerOutstandingService::class)->totalsByAssignedEmployee())
        ->firstWhere('employee_id', $akash->id);

    expect($ledgerSigned)->toBe(78888.0)
        ->and((float) $listed)->toBe(78888.0)
        ->and($summary)->toMatchArray([
            'outstanding' => 78888.0,
            'credit' => 0.0,
            'net' => 78888.0,
        ])
        ->and($employeeRow['total_outstanding'])->toBe(78888.0)
        ->and($employeeRow['total_credit'])->toBe(0.0);

    Livewire::actingAs($director)
        ->test(TotalOutstanding::class)
        ->assertSuccessful()
        ->assertSee(IndianCurrency::format(78888))
        ->assertDontSee(IndianCurrency::format(49242))
        ->call('selectEmployee', $akash->id)
        ->assertCanSeeTableRecords([$dealer])
        ->assertSee(IndianCurrency::format(78888))
        ->assertDontSee(IndianCurrency::format(49242));
});
