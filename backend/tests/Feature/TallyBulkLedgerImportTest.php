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

function bulkTallyFile(string $dealerName, string $filename): array
{
    return [
        'path' => tallyLedgerExcel(typicalTallyRows($dealerName)),
        'original_filename' => $filename,
    ];
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

it('previews each uploaded excel as one dealer ledger', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9822100101');
    $ganesh = ledgerDealer($employee, ['firm_name' => 'Shree Ganesh Traders']);
    ledgerDealer($employee, ['firm_name' => 'Balaji Agro']);

    $preview = app(TallyBulkLedgerImportService::class)->previewFiles([
        bulkTallyFile('Shree Ganesh Traders', 'ganesh.xlsx'),
        bulkTallyFile('Unknown Tally Party', 'unknown.xlsx'),
        bulkTallyFile('Balaji Agro', 'balaji.xlsx'),
    ], (int) $employee->id);

    $rows = collect($preview['rows']);

    expect($rows)->toHaveCount(3)
        ->and($rows[0]['file_name'])->toBe('ganesh.xlsx')
        ->and($rows[0]['detected_dealer'])->toBe('Shree Ganesh Traders')
        ->and($rows[0]['matched_dealer'])->toBe('Shree Ganesh Traders')
        ->and($rows[0]['status'])->toBe('Matched')
        ->and($rows[0]['dealer_id'])->toBe($ganesh->id)
        ->and($rows[1]['file_name'])->toBe('unknown.xlsx')
        ->and($rows[1]['detected_dealer'])->toBe('Unknown Tally Party')
        ->and($rows[1]['matched_dealer'])->toBe('—')
        ->and($rows[1]['status'])->toBe('Not Matched')
        ->and($rows[2]['status'])->toBe('Matched');
});

it('detects the excel ledger heading instead of salesman and matches assigned dealers', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9822100201');
    $otherEmployee = ledgerEmployee(UserRole::Employee, '9822100202');
    $assigned = ledgerDealer($employee, ['firm_name' => 'Adinath Krushi Seva Kendra (Wadgaon)']);
    ledgerDealer($otherEmployee, ['firm_name' => 'Adinath Krushi Seva Kendra (Wadgaon)']);

    $path = tallyLedgerExcel([
        ['PARAMGOLD AGRITECH PRIVATE LIMITED'],
        ['Reg. Office: Plot D-69, Five Star MIDC,'],
        ['Shendra, Aurangabad 431007'],
        [],
        ['Adinath Krushi Seva Kendra Wadgaon'],
        ['Ledger Account'],
        ['Salesman: SO Akash Nikam'],
        ['Group: Sundry Debtors'],
        ['1-Apr-26 to 31-Mar-27'],
        [],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['01-04-2026', 'Opening Balance', '', '', '50,000.00', ''],
        ['10-04-2026', 'Sales', 'Sales', 'SL-101', '25,000.00', ''],
        ['18-04-2026', 'Receipt', 'Receipt', 'RT-22', '', '10,000.00'],
        ['', 'Closing Balance', '', '', '', '65,000.00'],
        ['', 'Total', '', '', '75,000.00', '75,000.00'],
    ]);

    $preview = app(TallyBulkLedgerImportService::class)->previewFiles([
        [
            'path' => $path,
            'original_filename' => 'Adinath Krushi Seva Kendra Wadgaon.xlsx',
        ],
    ], (int) $employee->id);

    expect($preview['rows'])->toHaveCount(1)
        ->and($preview['rows'][0]['file_name'])->toBe('Adinath Krushi Seva Kendra Wadgaon.xlsx')
        ->and($preview['rows'][0]['detected_dealer'])->toBe('Adinath Krushi Seva Kendra Wadgaon')
        ->and($preview['rows'][0]['matched_dealer'])->toBe('Adinath Krushi Seva Kendra (Wadgaon)')
        ->and($preview['rows'][0]['status'])->toBe('Matched')
        ->and($preview['rows'][0]['dealer_id'])->toBe($assigned->id)
        ->and($preview['rows'][0]['detected_dealer'])->not->toContain('Salesman')
        ->and($preview['rows'][0]['detected_dealer'])->not->toContain('Akash');
});

