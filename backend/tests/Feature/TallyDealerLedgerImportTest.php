<?php

use App\Enums\UserRole;
use App\Filament\Resources\Dealers\Pages\ImportTallyLedger;
use App\Filament\Resources\Dealers\Pages\ListTallyLedgerImports;
use App\Filament\Resources\Dealers\Pages\ViewDealerLedger;
use App\Models\Dealer;
use App\Models\DealerTallyEntry;
use App\Models\DealerTallyImport;
use App\Models\DealerTallyLedger;
use App\Models\Order;
use App\Models\User;
use App\Services\Dealers\DealerLedgerService;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Services\TallyLedger\TallyLedgerExcelParser;
use App\Services\TallyLedger\TallyLedgerImportService;
use App\Support\IndianCurrency;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function tallyLedgerExcel(array $rows): string
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tally-'.uniqid('', true).'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function tallyStyleExportExcel(string $ledgerName, array $entries, string $period = '1-Apr-26 to 31-Aug-26'): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'PARAMGOLD AGRITECH PRIVATE LIMITED');
    $sheet->setCellValue('A2', 'Reg. Office: Plot D-69, Five Star MIDC,');
    $sheet->setCellValue('A3', 'Shendra, Aurangabad 431007');
    $sheet->setCellValue('A4', 'CIN: U01400MH2019PTC328676');
    $sheet->setCellValue('A6', $ledgerName);
    $sheet->setCellValue('A7', 'Ledger Account');
    $sheet->setCellValue('A11', $period);
    $sheet->fromArray(['Date', 'Particulars', null, 'Vch Type', 'Vch No.', 'Debit', 'Credit'], null, 'A12');

    $excelRow = 13;
    foreach ($entries as $entry) {
        if (isset($entry['total_debit'])) {
            $sheet->setCellValue('A'.$excelRow, $entry['total_debit']);
            $sheet->getStyle('A'.$excelRow)->getNumberFormat()->setFormatCode('0.00');
            if (isset($entry['total_credit'])) {
                $sheet->setCellValue('G'.$excelRow, $entry['total_credit']);
                $sheet->getStyle('G'.$excelRow)->getNumberFormat()->setFormatCode('0.00');
            }
            $excelRow++;

            continue;
        }

        if (isset($entry['closing'])) {
            $sheet->setCellValue('B'.$excelRow, 'By');
            $sheet->setCellValue('C'.$excelRow, 'Closing Balance');
            $sheet->setCellValue('G'.$excelRow, $entry['closing']);
            $sheet->getStyle('G'.$excelRow)->getNumberFormat()->setFormatCode('0.00');
            $excelRow++;

            continue;
        }

        if (! empty($entry['date'])) {
            $sheet->setCellValue('A'.$excelRow, ExcelDate::PHPToExcel(new DateTimeImmutable($entry['date'])));
            $sheet->getStyle('A'.$excelRow)->getNumberFormat()->setFormatCode('d-mmm-yy');
        }

        $sheet->setCellValue('B'.$excelRow, $entry['marker'] ?? '');
        $sheet->setCellValue('C'.$excelRow, $entry['particulars'] ?? '');
        $sheet->setCellValue('D'.$excelRow, $entry['voucher_type'] ?? '');
        $voucherNo = $entry['voucher_no'] ?? '';
        if ($voucherNo !== '' && is_numeric($voucherNo)) {
            $sheet->setCellValue('E'.$excelRow, $voucherNo + 0);
        } else {
            $sheet->setCellValue('E'.$excelRow, $voucherNo);
        }

        if (($entry['debit'] ?? 0) > 0) {
            $sheet->setCellValue('F'.$excelRow, $entry['debit']);
            $sheet->getStyle('F'.$excelRow)->getNumberFormat()->setFormatCode('0.00');
        }

        if (($entry['credit'] ?? 0) > 0) {
            $sheet->setCellValue('G'.$excelRow, $entry['credit']);
            $sheet->getStyle('G'.$excelRow)->getNumberFormat()->setFormatCode('0.00');
        }

        $excelRow++;
    }

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tally-style-'.uniqid('', true).'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

