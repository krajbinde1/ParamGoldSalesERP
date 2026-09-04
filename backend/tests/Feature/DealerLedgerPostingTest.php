<?php

use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use App\Services\Dashboard\DirectorDashboardDataService;
use App\Services\Dealers\DealerLedgerPostingService;
use App\Services\Dealers\DealerOutstandingService;
use App\Services\Dealers\DealerSalesLedgerReconciler;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Services\TallyLedger\TallyLedgerConfig;
use App\Services\TallyLedger\TallyLedgerImportService;

it('skips tally import rows that match an existing erp debit or credit on the same date', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199001');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Shree Ganesh Traders']);
    $admin = tallyImportAdmin();

    $order = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 25000,
        'order_no' => 'ORD-ERP-1',
        'dispatch_date' => '2026-04-10',
        'dispatched_at' => '2026-04-10 10:00:00',
    ]);
    ledgerCollection($dealer, $employee, [
        'amount' => 10000,
        'status' => Collection::STATUS_RECEIVED,
        'collection_date' => '2026-04-18',
        'receipt_no' => 'RCP-ERP-1',
    ]);

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2);
    $collectionBefore = DealerTallyEntry::query()
        ->where('dealer_id', $dealer->id)
        ->where('source', DealerTallyEntry::SOURCE_COLLECTION)
        ->first();

    $excel = tallyLedgerExcel(typicalTallyRows($dealer->firm_name));
    $result = app(TallyLedgerImportService::class)->import(
        $excel,
        (int) $dealer->id,
        $admin,
        'ganesh.xlsx',
    );

    $salesEntry = DealerTallyEntry::query()
        ->where('dealer_id', $dealer->id)
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->first();
    $collectionEntry = DealerTallyEntry::query()
        ->where('dealer_id', $dealer->id)
        ->where('source', DealerTallyEntry::SOURCE_COLLECTION)
        ->first();

    expect($result['imported_count'])->toBe(0)
        ->and($result['reconciled_count'])->toBe(1)
        ->and($result['duplicate_count'])->toBe(1)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and($salesEntry)->not->toBeNull()
        ->and((int) $salesEntry->source_id)->toBe($order->id)
        ->and($salesEntry->voucher_no)->toBe('SL-101')
        ->and($salesEntry->tally_voucher_no)->toBe('SL-101')
        ->and($salesEntry->particulars)->toBe('Sales')
        ->and($salesEntry->tally_reconciled_at)->not->toBeNull()
        ->and($collectionEntry?->id)->toBe($collectionBefore?->id)
        ->and($collectionEntry?->voucher_no)->toBe($collectionBefore?->voucher_no)
        ->and($collectionEntry?->particulars)->toBe('Payment Received / Collection')
        ->and($collectionEntry?->tally_reconciled_at)->toBeNull()
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_TALLY_IMPORT)->count())->toBe(0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(65000.0);

    $again = app(TallyLedgerImportService::class)->import(
        tallyLedgerExcel(typicalTallyRows($dealer->firm_name)),
        (int) $dealer->id,
        $admin,
        'ganesh-again.xlsx',
    );

    expect($again['imported_count'])->toBe(0)
        ->and($again['duplicate_count'])->toBe(2)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_TALLY_IMPORT)->count())->toBe(0);
});

it('does not treat opposite debit and credit sides as tally duplicates', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199002');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Opposite Side Dealer']);
    $admin = tallyImportAdmin();

    ledgerCollection($dealer, $employee, [
        'amount' => 25000,
        'status' => Collection::STATUS_RECEIVED,
        'collection_date' => '2026-04-10',
        'receipt_no' => 'RCP-CREDIT-SAME-DATE',
    ]);

    $result = app(TallyLedgerImportService::class)->import(
        tallyLedgerExcel(typicalTallyRows($dealer->firm_name)),
        (int) $dealer->id,
        $admin,
        'opposite.xlsx',
    );

    expect($result['imported_count'])->toBe(2)
        ->and($result['duplicate_count'])->toBe(0)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(3);
});