it('uses the excel filename when the ledger heading is missing', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9822100203');
    $assigned = ledgerDealer($employee, ['firm_name' => 'Adinath Krushi Seva Kendra Wadgaon']);

    $path = tallyLedgerExcel([
        ['PARAMGOLD AGRITECH PRIVATE LIMITED'],
        ['Reg. Office: Plot D-69, Five Star MIDC,'],
        ['Ledger Account'],
        ['Salesman: SO Akash Nikam'],
        ['Group: Sundry Debtors'],
        ['1-Apr-26 to 31-Mar-27'],
        [],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['01-04-2026', 'Opening Balance', '', '', '50,000.00', ''],
        ['10-04-2026', 'Sales', 'Sales', 'SL-101', '25,000.00', ''],
        ['', 'Closing Balance', '', '', '', '75,000.00'],
        ['', 'Total', '', '', '75,000.00', '75,000.00'],
    ]);

    $preview = app(TallyBulkLedgerImportService::class)->previewFiles([
        [
            'path' => $path,
            'original_filename' => 'Adinath Krushi Seva Kendra Wadgaon.xlsx',
        ],
    ], (int) $employee->id);

    expect($preview['rows'][0]['detected_dealer'])->toBe('Adinath Krushi Seva Kendra Wadgaon')
        ->and($preview['rows'][0]['matched_dealer'])->toBe('Adinath Krushi Seva Kendra Wadgaon')
        ->and($preview['rows'][0]['status'])->toBe('Matched')
        ->and($preview['rows'][0]['dealer_id'])->toBe($assigned->id);
});

it('bulk imports matched files and skips unmatched and error files', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9822100001');
    $otherEmployee = ledgerEmployee(UserRole::Employee, '9822100002');
    $matched = ledgerDealer($employee, ['firm_name' => 'Shree Ganesh Traders']);
    $alsoMatched = ledgerDealer($employee, ['firm_name' => 'Balaji Agro']);
    $otherDealers = ledgerDealer($otherEmployee, ['firm_name' => 'Other Employee Dealer']);
    $admin = tallyImportAdmin();

    $mismatchRows = typicalTallyRows('Balaji Agro');
    $mismatchRows[count($mismatchRows) - 2] = ['', 'Closing Balance', '', '', '', '99,000.00'];

    $result = app(TallyBulkLedgerImportService::class)->importFiles([
        bulkTallyFile('Shree Ganesh Traders', 'ganesh.xlsx'),
        bulkTallyFile('Unknown Tally Party', 'unknown.xlsx'),
        [
            'path' => tallyLedgerExcel($mismatchRows),
            'original_filename' => 'balaji-error.xlsx',
        ],
        bulkTallyFile('Other Employee Dealer', 'other.xlsx'),
    ], (int) $employee->id, $admin);

    $rows = collect($result['rows'])->keyBy('file_name');

    expect($rows)->toHaveCount(4)
        ->and($rows['ganesh.xlsx']['status'])->toBe('Matched')
        ->and($rows['ganesh.xlsx']['import_status_label'])->toBe('Ledger Imported')
        ->and($rows['ganesh.xlsx']['imported_count'])->toBe(2)
        ->and($rows['unknown.xlsx']['status'])->toBe('Not Matched')
        ->and($rows['unknown.xlsx']['import_status_label'])->toBe('')
        ->and($rows['balaji-error.xlsx']['status'])->toBe('Error')
        ->and($rows['other.xlsx']['status'])->toBe('Not Matched')
        ->and($matched->fresh()->tallyLedgerImportStatusLabel())->toBe('Ledger Imported')
        ->and($alsoMatched->fresh()->tallyLedgerImportStatusLabel())->toBe('Not Imported')
        ->and($otherDealers->fresh()->tallyLedgerImportStatusLabel())->toBe('Not Imported')
        ->and(DealerTallyEntry::query()->where('dealer_id', $matched->id)->count())->toBe(2)
        ->and(DealerTallyEntry::query()->where('dealer_id', $alsoMatched->id)->count())->toBe(0)
        ->and(DealerTallyEntry::query()->where('dealer_id', $otherDealers->id)->count())->toBe(0)
        ->and(Dealer::query()->where('firm_name', 'Unknown Tally Party')->exists())->toBeFalse();
});

