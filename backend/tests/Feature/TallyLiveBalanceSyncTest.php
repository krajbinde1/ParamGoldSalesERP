<?php

use App\Models\DealerTallyEntry;
use App\Models\DealerTallyLedger;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Services\TallyLedger\TallyLedgerImportService;
use App\Services\TallySync\TallyLiveBalanceService;
use Illuminate\Support\Carbon;

it('stores live tally closing balances against mapped dealers without touching ledger rows', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000101');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Amrut Fertilizers Purna']);
    tallySyncMapDealer($dealer, 'Amrut Fertilizers Purna');
    $order = tallySyncPendingOrder($dealer, $employee, [
        'status' => 'dispatched',
        'grand_total' => 84525,
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-08-31',
        'dispatched_at' => '2026-08-31 16:00:00',
    ]);
    $entryCount = DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count();
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'connector_id' => 'office-pc-1',
            'tally_online' => true,
            'balances' => [
                [
                    'tally_ledger_name' => 'Amrut Fertilizers Purna',
                    'closing_balance' => 84525,
                    'closing_balance_type' => 'debit',
                ],
                [
                    'tally_ledger_name' => 'Unknown Tally Party',
                    'closing_balance' => 100,
                    'closing_balance_type' => 'debit',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 1)
        ->assertJsonPath('data.unmatched', 1);

    $account = $dealer->fresh()->tallyLedger;
    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());

    expect($account)->not->toBeNull()
        ->and((float) $account->live_closing_balance)->toBe(84525.0)
        ->and($account->live_closing_balance_type)->toBe('debit')
        ->and($account->live_tally_ledger_name)->toBe('Amrut Fertilizers Purna')
        ->and($account->live_synced_at)->not->toBeNull()
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe($entryCount)
        ->and($statement['verification']['status'])->toBe(TallyLiveBalanceService::STATUS_MATCHED)
        ->and($statement['verification']['balance_matched'])->toBeTrue()
        ->and($statement['verification']['difference'])->toBe(0.0)
        ->and($statement['verification']['difference_label'])->toBe('₹0.00')
        ->and($statement['verification']['status_label'])->toBe('Live Tally Matched')
        ->and(DealerTallyEntry::query()->where('source_id', $order->id)->exists())->toBeTrue();
});

it('shows a mismatch when live tally closing differs from erp outstanding', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000102');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Mismatch Agro']);
    tallySyncMapDealer($dealer, 'Mismatch Agro');
    tallySyncPendingOrder($dealer, $employee, [
        'status' => 'dispatched',
        'grand_total' => 84525,
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-08-31',
        'dispatched_at' => '2026-08-31 16:00:00',
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Mismatch Agro',
                'closing_balance' => 80000,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk();

    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());

    expect($statement['verification']['status'])->toBe(TallyLiveBalanceService::STATUS_MISMATCH)
        ->and($statement['verification']['balance_matched'])->toBeFalse()
        ->and($statement['verification']['status_label'])->toBe('Live Tally Balance Mismatch')
        ->and($statement['verification']['live_tally_label'])->toBe('₹80,000.00 Dr')
        ->and($statement['verification']['erp_outstanding_label'])->toBe('₹84,525.00 Dr');
});

it('does not show a false mismatch when the tally connector is offline', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000103');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Offline Agro']);
    tallySyncMapDealer($dealer, 'Offline Agro');
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Offline Agro',
                'closing_balance' => 1000,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => false,
            'balances' => [],
        ])
        ->assertOk();

    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());
    $account = DealerTallyLedger::query()->where('dealer_id', $dealer->id)->first();

    expect($statement['verification']['status'])->toBe(TallyLiveBalanceService::STATUS_OFFLINE)
        ->and($statement['verification']['balance_matched'])->toBeNull()
        ->and($statement['verification']['status_label'])->toStartWith('Tally Offline / Last synced at')
        ->and((float) $account?->live_closing_balance)->toBe(1000.0);
});

it('treats a stale connector heartbeat as offline', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000104');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Stale Agro']);
    tallySyncMapDealer($dealer, 'Stale Agro');
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Stale Agro',
                'closing_balance' => 500,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk();

    Carbon::setTestNow(now()->addMinutes(10));

    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());

    expect($statement['verification']['status'])->toBe(TallyLiveBalanceService::STATUS_OFFLINE)
        ->and($statement['verification']['balance_matched'])->toBeNull();

    Carbon::setTestNow();
});

it('lets admin request a live tally sync and the connector poll reports force_sync', function (): void {
    $user = tallySyncConnectorUser();
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->getJson('/api/tally-connector/live-balances')
        ->assertOk()
        ->assertJsonPath('force_sync', false);

    app(TallyLiveBalanceService::class)->requestSync();

    $this->withToken($token)
        ->getJson('/api/tally-connector/live-balances')
        ->assertOk()
        ->assertJsonPath('force_sync', true);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [],
        ])
        ->assertOk();

    $this->withToken($token)
        ->getJson('/api/tally-connector/live-balances')
        ->assertOk()
        ->assertJsonPath('force_sync', false);
});

it('rejects live balance posts without a connector token', function (): void {
    $this->postJson('/api/tally-connector/live-balances', [
        'tally_online' => true,
        'balances' => [],
    ])->assertUnauthorized();
});

it('matches a unique erp firm name when no tally mapping exists', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000105');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Unique Firm Live Sync']);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Unique Firm Live Sync',
                'closing_balance' => 0,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 1);

    $account = $dealer->fresh()->tallyLedger;
    expect($account)->not->toBeNull()
        ->and((float) $account->live_closing_balance)->toBe(0.0)
        ->and($account->live_tally_ledger_name)->toBe('Unique Firm Live Sync');
});

it('keeps the live tally snapshot when imported tally rows are reset', function (): void {
    $admin = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000108');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Reset Live Agro']);
    tallySyncMapDealer($dealer, 'Reset Live Agro');
    $token = tallySyncConnectorToken($admin);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Reset Live Agro',
                'closing_balance' => 2500,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk();

    DealerTallyEntry::query()->create([
        'dealer_id' => $dealer->id,
        'entry_date' => '2026-04-10',
        'particulars' => 'Tally Sales',
        'voucher_type' => 'Sales',
        'voucher_no' => 'SL-LIVE-1',
        'debit' => 2500,
        'credit' => 0,
        'source' => DealerTallyEntry::SOURCE_TALLY_IMPORT,
        'fingerprint' => DealerTallyEntry::makeFingerprint(
            (int) $dealer->id,
            '2026-04-10',
            'Sales',
            'SL-LIVE-1',
            2500.0,
            0.0,
            'Tally Sales',
        ),
    ]);

    app(TallyLedgerImportService::class)->resetForDealer($dealer);

    $account = DealerTallyLedger::query()->where('dealer_id', $dealer->id)->first();

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(0)
        ->and($account)->not->toBeNull()
        ->and((float) $account->live_closing_balance)->toBe(2500.0)
        ->and($account->live_closing_balance_type)->toBe('debit')
        ->and($account->live_tally_ledger_name)->toBe('Reset Live Agro')
        ->and($account->live_synced_at)->not->toBeNull();
});