it('backfills dispatched orders and received collections without duplicating date amount and side', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199003');
    $dealer = ledgerDealer($employee);

    $order = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 40000,
        'dispatch_date' => '2026-05-01',
        'dispatched_at' => '2026-05-01 09:00:00',
    ]);
    $collection = ledgerCollection($dealer, $employee, [
        'amount' => 15000,
        'status' => Collection::STATUS_RECEIVED,
        'collection_date' => '2026-05-02',
    ]);

    DealerTallyEntry::query()->where('dealer_id', $dealer->id)->delete();

    $first = app(DealerLedgerPostingService::class)->backfill();
    expect($first['orders'])->toBe(1)
        ->and($first['collections'])->toBe(1)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)->where('source_id', $order->id)->count())->toBe(1)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->count())->toBe(1);

    $second = app(DealerLedgerPostingService::class)->backfill();
    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and($second['orders'])->toBe(1)
        ->and($second['collections'])->toBe(1);
});

it('uses posted ledger balances for employee outstanding and dashboard total', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199004');
    $dealer = ledgerDealer($employee, [
        'assigned_employee_id' => $employee->id,
        'opening_balance' => 49242,
    ]);

    ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 78888,
        'dispatch_date' => '2026-08-20',
        'dispatched_at' => '2026-08-20 11:00:00',
    ]);

    $ledgerSigned = app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh());
    $page = app(DealerOutstandingService::class)->summary($employee->id);
    $dashboard = app(DirectorDashboardDataService::class)->snapshot();

    expect($ledgerSigned)->toBe(78888.0)
        ->and($page['outstanding'])->toBe(78888.0)
        ->and((float) $dashboard['total_outstanding'])->toBe(78888.0);
});

it('merges a tally sales bill into the matching erp sales order debit', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199005');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Balaji Agro Traders (Bharadi)']);
    $admin = tallyImportAdmin();

    $order = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 97249,
        'order_no' => 'PG-20260824-0016',
        'dispatch_date' => '2026-08-24',
        'dispatched_at' => '2026-08-24 16:00:00',
    ]);

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(1)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(97249.0);

    $path = tallyLedgerExcel([
        ['Ledger : Balaji Agro Traders (Bharadi)'],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['25-08-2026', 'Sales @5%', 'Sales', 'PG/26-27/0465', '97,249.00', ''],
        ['', 'Closing Balance', '', '', '', '97,249.00'],
        ['', 'Total', '', '', '97,249.00', '97,249.00'],
    ]);

    $result = app(TallyLedgerImportService::class)->import($path, (int) $dealer->id, $admin, 'balaji-bill.xlsx');
    $entry = DealerTallyEntry::query()->where('dealer_id', $dealer->id)->first();

    expect($result['imported_count'])->toBe(0)
        ->and($result['reconciled_count'])->toBe(1)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(1)
        ->and($entry?->source)->toBe(DealerTallyEntry::SOURCE_SALES_ORDER)
        ->and((int) $entry?->source_id)->toBe($order->id)
        ->and($entry?->voucher_no)->toBe('PG/26-27/0465')
        ->and($entry?->tally_voucher_no)->toBe('PG/26-27/0465')
        ->and($entry?->voucher_type)->toBe('Sales')
        ->and($entry?->particulars)->toBe('Sales @5%')
        ->and($entry?->entry_date?->toDateString())->toBe('2026-08-25')
        ->and($entry?->tally_entry_date?->toDateString())->toBe('2026-08-25')
        ->and($entry?->tally_reconciled_at)->not->toBeNull()
        ->and((float) $entry?->debit)->toBe(97249.0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(97249.0);
});