function bajarangTallyExportPath(): string
{
    return tallyStyleExportExcel('New Bajarang Agro Services (Bazar Sawangi )', [
        ['date' => '2025-05-25', 'marker' => 'To', 'particulars' => 'Sale Gst', 'voucher_type' => 'Sales', 'voucher_no' => 'PG/25-26/216', 'debit' => 49750.26],
        ['date' => '2025-06-02', 'marker' => 'To', 'particulars' => 'Sale Gst', 'voucher_type' => 'Sales', 'voucher_no' => 'PG/25-26/275', 'debit' => 56000.80],
        ['date' => '2025-06-08', 'marker' => 'To', 'particulars' => 'Sale Gst', 'voucher_type' => 'Sales', 'voucher_no' => 'PG/25-26/305', 'debit' => 27050.40],
        ['date' => '2025-08-07', 'marker' => 'To', 'particulars' => 'Sale Gst', 'voucher_type' => 'Sales', 'voucher_no' => 'PG/25-26/676', 'debit' => 56805.00],
        ['date' => '2025-08-14', 'marker' => 'By', 'particulars' => 'State Bank of India', 'voucher_type' => 'Receipt', 'voucher_no' => 286, 'credit' => 100000.00],
        ['date' => '2025-08-31', 'marker' => 'To', 'particulars' => 'Sale Gst', 'voucher_type' => 'Sales', 'voucher_no' => 'PG/25-26/832', 'debit' => 15532.00],
        ['date' => '2025-11-21', 'marker' => 'By', 'particulars' => 'Sales _Return', 'voucher_type' => 'Credit Note', 'voucher_no' => 66, 'credit' => 26166.00],
        ['date' => '2025-12-06', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales', 'voucher_no' => 'PG/25-26/1090', 'debit' => 11000.00],
        ['date' => '2026-02-28', 'marker' => 'By', 'particulars' => 'State Bank of India', 'voucher_type' => 'Receipt', 'voucher_no' => 59, 'credit' => 50000.00],
        ['date' => '2026-03-27', 'marker' => 'By', 'particulars' => 'State Bank of India', 'voucher_type' => 'Receipt', 'voucher_no' => 161, 'credit' => 37000.00],
        ['date' => '2026-03-31', 'marker' => 'By', 'particulars' => 'Input CGST @2.5%', 'voucher_type' => 'Credit Note', 'voucher_no' => 72, 'credit' => 2972.46],
        ['date' => '2026-05-26', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales', 'voucher_no' => 'PG/26-27/064', 'debit' => 17000.00],
        ['date' => '2026-08-11', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales 26-27', 'voucher_no' => 'PG/26-27/0407', 'debit' => 11475.00],
        ['total_debit' => 244613.46, 'total_credit' => 216138.46],
        ['closing' => 28475.00],
        ['total_debit' => 244613.46, 'total_credit' => 244613.46],
    ], '1-Apr-25 to 31-Aug-26');
}

function balajiTallyExportPath(): string
{
    return tallyStyleExportExcel('Balaji Agro Traders (Bharadi)', [
        ['date' => '2026-04-01', 'marker' => 'By', 'particulars' => 'Opening Balance', 'debit' => 10481.26],
        ['date' => '2026-05-19', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales', 'voucher_no' => 'PG/26-27/046', 'debit' => 87413.00],
        ['date' => '2026-05-28', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales', 'voucher_no' => 'PG/26-27/083', 'debit' => 103920.00],
        ['date' => '2026-06-20', 'marker' => 'By', 'particulars' => 'State Bank of India', 'voucher_type' => 'Receipt', 'voucher_no' => 349, 'credit' => 100000.00],
        ['date' => '2026-06-24', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales 26-27', 'voucher_no' => 'PG/26-27/0202', 'debit' => 119770.00],
        ['date' => '2026-07-04', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales 26-27', 'voucher_no' => 'PG/26-27/0234', 'debit' => 22453.00],
        ['date' => '2026-07-10', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales 26-27', 'voucher_no' => 'PG/26-27/0259', 'debit' => 10693.00],
        ['date' => '2026-07-10', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales 26-27', 'voucher_no' => 'PG/26-27/0261', 'debit' => 47474.00],
        ['date' => '2026-07-20', 'marker' => 'To', 'particulars' => 'Sale Gst', 'voucher_type' => 'Sales 26-27', 'voucher_no' => 'PG/26-27/0294', 'debit' => 87218.00],
        ['date' => '2026-07-26', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales 26-27', 'voucher_no' => 'PG/26-27/0340', 'debit' => 5515.02],
        ['date' => '2026-07-29', 'marker' => 'By', 'particulars' => 'State Bank of India', 'voucher_type' => 'Receipt', 'voucher_no' => 442, 'credit' => 50000.00],
        ['date' => '2026-08-14', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales 26-27', 'voucher_no' => 'PG/26-27/0429', 'debit' => 63562.00],
        ['date' => '2026-08-15', 'marker' => 'By', 'particulars' => 'Sales_Return', 'voucher_type' => 'Credit Note', 'voucher_no' => 119, 'credit' => 38724.00],
        ['total_debit' => 63153.64, 'total_credit' => 188724.00],
        ['total_debit' => 558499.28, 'total_credit' => 188724.00],
        ['closing' => 369775.28],
        ['total_debit' => 558499.28, 'total_credit' => 558499.28],
    ]);
}

function typicalTallyRows(string $dealerName = 'Shree Ganesh Traders'): array
{
    return [
        ['ParamGold Agro Industries'],
        ['Aurangabad, Maharashtra'],
        ['GSTIN: 27AAAAA0000A1Z5'],
        [],
        ['Ledger : '.$dealerName],
        ['1-Apr-26 to 31-Mar-27'],
        [],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['01-04-2026', 'Opening Balance', '', '', '50,000.00', ''],
        ['10-04-2026', 'Sales', 'Sales', 'SL-101', '25,000.00', ''],
        ['18-04-2026', 'Receipt', 'Receipt', 'RT-22', '', '10,000.00'],
        ['20-03-2026', 'Old year sale', 'Sales', 'SL-OLD', '9,999.00', ''],
        ['', 'Closing Balance', '', '', '', '65,000.00'],
        ['', 'Total', '', '', '75,000.00', '75,000.00'],
    ];
}

function tallyImportAdmin(): User
{
    return User::query()->create([
        'name' => 'Tally Admin',
        'email' => 'tally.admin.'.uniqid().'@example.com',
        'password' => 'password',
        'role' => UserRole::Employee->value,
        'job_role' => 'Admin',
    ]);
}

it('formats ledger amounts with paise and dr/cr', function (): void {
    expect(IndianCurrency::formatExact(125000))->toBe('₹1,25,000.00')
        ->and(IndianCurrency::formatDrCr(28475))->toBe('₹28,475.00 Dr')
        ->and(IndianCurrency::formatDrCr(-28475))->toBe('₹28,475.00 Cr')
        ->and(IndianCurrency::formatDrCr(0))->toBe('₹0.00');
});

it('parses a tally excel regardless of company header rows and skips totals', function (): void {
    $path = tallyLedgerExcel(typicalTallyRows());
    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect($parsed->tallyLedgerName)->toBe('Shree Ganesh Traders')
        ->and($parsed->openingBalanceExplicit)->toBeTrue()
        ->and($parsed->openingBalance)->toBe(50000.0)
        ->and($parsed->openingBalanceType)->toBe('debit')
        ->and($parsed->transactions)->toHaveCount(2)
        ->and($parsed->totalDebit)->toBe(25000.0)
        ->and($parsed->totalCredit)->toBe(10000.0)
        ->and($parsed->skippedBeforeStartDate)->toBe(1)
        ->and($parsed->tallyClosingBalance)->toBe(65000.0)
        ->and($parsed->tallyClosingBalanceType)->toBe('debit')
        ->and($parsed->calculatedClosingSigned())->toBe(65000.0);
});

it('does not import opening, closing, or total rows as transactions', function (): void {
    $path = tallyLedgerExcel(typicalTallyRows());
    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect(collect($parsed->transactions)->pluck('particulars')->all())
        ->not->toContain('Opening Balance')
        ->not->toContain('Closing Balance')
        ->not->toContain('Total')
        ->and($parsed->failed)->toBe([]);
});

it('uses zero opening when tally does not show an opening balance', function (): void {
    $path = tallyLedgerExcel([
        ['Ledger : Zero Open Dealer'],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['05-04-2026', 'Sales', 'Sales', '1', '1000', ''],
        ['', 'Closing Balance', '', '', '', '1000'],
    ]);
    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect($parsed->openingBalanceExplicit)->toBeFalse()
        ->and($parsed->openingBalance)->toBe(0.0)
        ->and($parsed->openingBalanceType)->toBe('debit');
});

it('treats tally debit and credit columns as authoritative even for sales', function (): void {
    $path = tallyLedgerExcel([
        ['Ledger : Credit Note Dealer'],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['06-04-2026', 'Sales return', 'Sales', 'CN-1', '', '1500'],
    ]);
    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect($parsed->transactions[0]['debit'])->toBe(0.0)
        ->and($parsed->transactions[0]['credit'])->toBe(1500.0)
        ->and($parsed->transactions[0]['voucher_type'])->toBe('Sales');
});

it('imports into an existing dealer and ignores the old erp opening balance', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100091');
    $dealer = ledgerDealer($employee, [
        'firm_name' => 'Shree Ganesh Traders',
        'opening_balance' => 999999,
        'opening_balance_type' => 'debit',
    ]);
    $admin = tallyImportAdmin();
    $path = tallyLedgerExcel(typicalTallyRows($dealer->firm_name));

    $result = app(TallyLedgerImportService::class)->import($path, (int) $dealer->id, $admin, 'ganesh.xlsx');
    $statement = $result['summary'];

    expect($dealer->fresh()->opening_balance)->toBe('999999.00')
        ->and(app(DealerLedgerService::class)->getOpeningBalance($dealer->fresh()))->toBe(999999.0)
        ->and($statement['opening_balance'])->toBe(50000.0)
        ->and($statement['current_outstanding_signed'])->toBe(65000.0)
        ->and($statement['current_outstanding_label'])->toBe('₹65,000.00 Dr')
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and($result['imported_count'])->toBe(2)
        ->and($result['duplicate_count'])->toBe(0)
        ->and($result['import']->balance_matched)->toBeTrue();

    $again = app(TallyLedgerImportService::class)->import($path, (int) $dealer->id, $admin, 'ganesh.xlsx');
    expect($again['imported_count'])->toBe(0)
        ->and($again['duplicate_count'])->toBe(2)
        ->and($again['summary']['current_outstanding_signed'])->toBe(65000.0)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and(Dealer::query()->count())->toBe(1);
});

it('imports the uploaded ledger into the selected erp dealer even when tally names differ', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100092');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Existing ERP Dealer']);
    $admin = tallyImportAdmin();
    $path = tallyLedgerExcel(typicalTallyRows('Unknown Tally Party'));

    $preview = app(TallyLedgerImportService::class)->preview($path, $dealer);
    expect($preview['names_differ'])->toBeTrue()
        ->and($preview['tally_ledger_name'])->toBe('Unknown Tally Party');

    $result = app(TallyLedgerImportService::class)->import($path, (int) $dealer->id, $admin, 'unknown.xlsx');
    expect($result['dealer']->id)->toBe($dealer->id)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and(Dealer::query()->where('firm_name', 'Unknown Tally Party')->exists())->toBeFalse();
});

it('does not create a dealer from a tally ledger', function (): void {
    $path = tallyLedgerExcel(typicalTallyRows('Brand New Party From Tally'));
    $preview = app(TallyLedgerImportService::class)->preview($path);

    expect($preview['tally_ledger_name'])->toBe('Brand New Party From Tally')
        ->and(Dealer::query()->where('firm_name', 'Brand New Party From Tally')->exists())->toBeFalse();
});

it('shows the tally ledger on the dealer ledger page instead of billed orders', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100093');
    $dealer = ledgerDealer($employee, [
        'firm_name' => 'Ledger Screen Dealer',
        'opening_balance' => 100000,
    ]);
    ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_BILLED,
        'grand_total' => 50000,
        'bill_number' => 'BILL-TALLY',
        'bill_date' => '2026-04-15',
        'billed_at' => '2026-04-15 11:00:00',
    ]);
    $admin = tallyImportAdmin();
    $path = tallyLedgerExcel(typicalTallyRows($dealer->firm_name));
    app(TallyLedgerImportService::class)->import($path, (int) $dealer->id, $admin, 'screen.xlsx');

    Livewire::actingAs($admin)
        ->test(ViewDealerLedger::class, ['record' => $dealer->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('₹65,000.00 Dr')
        ->assertSee('₹50,000.00 Dr')
        ->assertDontSee('Sales Invoice / Order Bill');
});

it('lets admin open the tally import page for a selected dealer', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100098');
    $dealer = ledgerDealer($employee, [
        'firm_name' => 'New Bajarang Agro Services (Bazar Sawangi)',
        'village' => 'Bazar Sawangi',
        'district' => 'Chhatrapati Sambhajinagar',
    ]);

    Livewire::actingAs(tallyImportAdmin())
        ->test(ImportTallyLedger::class, ['record' => $dealer->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Selected ERP Dealer')
        ->assertSee($dealer->dealer_code)
        ->assertSee('New Bajarang Agro Services (Bazar Sawangi)')
        ->assertSee('Bazar Sawangi')
        ->assertSee('Chhatrapati Sambhajinagar')
        ->assertDontSee('Search dealer code, firm name, or village');
});

it('lets admin open tally import history', function (): void {
    Livewire::actingAs(tallyImportAdmin())
        ->test(ListTallyLedgerImports::class)
        ->assertSuccessful();
});

it('detects tally headers with aliases, two-row headers, and dates like 11-Aug-26', function (): void {
    $path = tallyLedgerExcel([
        ['ParamGold Agro Industries'],
        ['Gut No. 12, Aurangabad, Maharashtra'],
        ['GSTIN: 27AAAAA0000A1Z5'],
        [],
        ['New Bajarang Agro Services (Bazar Sawangi)'],
        ['1-Apr-26 to 31-Mar-27'],
        [],
        ['Date', 'Particular', 'Vch Type', 'Vch No', '', ''],
        ['', '', '', '', 'Dr', 'Cr'],
        ['25-May-25', 'Old year sale', 'Sales', 'SL-OLD', '9,999.00', ''],
        ['01-Apr-26', 'Opening Balance', '', '', '12,000.00', ''],
        ['02-Jun-26', 'Sales', 'Sales', 'SL-102', '8,000.00', ''],
        ['11-Aug-26', 'Receipt', 'Receipt', 'RT-9', '', '3,000.00'],
        ['', 'Closing Balance', '', '', '', '17,000.00'],
        ['', 'Total', '', '', '20,000.00', '20,000.00'],
    ]);

    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect($parsed->tallyLedgerName)->toBe('New Bajarang Agro Services (Bazar Sawangi)')
        ->and($parsed->openingBalance)->toBe(12000.0)
        ->and($parsed->openingBalanceType)->toBe('debit')
        ->and($parsed->transactions)->toHaveCount(2)
        ->and($parsed->transactions[0]['date'])->toBe('2026-06-02')
        ->and($parsed->transactions[1]['date'])->toBe('2026-08-11')
        ->and($parsed->skippedBeforeStartDate)->toBe(1)
        ->and($parsed->transactions[0]['debit'])->toBe(8000.0)
        ->and($parsed->transactions[1]['credit'])->toBe(3000.0);
});

it('reads a tally header below company rows and merged cells', function (): void {
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'ParamGold Agro Industries');
    $sheet->mergeCells('A1:F1');
    $sheet->setCellValue('A2', 'Aurangabad, Maharashtra');
    $sheet->setCellValue('A5', 'Ledger : New Bajarang Agro Services (Bazar Sawangi)');
    $sheet->mergeCells('A5:F5');
    $sheet->setCellValue('A6', '01-Apr-26 to 31-Mar-27');

    $sheet->setCellValue('A10', 'Date');
    $sheet->setCellValue('B10', 'Particulars');
    $sheet->mergeCells('B10:C10');
    $sheet->setCellValue('D10', 'Voucher Type');
    $sheet->setCellValue('E10', 'Voucher No.');
    $sheet->setCellValue('F10', 'Debit Amount');
    $sheet->setCellValue('G10', 'Credit Amount');

    $sheet->fromArray([
        ['01-Apr-26', 'Opening Balance', '', '', '', '4,500.00', ''],
        ['11-Aug-26', 'Sales', '', 'Sales', 'SL-200', '1,500.00', ''],
        ['', 'Closing Balance', '', '', '', '', '6,000.00'],
    ], null, 'A11');

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tally-merged-'.uniqid('', true).'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect($parsed->tallyLedgerName)->toBe('New Bajarang Agro Services (Bazar Sawangi)')
        ->and($parsed->openingBalance)->toBe(4500.0)
        ->and($parsed->transactions)->toHaveCount(1)
        ->and($parsed->transactions[0]['date'])->toBe('2026-08-11')
        ->and($parsed->transactions[0]['voucher_type'])->toBe('Sales')
        ->and($parsed->transactions[0]['voucher_no'])->toBe('SL-200')
        ->and($parsed->transactions[0]['debit'])->toBe(1500.0);
});

it('rejects impossible tally dates such as 21 Sep 2569', function (): void {
    $path = tallyLedgerExcel([
        ['Ledger : Date Check Dealer'],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['21 Sep 2569', 'Bad year', 'Sales', 'SL-BAD', '500', ''],
        ['11-Aug-26', 'Sales', 'Sales', 'SL-OK', '1500', ''],
        ['', 'Closing Balance', '', '', '', '1500'],
        ['', 'Total', '', '', '2000', '2000'],
    ]);

    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect($parsed->transactions)->toHaveCount(1)
        ->and($parsed->transactions[0]['date'])->toBe('2026-08-11')
        ->and($parsed->failed)->toHaveCount(1)
        ->and($parsed->failed[0]['reason'])->toBe('Invalid or missing date.')
        ->and(collect($parsed->transactions)->pluck('date'))->not->toContain('2569-09-21');
});

it('uses zero opening and does not invent it from older tally rows', function (): void {
    $path = tallyLedgerExcel([
        ['Ledger : Historic Rows Dealer'],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['20-03-2026', 'Old year sale', 'Sales', 'SL-OLD', '9,999.00', ''],
        ['05-04-2026', 'Sales @5%', 'Sales', 'SL-1', '1,000.00', ''],
        ['', 'Closing Balance', '', '', '', '1,000.00'],
        ['', 'Total', '', '', '10,999.00', '10,999.00'],
    ]);
    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect($parsed->openingBalanceExplicit)->toBeFalse()
        ->and($parsed->openingBalance)->toBe(0.0)
        ->and($parsed->transactions)->toHaveCount(1)
        ->and($parsed->transactions[0]['particulars'])->toBe('Sales @5%')
        ->and($parsed->skippedBeforeStartDate)->toBe(1)
        ->and($parsed->failed)->toBe([]);
});

it('keeps actual tally particulars and ignores to/by, totals, and continuation rows', function (): void {
    $path = tallyLedgerExcel([
        ['Ledger : Particulars Dealer'],
        ['Date', 'Particulars', '', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['01-04-2026', 'Opening Balance', '', '', '', '50,000.00', ''],
        ['10-04-2026', 'To', 'Sales @5%', 'Sales', 'SL-101', '25,000.00', ''],
        ['', 'Sales @5%', '', '', '', '', ''],
        ['12-04-2026', 'By State Bank of India', '', 'Receipt', 'RT-22', '', '10,000.00'],
        ['', 'State Bank of India', '', '', '', '', ''],
        ['15-04-2026', 'To', 'Sales_Return', 'Credit Note', 'CN-1', '', '2,000.00'],
        ['16-04-2026', 'To Input CGST @2.5%', '', 'Journal', 'JV-9', '500.00', ''],
        ['', 'By', 'Closing Balance', '', '', '', '63,500.00'],
        ['', 'Total', '', '', '', '75,500.00', '75,500.00'],
    ]);
    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect($parsed->openingBalanceExplicit)->toBeTrue()
        ->and($parsed->openingBalance)->toBe(50000.0)
        ->and($parsed->openingBalanceType)->toBe('debit')
        ->and($parsed->tallyClosingBalance)->toBe(63500.0)
        ->and($parsed->tallyClosingBalanceType)->toBe('debit')
        ->and($parsed->failed)->toBe([])
        ->and(collect($parsed->transactions)->pluck('particulars')->all())->toBe([
            'Sales @5%',
            'State Bank of India',
            'Sales_Return',
            'Input CGST @2.5%',
        ])
        ->and(collect($parsed->transactions)->pluck('particulars')->all())
        ->not->toContain('To')
        ->not->toContain('By');
});

it('warns when the tally ledger name differs from the selected erp dealer and still imports into that dealer', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100096');
    $dealer = ledgerDealer($employee, [
        'firm_name' => 'New Bajarang Agro Services (Bazar Sawangi)',
        'village' => 'Bazar Sawangi',
    ]);
    $admin = tallyImportAdmin();
    $path = tallyLedgerExcel(typicalTallyRows('Some Other Tally Party'));

    $preview = app(TallyLedgerImportService::class)->preview($path, $dealer);
    expect($preview['names_differ'])->toBeTrue()
        ->and($preview['tally_ledger_name'])->toBe('Some Other Tally Party');

    $sameName = app(TallyLedgerImportService::class)->preview(
        tallyLedgerExcel(typicalTallyRows($dealer->firm_name)),
        $dealer,
    );
    expect($sameName['names_differ'])->toBeFalse();

    $result = app(TallyLedgerImportService::class)->import($path, (int) $dealer->id, $admin, 'locked-dealer.xlsx');
    expect($result['dealer']->id)->toBe($dealer->id)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2);
});

it('stores actual particulars from a to/by split tally row', function (): void {
    $path = tallyLedgerExcel([
        ['Ledger : Split To By Dealer'],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['26-May-26', 'To', 'Sales @5%', 'Sales', 'PG/26-27/064', '17000'],
        ['27-May-26', 'By', 'State Bank of India', 'Receipt', 'RT-9', '5000'],
        ['28-May-26', 'To', 'Sales_Return', 'Credit Note', 'CN-4', '2000'],
        ['29-May-26', 'To', 'Input CGST @2.5%', 'Journal', 'JV-8', '250'],
    ]);
    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect($parsed->failed)->toBe([])
        ->and(collect($parsed->transactions)->pluck('particulars')->all())->toBe([
            'Sales @5%',
            'State Bank of India',
            'Sales_Return',
            'Input CGST @2.5%',
        ])
        ->and($parsed->transactions[0]['voucher_type'])->toBe('Sales')
        ->and($parsed->transactions[0]['voucher_no'])->toBe('PG/26-27/064')
        ->and($parsed->transactions[0]['debit'])->toBe(17000.0)
        ->and($parsed->transactions[1]['voucher_type'])->toBe('Receipt')
        ->and($parsed->transactions[1]['credit'])->toBe(5000.0);
});

it('resets tally ledger data for the selected dealer only and allows re-import', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100101');
    $dealer = ledgerDealer($employee, [
        'firm_name' => 'Reset Target Dealer',
        'opening_balance' => 888888,
        'opening_balance_type' => 'debit',
    ]);
    $other = ledgerDealer($employee, ['firm_name' => 'Other Tally Dealer']);
    $order = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_BILLED,
        'grand_total' => 12000,
        'bill_number' => 'BILL-RESET',
        'bill_date' => '2026-04-15',
        'billed_at' => '2026-04-15 11:00:00',
    ]);
    $collection = ledgerCollection($dealer, $employee);
    $admin = tallyImportAdmin();

    app(TallyLedgerImportService::class)->import(
        tallyLedgerExcel(typicalTallyRows($dealer->firm_name)),
        (int) $dealer->id,
        $admin,
        'reset-target.xlsx',
    );
    app(TallyLedgerImportService::class)->import(
        tallyLedgerExcel(typicalTallyRows($other->firm_name)),
        (int) $other->id,
        $admin,
        'other-dealer.xlsx',
    );

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and(DealerTallyEntry::query()->where('dealer_id', $other->id)->count())->toBe(2);

    Livewire::actingAs($admin)
        ->test(ViewDealerLedger::class, ['record' => $dealer->getRouteKey()])
        ->assertSee('Reset Tally Ledger')
        ->assertActionVisible('resetTallyLedger')
        ->callAction('resetTallyLedger')
        ->assertNotified();

    $dealer->refresh();
    $statement = app(TallyDealerLedgerService::class)->statement($dealer);

    expect(Dealer::query()->whereKey($dealer->id)->exists())->toBeTrue()
        ->and($dealer->opening_balance)->toBe('888888.00')
        ->and(Order::query()->whereKey($order->id)->exists())->toBeTrue()
        ->and($collection->fresh())->not->toBeNull()
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(0)
        ->and(DealerTallyImport::query()->where('dealer_id', $dealer->id)->count())->toBe(0)
        ->and(DealerTallyLedger::query()->where('dealer_id', $dealer->id)->exists())->toBeFalse()
        ->and(DealerTallyEntry::query()->where('dealer_id', $other->id)->count())->toBe(2)
        ->and(DealerTallyLedger::query()->where('dealer_id', $other->id)->exists())->toBeTrue()
        ->and($statement['summary']['opening_balance'])->toBe(0.0)
        ->and($statement['summary']['current_outstanding_signed'])->toBe(0.0)
        ->and($statement['summary']['has_tally_ledger'])->toBeFalse()
        ->and(collect($statement['ledger'])->where('is_opening', false))->toHaveCount(0);

    $again = app(TallyLedgerImportService::class)->import(
        tallyLedgerExcel(typicalTallyRows($dealer->firm_name)),
        (int) $dealer->id,
        $admin,
        'reset-target.xlsx',
    );

    expect($again['imported_count'])->toBe(2)
        ->and($again['duplicate_count'])->toBe(0)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2);

    $duplicate = app(TallyLedgerImportService::class)->import(
        tallyLedgerExcel(typicalTallyRows($dealer->firm_name)),
        (int) $dealer->id,
        $admin,
        'reset-target.xlsx',
    );

    expect($duplicate['imported_count'])->toBe(0)
        ->and($duplicate['duplicate_count'])->toBe(2)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2);
});

