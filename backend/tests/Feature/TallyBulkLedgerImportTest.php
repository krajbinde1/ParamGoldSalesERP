<?php

use App\Enums\UserRole;
use App\Filament\Resources\Dealers\Pages\BulkImportTallyLedger;
use App\Filament\Resources\Dealers\Pages\ListDealers;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\DealerTallyLedger;
use App\Services\TallyLedger\TallyBulkLedgerImportService;
use App\Services\TallyLedger\TallyLedgerExcelParser;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function stackedTallyLedgersExcel(array $dealerNames): string
{
    $rows = [];
    foreach (array_values($dealerNames) as $index => $name) {
        if ($index > 0) {
            $rows[] = [];
        }

        foreach (typicalTallyRows($name) as $row) {
            $rows[] = $row;
        }
    }

    return tallyLedgerExcel($rows);
}

function multiSheetTallyLedgersExcel(array $dealerNames): string
{
    $spreadsheet = new Spreadsheet;
    foreach (array_values($dealerNames) as $index => $name) {
        $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $sheet->setTitle('Ledger '.($index + 1));
        $sheet->fromArray(typicalTallyRows($name), null, 'A1');
    }

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tally-multi-'.uniqid('', true).'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

it('parses every stacked tally ledger without changing single-file parse', function (): void {
    $path = stackedTallyLedgersExcel(['Alpha Agro', 'Beta Traders']);

    $all = app(TallyLedgerExcelParser::class)->parseAll($path);
    $first = app(TallyLedgerExcelParser::class)->parse($path);

    expect($all)->toHaveCount(2)
        ->and($all[0]->tallyLedgerName)->toBe('Alpha Agro')
        ->and($all[1]->tallyLedgerName)->toBe('Beta Traders')
        ->and($all[0]->transactions)->toHaveCount(2)
        ->and($all[1]->transactions)->toHaveCount(2)
        ->and($all[0]->canImport())->toBeTrue()
        ->and($all[1]->canImport())->toBeTrue()
        ->and($first->tallyLedgerName)->toBe('Alpha Agro');
});

it('parses tally ledgers from every worksheet', function (): void {
    $path = multiSheetTallyLedgersExcel(['Sheet One Agro', 'Sheet Two Mart']);

    $all = app(TallyLedgerExcelParser::class)->parseAll($path);

    expect($all)->toHaveCount(2)
        ->and(collect($all)->pluck('tallyLedgerName')->all())->toBe(['Sheet One Agro', 'Sheet Two Mart']);
});

it('bulk imports matched assigned dealers and skips unmatched parties', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9822100001');
    $otherEmployee = ledgerEmployee(UserRole::Employee, '9822100002');
    $matched = ledgerDealer($employee, ['firm_name' => 'Shree Ganesh Traders']);
    $alsoMatched = ledgerDealer($employee, ['firm_name' => 'Balaji Agro']);
    $otherDealers = ledgerDealer($otherEmployee, ['firm_name' => 'Other Employee Dealer']);
    $admin = tallyImportAdmin();
    $path = stackedTallyLedgersExcel([
        'Shree Ganesh Traders',
        'Unknown Tally Party',
        'Balaji Agro',
        'Other Employee Dealer',
    ]);

    $result = app(TallyBulkLedgerImportService::class)->import($path, (int) $employee->id, $admin, 'bulk.xlsx');
    $rows = collect($result['rows'])->keyBy('tally_ledger_name');

    expect($rows)->toHaveCount(4)
        ->and($rows['Shree Ganesh Traders']['matched'])->toBeTrue()
        ->and($rows['Shree Ganesh Traders']['import_status_label'])->toBe('Ledger Imported')
        ->and($rows['Shree Ganesh Traders']['imported_count'])->toBe(2)
        ->and($rows['Shree Ganesh Traders']['closing_balance_label'])->toBe('₹65,000.00 Dr')
        ->and($rows['Balaji Agro']['matched'])->toBeTrue()
        ->and($rows['Balaji Agro']['import_status_label'])->toBe('Ledger Imported')
        ->and($rows['Unknown Tally Party']['matched'])->toBeFalse()
        ->and($rows['Unknown Tally Party']['match_label'])->toBe('Not Matched')
        ->and($rows['Unknown Tally Party']['import_status_label'])->toBe('Not Imported')
        ->and($rows['Other Employee Dealer']['matched'])->toBeFalse()
        ->and($rows['Other Employee Dealer']['import_status_label'])->toBe('Not Imported')
        ->and($matched->fresh()->tallyLedgerImportStatusLabel())->toBe('Ledger Imported')
        ->and($alsoMatched->fresh()->tallyLedgerImportStatusLabel())->toBe('Ledger Imported')
        ->and($otherDealers->fresh()->tallyLedgerImportStatusLabel())->toBe('Not Imported')
        ->and(DealerTallyEntry::query()->where('dealer_id', $matched->id)->count())->toBe(2)
        ->and(DealerTallyEntry::query()->where('dealer_id', $alsoMatched->id)->count())->toBe(2)
        ->and(DealerTallyEntry::query()->where('dealer_id', $otherDealers->id)->count())->toBe(0)
        ->and(Dealer::query()->where('firm_name', 'Unknown Tally Party')->exists())->toBeFalse();
});