it('does not merge a tally sales bill into an unrelated same-amount collection or credit', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199006');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Same Amount Other Side']);
    $admin = tallyImportAdmin();

    ledgerCollection($dealer, $employee, [
        'amount' => 97249,
        'status' => Collection::STATUS_RECEIVED,
        'collection_date' => '2026-08-24',
        'receipt_no' => 'RCP-UNRELATED',
    ]);
    $collectionBefore = DealerTallyEntry::query()
        ->where('dealer_id', $dealer->id)
        ->where('source', DealerTallyEntry::SOURCE_COLLECTION)
        ->first();

    $path = tallyLedgerExcel([
        ['Ledger : Same Amount Other Side'],
        ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
        ['25-08-2026', 'Sales @5%', 'Sales', 'PG/26-27/0465', '97,249.00', ''],
        ['', 'Closing Balance', '', '', '', '97,249.00'],
        ['', 'Total', '', '', '97,249.00', '97,249.00'],
    ]);

    $result = app(TallyLedgerImportService::class)->import($path, (int) $dealer->id, $admin, 'unrelated.xlsx');
    $collection = DealerTallyEntry::query()
        ->where('dealer_id', $dealer->id)
        ->where('source', DealerTallyEntry::SOURCE_COLLECTION)
        ->first();

    expect($result['imported_count'])->toBe(1)
        ->and($result['reconciled_count'])->toBe(0)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and($collection?->id)->toBe($collectionBefore?->id)
        ->and($collection?->voucher_no)->toBe($collectionBefore?->voucher_no)
        ->and($collection?->particulars)->toBe('Payment Received / Collection')
        ->and($collection?->tally_reconciled_at)->toBeNull()
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_TALLY_IMPORT)->where('voucher_no', 'PG/26-27/0465')->exists())->toBeTrue();
});

it('reconciles existing duplicate sales order and tally sales rows so outstanding is not doubled', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199007');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Already Duplicated Dealer']);

    $order = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 97249,
        'order_no' => 'PG-20260824-0016',
        'dispatch_date' => '2026-08-24',
        'dispatched_at' => '2026-08-24 16:00:00',
    ]);

    DealerTallyEntry::query()->create([
        'dealer_id' => $dealer->id,
        'import_id' => null,
        'entry_date' => '2026-08-25',
        'particulars' => 'Sales @5%',
        'voucher_type' => 'Sales',
        'voucher_no' => 'PG/26-27/0465',
        'debit' => 97249,
        'credit' => 0,
        'source' => TallyLedgerConfig::SOURCE,
        'fingerprint' => DealerTallyEntry::makeFingerprint(
            dealerId: (int) $dealer->id,
            date: '2026-08-25',
            voucherType: 'Sales',
            voucherNo: 'PG/26-27/0465',
            debit: 97249,
            credit: 0,
            particulars: 'Sales @5%',
        ),
        'source_row' => 3,
    ]);

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(194498.0);

    $reconciled = app(DealerSalesLedgerReconciler::class)->reconcileExistingDuplicates($dealer);
    $entry = DealerTallyEntry::query()->where('dealer_id', $dealer->id)->first();

    expect($reconciled)->toBe(1)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(1)
        ->and($entry?->source)->toBe(DealerTallyEntry::SOURCE_SALES_ORDER)
        ->and((int) $entry?->source_id)->toBe($order->id)
        ->and($entry?->voucher_no)->toBe('PG/26-27/0465')
        ->and($entry?->particulars)->toBe('Sales @5%')
        ->and($entry?->tally_reconciled_at)->not->toBeNull()
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(97249.0);
});

it('attaches a later dispatched sales order to an already imported tally sales bill', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199008');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Tally First Dealer']);
    $admin = tallyImportAdmin();

    app(TallyLedgerImportService::class)->import(
        tallyLedgerExcel([
            ['Ledger : Tally First Dealer'],
            ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
            ['25-08-2026', 'Sales @5%', 'Sales', 'PG/26-27/0465', '97,249.00', ''],
            ['', 'Closing Balance', '', '', '', '97,249.00'],
            ['', 'Total', '', '', '97,249.00', '97,249.00'],
        ]),
        (int) $dealer->id,
        $admin,
        'tally-first.xlsx',
    );

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(1);

    $order = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 97249,
        'order_no' => 'PG-20260824-0016',
        'dispatch_date' => '2026-08-24',
        'dispatched_at' => '2026-08-24 16:00:00',
    ]);

    $entry = DealerTallyEntry::query()->where('dealer_id', $dealer->id)->first();

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(1)
        ->and($entry?->source)->toBe(DealerTallyEntry::SOURCE_SALES_ORDER)
        ->and((int) $entry?->source_id)->toBe($order->id)
        ->and($entry?->voucher_no)->toBe('PG/26-27/0465')
        ->and($entry?->tally_reconciled_at)->not->toBeNull()
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(97249.0);
});

