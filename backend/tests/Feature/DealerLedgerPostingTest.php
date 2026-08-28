<?php

use App\Enums\UserRole;
use App\Models\Collection;
use App\Models\DealerTallyEntry;
use App\Models\Order;
use App\Services\Dashboard\DirectorDashboardDataService;
use App\Services\Dealers\DealerLedgerPostingService;
use App\Services\Dealers\DealerOutstandingService;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Services\TallyLedger\TallyLedgerImportService;

it('skips tally import rows that match an existing erp debit or credit on the same date', function (): void {
    $employee = ledgerEmployee(UserRole::Employee, '9811199001');
    $dealer = ledgerDealer($employee, ['firm_name' => 'Shree Ganesh Traders']);
    $admin = tallyImportAdmin();

    ledgerOrder($dealer, $employee, [
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

    $excel = tallyLedgerExcel(typicalTallyRows($dealer->firm_name));
    $result = app(TallyLedgerImportService::class)->import(
        $excel,
        (int) $dealer->id,
        $admin,
        'ganesh.xlsx',
    );

    expect($result['imported_count'])->toBe(0)
        ->and($result['duplicate_count'])->toBe(2)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(2)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)->count())->toBe(1)
        ->and(DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->count())->toBe(1)
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