it('skips duplicate transactions when the same bulk file is imported again', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9822100003');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Repeat Agro']);
    $admin = tallyImportAdmin();
    $path = stackedTallyLedgersExcel(['Repeat Agro']);

    $first = app(TallyBulkLedgerImportService::class)->import($path, (int) $employee->id, $admin, 'bulk.xlsx');
    $second = app(TallyBulkLedgerImportService::class)->import($path, (int) $employee->id, $admin, 'bulk.xlsx');

    expect($first['rows'][0]['imported_count'])->toBe(2)
        ->and($second['rows'][0]['imported_count'])->toBe(0)
        ->and($second['rows'][0]['duplicate_count'])->toBe(2)
        ->and($second['rows'][0]['import_status_label'])->toBe('Ledger Imported')
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and($dealer->fresh()->tallyLedgerImportStatusLabel())->toBe('Ledger Imported');
});

it('does not mark a mismatched tally ledger as imported', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9822100004');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Mismatch Agro']);
    $admin = tallyImportAdmin();
    $rows = typicalTallyRows('Mismatch Agro');
    $rows[count($rows) - 2] = ['', 'Closing Balance', '', '', '', '99,000.00'];
    $path = tallyLedgerExcel($rows);

    $result = app(TallyBulkLedgerImportService::class)->import($path, (int) $employee->id, $admin, 'mismatch.xlsx');

    expect($result['rows'][0]['matched'])->toBeTrue()
        ->and($result['rows'][0]['import_status_label'])->toBe('Failed')
        ->and($dealer->fresh()->tallyLedgerImportStatusLabel())->toBe('Not Imported')
        ->and(DealerTallyLedger::query()->where('dealer_id', $dealer->id)->exists())->toBeFalse()
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(0);
});

it('lists only the selected employee dealers on the bulk import page', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9822100005');
    $otherEmployee = ledgerEmployee(UserRole::Employee, '9822100006');
    $assigned = ledgerDealer($employee, ['firm_name' => 'Assigned Visible Dealer']);
    $hidden = ledgerDealer($otherEmployee, ['firm_name' => 'Other Employee Hidden Dealer']);
    $admin = tallyImportAdmin();

    Livewire::actingAs($admin)
        ->test(BulkImportTallyLedger::class)
        ->assertSuccessful()
        ->assertSee('Assigned Employee')
        ->assertDontSee('Assigned Visible Dealer')
        ->assertDontSee('Other Employee Hidden Dealer')
        ->fillForm(['employee_id' => $employee->id])
        ->assertSee('Assigned Visible Dealer')
        ->assertDontSee('Other Employee Hidden Dealer')
        ->assertSee($assigned->dealer_code)
        ->assertSee('Not Imported');

    expect($hidden->fresh()->firm_name)->toBe('Other Employee Hidden Dealer');
});

it('shows bulk tally ledger import on the dealers list', function (): void {
    Livewire::actingAs(tallyImportAdmin())
        ->test(ListDealers::class)
        ->assertSuccessful()
        ->assertActionVisible('bulkImportTallyLedger')
        ->assertActionVisible('tallyImportHistory');
});