it('matches the sales order whose date is uniquely closest to the tally bill', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199009');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Ambiguous Amount Dealer']);
    $admin = tallyImportAdmin();

    $closer = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 97249,
        'order_no' => 'PG-20260824-0016',
        'order_date' => '2026-08-24',
        'dispatch_date' => '2026-08-24',
        'dispatched_at' => '2026-08-24 10:00:00',
    ]);
    ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 97249,
        'order_no' => 'PG-20260824-0017',
        'order_date' => '2026-08-28',
        'dispatch_date' => '2026-08-28',
        'dispatched_at' => '2026-08-28 10:00:00',
    ]);

    $result = app(TallyLedgerImportService::class)->import(
        tallyLedgerExcel([
            ['Ledger : Ambiguous Amount Dealer'],
            ['Date', 'Particulars', 'Vch Type', 'Vch No.', 'Debit', 'Credit'],
            ['24-08-2026', 'Sales @5%', 'Sales', 'PG/26-27/0465', '97,249.00', ''],
            ['', 'Closing Balance', '', '', '', '97,249.00'],
            ['', 'Total', '', '', '97,249.00', '97,249.00'],
        ]),
        (int) $dealer->id,
        $admin,
        'closest.xlsx',
    );

    $matched = DealerTallyEntry::query()
        ->where('dealer_id', $dealer->id)
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->whereNotNull('tally_reconciled_at')
        ->first();

    expect($result['imported_count'])->toBe(0)
        ->and($result['reconciled_count'])->toBe(1)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_TALLY_IMPORT)->count())->toBe(0)
        ->and((int) $matched?->source_id)->toBe($closer->id)
        ->and($matched?->voucher_no)->toBe('PG/26-27/0465')
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(194498.0);
});

it('posts billed and dispatched sales order debits on the order date linked to the order id', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199010');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Amrut Fertilizers Purna']);

    $billed = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_BILLED,
        'grand_total' => 12000,
        'order_no' => 'PG-20260830-0002',
        'order_date' => '2026-08-30',
        'bill_date' => '2026-08-30',
        'billed_at' => '2026-08-30 11:00:00',
    ]);

    $dispatched = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 84525,
        'order_no' => 'PG-20260831-0001',
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-09-03',
        'dispatched_at' => '2026-09-03 16:00:00',
    ]);

    $billedEntry = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $billed->id)
        ->first();
    $dispatchedEntry = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $dispatched->id)
        ->first();

    expect($billedEntry)->not->toBeNull()
        ->and($billedEntry?->entry_date?->toDateString())->toBe('2026-08-30')
        ->and((float) $billedEntry?->debit)->toBe(12000.0)
        ->and($dispatchedEntry)->not->toBeNull()
        ->and($dispatchedEntry?->entry_date?->toDateString())->toBe('2026-08-31')
        ->and((float) $dispatchedEntry?->debit)->toBe(84525.0)
        ->and($dispatchedEntry?->voucher_no)->toBe('PG-20260831-0001')
        ->and($dispatchedEntry?->particulars)->toBe('Sales Order PG-0001')
        ->and($dispatchedEntry?->source)->toBe(DealerTallyEntry::SOURCE_SALES_ORDER)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(96525.0);

    $again = app(DealerLedgerPostingService::class)->syncDispatchedOrder($dispatched->fresh());
    expect($again?->id)->toBe($dispatchedEntry?->id)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2);
});