it('parses the New Bajarang Agro Services tally layout without turning totals into dates', function (): void {
    $parsed = app(TallyLedgerExcelParser::class)->parse(bajarangTallyExportPath());

    expect($parsed->tallyLedgerName)->toBe('New Bajarang Agro Services (Bazar Sawangi )')
        ->and($parsed->openingBalanceExplicit)->toBeFalse()
        ->and($parsed->openingBalance)->toBe(0.0)
        ->and($parsed->failed)->toBe([])
        ->and($parsed->transactions)->toHaveCount(2)
        ->and(collect($parsed->transactions)->pluck('date')->all())->toBe(['2026-05-26', '2026-08-11'])
        ->and(collect($parsed->transactions)->pluck('particulars')->all())->toBe(['Sales @5%', 'Sales @5%'])
        ->and(collect($parsed->transactions)->pluck('particulars')->all())->not->toContain('To')->not->toContain('By')
        ->and($parsed->transactions[0]['voucher_type'])->toBe('Sales')
        ->and($parsed->transactions[0]['voucher_no'])->toBe('PG/26-27/064')
        ->and($parsed->transactions[0]['debit'])->toBe(17000.0)
        ->and($parsed->transactions[1]['voucher_type'])->toBe('Sales 26-27')
        ->and($parsed->transactions[1]['debit'])->toBe(11475.0)
        ->and($parsed->totalDebit)->toBe(28475.0)
        ->and($parsed->totalCredit)->toBe(0.0)
        ->and($parsed->tallyClosingBalance)->toBe(28475.0)
        ->and($parsed->tallyClosingBalanceType)->toBe('debit')
        ->and($parsed->calculatedClosingSigned())->toBe(28475.0)
        ->and($parsed->canImport())->toBeTrue()
        ->and(collect($parsed->transactions)->pluck('date')->all())->not->toContain('2072-11-25');
});