it('marks already imported dealers and does not import them again', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9822100003');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Repeat Agro']);
    $admin = tallyImportAdmin();
    $file = bulkTallyFile('Repeat Agro', 'repeat.xlsx');

    $first = app(TallyBulkLedgerImportService::class)->importFiles([$file], (int) $employee->id, $admin);
    $second = app(TallyBulkLedgerImportService::class)->importFiles([
        bulkTallyFile('Repeat Agro', 'repeat-again.xlsx'),
    ], (int) $employee->id, $admin);

    expect($first['rows'][0]['import_status_label'])->toBe('Ledger Imported')
        ->and($first['rows'][0]['imported_count'])->toBe(2)
        ->and($second['rows'][0]['status'])->toBe('Already Imported')
        ->and($second['rows'][0]['import_status_label'])->toBe('')
        ->and($second['rows'][0]['imported_count'])->toBe(0)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and($dealer->fresh()->tallyLedgerImportStatusLabel())->toBe('Ledger Imported');
});

it('does not mark a mismatched tally ledger as imported', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9822100004');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Mismatch Agro']);
    $admin = tallyImportAdmin();
    $rows = typicalTallyRows('Mismatch Agro');
    $rows[count($rows) - 2] = ['', 'Closing Balance', '', '', '', '99,000.00'];

    $result = app(TallyBulkLedgerImportService::class)->importFiles([
        [
            'path' => tallyLedgerExcel($rows),
            'original_filename' => 'mismatch.xlsx',
        ],
    ], (int) $employee->id, $admin);

    expect($result['rows'][0]['status'])->toBe('Error')
        ->and($result['rows'][0]['matched_dealer'])->toBe('Mismatch Agro')
        ->and($dealer->fresh()->tallyLedgerImportStatusLabel())->toBe('Not Imported')
        ->and(DealerTallyLedger::query()->where('dealer_id', $dealer->id)->exists())->toBeFalse()
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(0);
});

it('keeps import result columns aligned instead of mixing status and counts', function (): void {
    $admin = tallyImportAdmin();

    Livewire::actingAs($admin)
        ->test(BulkImportTallyLedger::class)
        ->set('step', 3)
        ->set('resultRows', [
            [
                'file_name' => 'AVDHOOT AGRO MART (WADOD BAZAR).xlsx',
                'detected_dealer' => 'AVDHOOT AGRO MART (WADOD BAZAR)',
                'matched_dealer' => 'Avdhoot Agro Mart(Wadod bazar)',
                'dealer_id' => 143,
                'dealer_code' => 'D143',
                'status' => 'Matched',
                'reason' => null,
                'tally_status' => 'Ledger Imported',
                'imported_count' => 6,
                'duplicate_count' => 0,
                'import_status_label' => 'Ledger Imported',
                'can_import' => false,
            ],
            [
                'file_name' => 'Bhakti krushi Seva Kendra.xlsx',
                'detected_dealer' => 'Bhakti krushi Seva Kendra',
                'matched_dealer' => '—',
                'dealer_id' => null,
                'dealer_code' => null,
                'status' => 'Not Matched',
                'reason' => 'No assigned dealer matches this Tally party.',
                'tally_status' => 'Not Imported',
                'imported_count' => 0,
                'duplicate_count' => 0,
                'import_status_label' => '',
                'can_import' => false,
            ],
        ])
        ->assertSee('File Name')
        ->assertSee('Ledger Status')
        ->assertSee('Open ledger')
        ->assertSeeHtml('erp-bulk-tally-rows--results')
        ->assertSeeHtml('erp-status-badge')
        ->assertSeeHtml('erp-col-num')
        ->assertDontSee('Matched Imported')
        ->assertSee('No assigned dealer matches this Tally party.');
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
        ->assertSee('Tally Ledger Excel files')
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