it('does not skip a sales order debit because another same-date same-amount debit already exists', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199011');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Amrut Fertilizers Purna']);

    $first = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 84525,
        'order_no' => 'PG-20260831-0008',
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-08-31',
        'dispatched_at' => '2026-08-31 10:00:00',
    ]);
    $second = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 84525,
        'order_no' => 'PG-20260831-0001',
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-09-03',
        'dispatched_at' => '2026-09-03 16:00:00',
    ]);

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)->where('source_id', $first->id)->count())->toBe(1)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)->where('source_id', $second->id)->count())->toBe(1)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(169050.0);
});

it('backfills billed orders that were never posted by a status-change event', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199012');
    $dealer = ledgerDealer($employee);

    $order = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_BILLED,
        'grand_total' => 84525,
        'order_no' => 'PG-20260831-0001',
        'order_date' => '2026-08-31',
        'bill_date' => '2026-08-31',
        'billed_at' => '2026-08-31 18:00:00',
    ]);

    DealerTallyEntry::query()->where('dealer_id', $dealer->id)->delete();
    expect(DealerTallyEntry::query()->where('source_id', $order->id)->count())->toBe(0);

    $result = app(DealerLedgerPostingService::class)->backfill();
    $entry = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $order->id)
        ->first();

    expect($result['orders'])->toBe(1)
        ->and($entry)->not->toBeNull()
        ->and($entry?->entry_date?->toDateString())->toBe('2026-08-31')
        ->and((float) $entry?->debit)->toBe(84525.0)
        ->and(app(TallyDealerLedgerService::class)->signedCurrentOutstanding($dealer->fresh()))->toBe(84525.0);

    $second = app(DealerLedgerPostingService::class)->backfill();
    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(1)
        ->and($second['orders'])->toBe(1);
});

it('does not treat a different-amount ledger row as this order even when source_id was wrongly linked', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199013');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Amrut Fertilizers Purna']);

    $large = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 2271675,
        'order_no' => 'PG-20260820-0015',
        'order_date' => '2026-08-20',
        'dispatch_date' => '2026-08-20',
        'dispatched_at' => '2026-08-20 10:00:00',
    ]);
    $missing = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 84525,
        'order_no' => 'PG-20260831-0001',
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-09-03',
        'dispatched_at' => '2026-09-03 16:00:00',
    ]);

    $largeEntry = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $large->id)
        ->first();
    $missingEntry = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $missing->id)
        ->first();

    expect($largeEntry)->not->toBeNull()
        ->and($missingEntry)->not->toBeNull();

    $missingEntry->delete();
    $largeEntry->fill([
        'source_id' => $missing->id,
        'fingerprint' => DealerTallyEntry::makeSourceFingerprint(
            DealerTallyEntry::SOURCE_SALES_ORDER,
            (int) $missing->id,
        ),
        'voucher_no' => 'PG-0015',
        'tally_voucher_no' => 'PG-0015',
        'tally_reconciled_at' => now('Asia/Kolkata'),
    ])->save();

    expect(DealerTallyEntry::query()->where('source_id', $missing->id)->count())->toBe(1)
        ->and((float) DealerTallyEntry::query()->where('source_id', $missing->id)->value('debit'))->toBe(2271675.0);

    $posted = app(DealerLedgerPostingService::class)->syncDispatchedOrder($missing->fresh());
    $largeAfter = $largeEntry->fresh();
    $missingAfter = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $missing->id)
        ->whereRaw('ABS(COALESCE(debit, 0) - 84525) < 0.005')
        ->first();

    expect($posted)->not->toBeNull()
        ->and($posted?->id)->not->toBe($largeEntry->id)
        ->and((float) $posted?->debit)->toBe(84525.0)
        ->and($posted?->entry_date?->toDateString())->toBe('2026-08-31')
        ->and($posted?->particulars)->toBe('Sales Order PG-0001')
        ->and((float) $largeAfter?->debit)->toBe(2271675.0)
        ->and($largeAfter?->voucher_no)->toBe('PG-0015')
        ->and((int) $largeAfter?->source_id)->toBe($large->id)
        ->and($missingAfter?->id)->toBe($posted?->id)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2);
});