it('parses the Balaji Agro Traders tally layout with opening, to/by, and numeric totals', function (): void {
    $parsed = app(TallyLedgerExcelParser::class)->parse(balajiTallyExportPath());

    expect($parsed->tallyLedgerName)->toBe('Balaji Agro Traders (Bharadi)')
        ->and($parsed->openingBalanceExplicit)->toBeTrue()
        ->and($parsed->openingBalance)->toBe(10481.26)
        ->and($parsed->openingBalanceType)->toBe('debit')
        ->and($parsed->failed)->toBe([])
        ->and($parsed->canImport())->toBeTrue()
        ->and($parsed->transactions)->toHaveCount(12)
        ->and(collect($parsed->transactions)->pluck('date')->all())->not->toContain('2072-11-25')
        ->and(collect($parsed->transactions)->pluck('particulars')->all())->toBe([
            'Sales @5%',
            'Sales @5%',
            'State Bank of India',
            'Sales @5%',
            'Sales @5%',
            'Sales @5%',
            'Sales @5%',
            'Sale Gst',
            'Sales @5%',
            'State Bank of India',
            'Sales @5%',
            'Sales_Return',
        ])
        ->and(collect($parsed->transactions)->pluck('particulars')->all())
        ->not->toContain('To')
        ->not->toContain('By')
        ->not->toContain('Opening Balance')
        ->not->toContain('Closing Balance')
        ->not->toContain('Total')
        ->and($parsed->transactions[0]['date'])->toBe('2026-05-19')
        ->and($parsed->transactions[0]['voucher_type'])->toBe('Sales')
        ->and($parsed->transactions[0]['voucher_no'])->toBe('PG/26-27/046')
        ->and($parsed->transactions[0]['debit'])->toBe(87413.0)
        ->and($parsed->transactions[2]['date'])->toBe('2026-06-20')
        ->and($parsed->transactions[2]['voucher_type'])->toBe('Receipt')
        ->and($parsed->transactions[2]['voucher_no'])->toBe('349')
        ->and($parsed->transactions[2]['credit'])->toBe(100000.0)
        ->and($parsed->transactions[11]['date'])->toBe('2026-08-15')
        ->and($parsed->transactions[11]['voucher_type'])->toBe('Credit Note')
        ->and($parsed->transactions[11]['voucher_no'])->toBe('119')
        ->and($parsed->transactions[11]['credit'])->toBe(38724.0)
        ->and($parsed->totalDebit)->toBe(548018.02)
        ->and($parsed->totalCredit)->toBe(188724.0)
        ->and($parsed->inclusiveTotalDebit())->toBe(558499.28)
        ->and($parsed->inclusiveTotalCredit())->toBe(188724.0)
        ->and($parsed->tallyClosingBalance)->toBe(369775.28)
        ->and($parsed->tallyClosingBalanceType)->toBe('debit')
        ->and($parsed->calculatedClosingSigned())->toBe(369775.28)
        ->and($parsed->tallyClosingMatches())->toBeTrue();

    $employee = ledgerEmployee(UserRole::Employee, '9811100201');
    $dealer = ledgerDealer($employee, [
        'firm_name' => 'Balaji Agro Traders (Bharadi)',
        'opening_balance' => 63153.64,
        'opening_balance_type' => 'debit',
    ]);
    $preview = app(TallyLedgerImportService::class)->preview(balajiTallyExportPath(), $dealer);

    expect($preview['can_import'])->toBeTrue()
        ->and($preview['opening_balance'])->toBe(10481.26)
        ->and($preview['total_debit'])->toBe(558499.28)
        ->and($preview['total_credit'])->toBe(188724.0)
        ->and($preview['erp_closing_signed'])->toBe(369775.28)
        ->and($preview['tally_closing_balance'])->toBe(369775.28)
        ->and($preview['difference'])->toBe(0.0)
        ->and($preview['balance_matched'])->toBeTrue()
        ->and($preview['parse_errors'])->toBe([]);
});