it('backfills a dispatched order that was skipped because a larger tally debit stole its source_id', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199014');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Amrut Fertilizers Purna']);

    $missing = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 84525,
        'order_no' => 'PG-20260831-0001',
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-09-03',
        'dispatched_at' => '2026-09-03 16:00:00',
    ]);

    DealerTallyEntry::query()->where('source_id', $missing->id)->delete();

    $tally = DealerTallyEntry::query()->create([
        'dealer_id' => $dealer->id,
        'import_id' => null,
        'entry_date' => '2026-08-20',
        'particulars' => 'Sales @5%',
        'voucher_type' => 'Sales',
        'voucher_no' => 'PG-0015',
        'tally_voucher_no' => 'PG-0015',
        'tally_voucher_type' => 'Sales',
        'tally_entry_date' => '2026-08-20',
        'tally_reconciled_at' => now('Asia/Kolkata'),
        'debit' => 2271675,
        'credit' => 0,
        'source' => DealerTallyEntry::SOURCE_SALES_ORDER,
        'source_id' => $missing->id,
        'fingerprint' => DealerTallyEntry::makeSourceFingerprint(
            DealerTallyEntry::SOURCE_SALES_ORDER,
            (int) $missing->id,
        ),
        'source_row' => 4,
    ]);

    $result = app(DealerLedgerPostingService::class)->backfill();
    $erp = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $missing->id)
        ->first();
    $tallyAfter = $tally->fresh();

    expect($result['orders'])->toBeGreaterThanOrEqual(1)
        ->and($erp)->not->toBeNull()
        ->and($erp?->id)->not->toBe($tally->id)
        ->and((float) $erp?->debit)->toBe(84525.0)
        ->and($erp?->entry_date?->toDateString())->toBe('2026-08-31')
        ->and((float) $tallyAfter?->debit)->toBe(2271675.0)
        ->and($tallyAfter?->voucher_no)->toBe('PG-0015')
        ->and($tallyAfter?->source_id)->toBeNull()
        ->and($tallyAfter?->source)->toBe(TallyLedgerConfig::SOURCE);
});

it('does not attach a unique tally sales bill whose voucher is a different order number', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199015');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Amrut Fertilizers Purna']);

    DealerTallyEntry::query()->create([
        'dealer_id' => $dealer->id,
        'entry_date' => '2026-08-31',
        'particulars' => 'Sales @5%',
        'voucher_type' => 'Sales',
        'voucher_no' => 'PG-0015',
        'debit' => 84525,
        'credit' => 0,
        'source' => TallyLedgerConfig::SOURCE,
        'fingerprint' => DealerTallyEntry::makeFingerprint(
            dealerId: (int) $dealer->id,
            date: '2026-08-31',
            voucherType: 'Sales',
            voucherNo: 'PG-0015',
            debit: 84525,
            credit: 0,
            particulars: 'Sales @5%',
        ),
        'source_row' => 3,
    ]);

    $order = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 84525,
        'order_no' => 'PG-20260831-0001',
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-08-31',
        'dispatched_at' => '2026-08-31 16:00:00',
    ]);

    $erp = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $order->id)
        ->first();
    $tally = DealerTallyEntry::query()
        ->where('dealer_id', $dealer->id)
        ->where('voucher_no', 'PG-0015')
        ->first();

    expect($erp)->not->toBeNull()
        ->and($erp?->id)->not->toBe($tally?->id)
        ->and((float) $erp?->debit)->toBe(84525.0)
        ->and($tally?->source)->toBe(TallyLedgerConfig::SOURCE)
        ->and($tally?->source_id)->toBeNull()
        ->and((float) $tally?->debit)->toBe(84525.0)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2);
});

it('does not overwrite a mislinked PG-0015 row whose voucher is a full ERP order number', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199016');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Amrut Fertilizers Purna']);

    $large = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 2271675,
        'order_no' => 'PG-20260820-0015',
        'order_date' => '2026-08-20',
        'dispatch_date' => '2026-08-20',
        'dispatched_at' => '2026-08-20 10:00:00',
    ]);
    $missing = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 84525,
        'order_no' => 'PG-20260831-0001',
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-08-31',
        'dispatched_at' => '2026-08-31 16:00:00',
    ]);

    DealerTallyEntry::query()->where('source_id', $missing->id)->delete();
    $largeEntry = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $large->id)
        ->first();
    $largeEntry->fill([
        'source_id' => $missing->id,
        'fingerprint' => DealerTallyEntry::makeSourceFingerprint(
            DealerTallyEntry::SOURCE_SALES_ORDER,
            (int) $missing->id,
        ),
        'voucher_no' => 'PG-20260820-0015',
        'tally_voucher_no' => null,
        'tally_reconciled_at' => null,
        'debit' => 2271675,
    ])->save();

    $diagnosis = app(DealerLedgerPostingService::class)->diagnoseSalesOrder($missing->fresh());
    $posted = app(DealerLedgerPostingService::class)->syncDispatchedOrder($missing->fresh());
    $largeAfter = $largeEntry->fresh();

    expect($diagnosis['action'])->toBe('post')
        ->and($diagnosis['skip_reason'])->toContain('incorrectly linked')
        ->and($posted)->not->toBeNull()
        ->and((float) $posted?->debit)->toBe(84525.0)
        ->and($posted?->entry_date?->toDateString())->toBe('2026-08-31')
        ->and((float) $largeAfter?->debit)->toBe(2271675.0)
        ->and($largeAfter?->voucher_no)->toBe('PG-20260820-0015');
});

it('diagnoses and posts only PG-0001 from the backfill command without bulk writing', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199017');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Amrut Fertilizers Purna']);

    $other = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 12000,
        'order_no' => 'PG-20260801-0099',
        'order_date' => '2026-08-01',
        'dispatch_date' => '2026-08-01',
        'dispatched_at' => '2026-08-01 10:00:00',
    ]);
    $missing = ledgerOrder($dealer, $employee, [
        'status' => Order::STATUS_DISPATCHED,
        'grand_total' => 84525,
        'order_no' => 'PG-20260831-0001',
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-08-31',
        'dispatched_at' => '2026-08-31 16:00:00',
    ]);

    DealerTallyEntry::query()->where('source_id', $other->id)->delete();
    DealerTallyEntry::query()->where('source_id', $missing->id)->delete();
    DealerTallyEntry::query()->create([
        'dealer_id' => $dealer->id,
        'entry_date' => '2026-08-24',
        'particulars' => 'Sales @5%',
        'voucher_type' => 'Sales',
        'voucher_no' => 'PG-0015',
        'tally_voucher_no' => 'PG-0015',
        'tally_reconciled_at' => now('Asia/Kolkata'),
        'debit' => 2271675,
        'credit' => 0,
        'source' => DealerTallyEntry::SOURCE_SALES_ORDER,
        'source_id' => $missing->id,
        'fingerprint' => DealerTallyEntry::makeSourceFingerprint(
            DealerTallyEntry::SOURCE_SALES_ORDER,
            (int) $missing->id,
        ),
        'source_row' => 4,
    ]);

    $this->artisan('ledger:backfill-erp-entries', ['--order' => 'PG-0001'])
        ->expectsOutputToContain('incorrectly linked')
        ->assertSuccessful();

    $erp = DealerTallyEntry::query()
        ->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)
        ->where('source_id', $missing->id)
        ->whereRaw('ABS(COALESCE(debit, 0) - 84525) < 0.005')
        ->first();
    $stolen = DealerTallyEntry::query()
        ->where('dealer_id', $dealer->id)
        ->whereRaw('ABS(COALESCE(debit, 0) - 2271675) < 0.005')
        ->first();
    $otherEntry = DealerTallyEntry::query()
        ->where('source_id', $other->id)
        ->first();

    expect($erp)->not->toBeNull()
        ->and($erp?->entry_date?->toDateString())->toBe('2026-08-31')
        ->and($erp?->particulars)->toBe('Sales Order PG-0001')
        ->and((float) $stolen?->debit)->toBe(2271675.0)
        ->and($stolen?->voucher_no)->toBe('PG-0015')
        ->and($otherEntry)->toBeNull();
});