it('never treats numeric tally totals as excel dates or transactions', function (): void {
    $path = tallyStyleExportExcel('Numeric Total Dealer', [
        ['date' => '2026-05-19', 'marker' => 'To', 'particulars' => 'Sales @5%', 'voucher_type' => 'Sales', 'voucher_no' => 'PG/26-27/046', 'debit' => 1000.00],
        ['total_debit' => 63153.64, 'total_credit' => 1000.00],
        ['closing' => 1000.00],
        ['total_debit' => 1000.00, 'total_credit' => 1000.00],
    ]);
    $parsed = app(TallyLedgerExcelParser::class)->parse($path);

    expect($parsed->transactions)->toHaveCount(1)
        ->and($parsed->transactions[0]['date'])->toBe('2026-05-19')
        ->and($parsed->transactions[0]['particulars'])->toBe('Sales @5%')
        ->and(collect($parsed->transactions)->pluck('date')->all())->not->toContain('2072-11-25')
        ->and(collect($parsed->transactions)->pluck('particulars')->all())->not->toContain('63153.64')
        ->and($parsed->failed)->toBe([]);
});

it('blocks confirm and import when parsed closing does not match tally', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811100202');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Mismatch Dealer']);
    $path = tallyLedgerExcel([
        ['Ledger : Mismatch Dealer'],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['10-04-2026', 'Sales @5%', 'Sales', 'SL-1', '1000', ''],
        ['', 'Closing Balance', '', '', '', '9999'],
    ]);
    $preview = app(TallyLedgerImportService::class)->preview($path, $dealer);

    expect($preview['can_import'])->toBeFalse()
        ->and($preview['balance_matched'])->toBeFalse()
        ->and($preview['parse_errors'])->not->toBeEmpty();

    expect(fn () => app(TallyLedgerImportService::class)->import($path, (int) $dealer->id, tallyImportAdmin(), 'mismatch.xlsx'))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
