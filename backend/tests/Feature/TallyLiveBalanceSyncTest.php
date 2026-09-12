<?php

use App\Exceptions\TallyMappingException;
use App\Models\DealerTallyEntry;
use App\Models\DealerTallyLedger;
use App\Models\TallyConnectorLedger;
use App\Models\TallyDealerMapping;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Services\TallyLedger\TallyLedgerImportService;
use App\Services\TallySync\TallyDealerMappingService;
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

it('matches erp outstanding when live tally posts the same debit closing balance', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000109');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Dr Cr Sign Agro']);
    tallySyncMapDealer($dealer, 'Dr Cr Sign Agro');
    tallySyncPendingOrder($dealer, $employee, [
        'status' => 'dispatched',
        'grand_total' => 3393284.20,
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-08-31',
        'dispatched_at' => '2026-08-31 16:00:00',
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Dr Cr Sign Agro',
                'closing_balance' => 3393284.20,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk();

    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());

    expect($statement['verification']['status'])->toBe(TallyLiveBalanceService::STATUS_MATCHED)
        ->and($statement['verification']['live_tally_label'])->toBe('₹33,93,284.20 Dr')
        ->and($statement['verification']['erp_outstanding_label'])->toBe('₹33,93,284.20 Dr')
        ->and($statement['verification']['difference'])->toBe(0.0)
        ->and($statement['verification']['difference_label'])->toBe('₹0.00')
        ->and($statement['verification']['status_short'])->toBe('Matched');
});

it('reinterprets a negative tally xml closing balance as debit when the connector type is credit', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000110');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Xml Sign Agro']);
    tallySyncMapDealer($dealer, 'Xml Sign Agro');
    tallySyncPendingOrder($dealer, $employee, [
        'status' => 'dispatched',
        'grand_total' => 3393284.20,
        'order_date' => '2026-08-31',
        'dispatch_date' => '2026-08-31',
        'dispatched_at' => '2026-08-31 16:00:00',
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Xml Sign Agro',
                'closing_balance' => 3393284.20,
                'closing_balance_type' => 'credit',
                'closing_balance_raw' => '-3393284.20',
                'closing_balance_numeric' => -3393284.20,
            ]],
        ])
        ->assertOk();

    $account = $dealer->fresh()->tallyLedger;
    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());

    expect($account?->live_closing_balance_type)->toBe('debit')
        ->and((float) $account?->live_closing_balance)->toBe(3393284.20)
        ->and($statement['verification']['status'])->toBe(TallyLiveBalanceService::STATUS_MATCHED)
        ->and($statement['verification']['live_tally_label'])->toBe('₹33,93,284.20 Dr')
        ->and($statement['verification']['difference'])->toBe(0.0);
});

it('stores an opening-only sundry debtor live balance as debit not credit', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000111');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Kakde Patil Krushi seva Kendra (Dhabadi)']);
    tallySyncMapDealer($dealer, 'Kakde Patil Krushi seva Kendra (Dhabadi)');
    DealerTallyLedger::query()->create([
        'dealer_id' => $dealer->id,
        'opening_balance' => 30003,
        'opening_balance_type' => 'debit',
        'opening_balance_explicit' => true,
        'financial_start_date' => '2026-04-01',
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Kakde Patil Krushi seva Kendra (Dhabadi)',
                'closing_balance' => 30003,
                'closing_balance_type' => 'credit',
                'closing_balance_raw' => '30003.00',
                'closing_balance_numeric' => 30003,
                'tally_is_debit' => false,
                'opening_is_debit' => false,
                'deemed_positive' => true,
                'ledger_parent' => 'Sundry Debtors',
            ]],
        ])
        ->assertOk();

    $account = $dealer->fresh()->tallyLedger;
    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());

    expect($account?->live_closing_balance_type)->toBe('debit')
        ->and((float) $account?->live_closing_balance)->toBe(30003.0)
        ->and($statement['verification']['status'])->toBe(TallyLiveBalanceService::STATUS_MATCHED)
        ->and($statement['verification']['live_tally_label'])->toBe('₹30,003.00 Dr')
        ->and($statement['verification']['erp_outstanding_label'])->toBe('₹30,003.00 Dr')
        ->and($statement['verification']['difference'])->toBe(0.0)
        ->and($statement['verification']['status_short'])->toBe('Matched');
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

it('maps live tally when names are exactly equal after case and ampersand spacing', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000111');
    $dealer = tallySyncDealer($employee, [
        'firm_name' => 'Katare Krushi Seva Kendra & Trading Company Walour',
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Katare krushi seva kendra & Trading Company Walour',
                'closing_balance' => 1500,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 1)
        ->assertJsonPath('data.unmatched', 0)
        ->assertJsonPath('data.ambiguous', 0);

    $account = $dealer->fresh()->tallyLedger;
    expect($account)->not->toBeNull()
        ->and((float) $account->live_closing_balance)->toBe(1500.0)
        ->and($account->live_tally_ledger_name)->toBe('Katare krushi seva kendra & Trading Company Walour');
});

it('does not map live tally when names differ after strict normalization', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000112');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'ABC & Co']);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [
                [
                    'tally_ledger_name' => 'ABC Co',
                    'closing_balance' => 100,
                    'closing_balance_type' => 'debit',
                ],
                [
                    'tally_ledger_name' => 'ABC (Pune)',
                    'closing_balance' => 200,
                    'closing_balance_type' => 'debit',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 0)
        ->assertJsonPath('data.unmatched', 2);

    expect($dealer->fresh()->tallyLedger?->live_synced_at)->toBeNull();

    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());
    expect($statement['verification']['status'])->toBe(TallyLiveBalanceService::STATUS_NOT_SYNCED)
        ->and($statement['verification']['status_short'])->toBe('Not Mapped');
});

it('does not use stored voucher mappings to auto-map a different live tally name', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000113');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Exact Mapping Ignored Agro']);
    tallySyncMapDealer($dealer, 'Completely Different Tally Ledger');
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Completely Different Tally Ledger',
                'closing_balance' => 400,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 0)
        ->assertJsonPath('data.unmatched', 1);

    expect($dealer->fresh()->tallyLedger?->live_synced_at)->toBeNull();
});

it('does not auto-map when more than one tally ledger has the same normalized name', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000114');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Ambiguous Live Agro']);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [
                [
                    'tally_ledger_name' => 'Ambiguous Live Agro',
                    'closing_balance' => 100,
                    'closing_balance_type' => 'debit',
                ],
                [
                    'tally_ledger_name' => 'AMBIGUOUS   Live Agro',
                    'closing_balance' => 200,
                    'closing_balance_type' => 'debit',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 0)
        ->assertJsonPath('data.unmatched', 2)
        ->assertJsonPath('data.ambiguous', 2);

    expect($dealer->fresh()->tallyLedger?->live_synced_at)->toBeNull();
});

it('does not auto-map when two erp dealers share the same normalized firm name', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000115');
    $first = tallySyncDealer($employee, ['firm_name' => 'Duplicate Live Agro']);
    $second = tallySyncDealer($employee, ['firm_name' => 'duplicate  live agro']);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Duplicate Live Agro',
                'closing_balance' => 300,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 0)
        ->assertJsonPath('data.unmatched', 1);

    expect($first->fresh()->tallyLedger?->live_synced_at)->toBeNull()
        ->and($second->fresh()->tallyLedger?->live_synced_at)->toBeNull();
});

it('maps mirai krushi seva kendra (murtajapur) by strict exact name', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000120');
    $dealer = tallySyncDealer($employee, [
        'firm_name' => 'Mirai Krushi Seva Kendra (Murtajapur)',
    ]);
    DealerTallyLedger::query()->create([
        'dealer_id' => $dealer->id,
        'opening_balance' => 445161,
        'opening_balance_type' => 'debit',
        'opening_balance_explicit' => true,
        'financial_start_date' => '2026-04-01',
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Mirai Krushi Seva Kendra (Murtajapur)',
                'closing_balance' => 445161,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 1)
        ->assertJsonPath('data.unmatched', 0)
        ->assertJsonPath('data.ambiguous', 0);

    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());

    expect($statement['verification']['status'])->toBe(TallyLiveBalanceService::STATUS_MATCHED)
        ->and($statement['verification']['status_short'])->toBe('Matched')
        ->and($statement['verification']['live_tally_label'])->toBe('₹4,45,161.00 Dr')
        ->and($statement['verification']['erp_outstanding_label'])->toBe('₹4,45,161.00 Dr')
        ->and($statement['verification']['difference'])->toBe(0.0)
        ->and($statement['verification']['difference_label'])->toBe('₹0.00');
});

it('maps mirai when tally encodes an alias separator or nbsp around parentheses', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000121');
    $dealer = tallySyncDealer($employee, [
        'firm_name' => 'Mirai Krushi Seva Kendra (Murtajapur)',
    ]);
    DealerTallyLedger::query()->create([
        'dealer_id' => $dealer->id,
        'opening_balance' => 445161,
        'opening_balance_type' => 'debit',
        'opening_balance_explicit' => true,
        'financial_start_date' => '2026-04-01',
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => "Mirai Krushi Seva Kendra\u{00A0}(Murtajapur)&#4;MIRAI",
                'closing_balance' => 445161,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 1);

    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());
    expect($statement['verification']['status'])->toBe(TallyLiveBalanceService::STATUS_MATCHED)
        ->and($dealer->fresh()->tallyLedger?->live_tally_ledger_name)->toBe('Mirai Krushi Seva Kendra (Murtajapur)');
});

it('maps the active mirai dealer when an inactive duplicate exists', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000122');
    tallySyncDealer($employee, [
        'firm_name' => 'Mirai Krushi Seva Kendra (Murtajapur)',
        'status' => false,
    ]);
    $active = tallySyncDealer($employee, [
        'firm_name' => 'Mirai Krushi Seva Kendra (Murtajapur)',
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Mirai Krushi Seva Kendra (Murtajapur)',
                'closing_balance' => 445161,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 1);

    expect($active->fresh()->tallyLedger?->live_synced_at)->not->toBeNull();
});

it('maps duplicate tally payload rows that are the same ledger twice', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000123');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Nested Parse Agro']);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [
                [
                    'tally_ledger_name' => 'Nested Parse Agro',
                    'closing_balance' => 10,
                    'closing_balance_type' => 'debit',
                ],
                [
                    'tally_ledger_name' => 'Nested Parse Agro',
                    'closing_balance' => 10,
                    'closing_balance_type' => 'debit',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 1)
        ->assertJsonPath('data.ambiguous', 0);

    expect((float) $dealer->fresh()->tallyLedger?->live_closing_balance)->toBe(10.0);
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

it('maps live tally by saved guid even when dealer and ledger names differ', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000140');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'ERP Name Only Agro']);
    TallyDealerMapping::query()->create([
        'tally_ledger_name' => 'Tally Party Walour',
        'tally_ledger_name_normalized' => TallyDealerMapping::normalizeName('Tally Party Walour'),
        'tally_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'dealer_id' => $dealer->id,
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Tally Party Walour',
                'tally_ledger_guid' => 'AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE',
                'closing_balance' => 445161,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 1);

    $account = $dealer->fresh()->tallyLedger;
    expect($account)->not->toBeNull()
        ->and((float) $account->live_closing_balance)->toBe(445161.0)
        ->and($account->live_tally_ledger_guid)->toBe('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
});

it('backfills guid onto a currently name-mapped dealer without remapping', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000141');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Mirai Krushi Seva Kendra (Murtajapur)']);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Mirai Krushi Seva Kendra (Murtajapur)',
                'tally_ledger_guid' => '11111111-2222-3333-4444-555555555555',
                'closing_balance' => 445161,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.matched', 1);

    $mapping = TallyDealerMapping::query()->where('dealer_id', $dealer->id)->first();
    expect($mapping)->not->toBeNull()
        ->and($mapping->tally_ledger_guid)->toBe('11111111-2222-3333-4444-555555555555')
        ->and($mapping->tally_ledger_name)->toBe('Mirai Krushi Seva Kendra (Murtajapur)')
        ->and((int) $mapping->dealer_id)->toBe((int) $dealer->id);
});

it('does not overwrite an existing saved guid during live ingest', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000142');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Guid Locked Agro']);
    TallyDealerMapping::query()->create([
        'tally_ledger_name' => 'Guid Locked Agro',
        'tally_ledger_name_normalized' => TallyDealerMapping::normalizeName('Guid Locked Agro'),
        'tally_ledger_guid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'dealer_id' => $dealer->id,
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [
                [
                    'tally_ledger_name' => 'Guid Locked Agro',
                    'tally_ledger_guid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                    'closing_balance' => 10,
                    'closing_balance_type' => 'debit',
                ],
                [
                    'tally_ledger_name' => 'Other Locked Party',
                    'tally_ledger_guid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                    'closing_balance' => 99,
                    'closing_balance_type' => 'debit',
                ],
            ],
        ])
        ->assertOk();

    $mapping = TallyDealerMapping::query()->where('dealer_id', $dealer->id)->first();
    $account = $dealer->fresh()->tallyLedger;
    expect($mapping?->tally_ledger_guid)->toBe('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa')
        ->and((float) $account?->live_closing_balance)->toBe(99.0)
        ->and($account?->live_tally_ledger_name)->toBe('Other Locked Party');
});

it('keeps a guid-mapped dealer live snapshot when that ledger is missing from a later sync', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000143');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Keep Snapshot Agro']);
    TallyDealerMapping::query()->create([
        'tally_ledger_name' => 'Keep Snapshot Agro',
        'tally_ledger_name_normalized' => TallyDealerMapping::normalizeName('Keep Snapshot Agro'),
        'tally_ledger_guid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
        'dealer_id' => $dealer->id,
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Keep Snapshot Agro',
                'tally_ledger_guid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
                'closing_balance' => 500,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk();

    $this->withToken($token)
        ->postJson('/api/tally-connector/live-balances', [
            'tally_online' => true,
            'balances' => [[
                'tally_ledger_name' => 'Unrelated Party',
                'tally_ledger_guid' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
                'closing_balance' => 1,
                'closing_balance_type' => 'debit',
            ]],
        ])
        ->assertOk();

    $account = $dealer->fresh()->tallyLedger;
    expect((float) $account?->live_closing_balance)->toBe(500.0)
        ->and($account?->live_tally_ledger_guid)->toBe('cccccccc-cccc-cccc-cccc-cccccccccccc');
});

it('rejects mapping the same tally guid to a second erp dealer', function (): void {
    $employee = tallySyncEmployee('9813000144');
    $first = tallySyncDealer($employee, ['firm_name' => 'First Guid Dealer']);
    $second = tallySyncDealer($employee, ['firm_name' => 'Second Guid Dealer']);
    TallyConnectorLedger::query()->create([
        'tally_ledger_guid' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
        'tally_ledger_name' => 'Shared Tally Party',
        'tally_ledger_name_normalized' => 'shared tally party',
        'last_seen_at' => now('Asia/Kolkata'),
    ]);

    app(TallyDealerMappingService::class)->assign($first, 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee');

    expect(fn () => app(TallyDealerMappingService::class)->assign($second, 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee'))
        ->toThrow(TallyMappingException::class);
});

it('does not change ledger entries or opening balance when mapping is changed or removed', function (): void {
    $employee = tallySyncEmployee('9813000145');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Mapping Safety Agro']);
    $account = DealerTallyLedger::query()->create([
        'dealer_id' => $dealer->id,
        'opening_balance' => 1200,
        'opening_balance_type' => 'debit',
        'opening_balance_explicit' => true,
        'financial_start_date' => '2026-04-01',
    ]);
    DealerTallyEntry::query()->create([
        'dealer_id' => $dealer->id,
        'entry_date' => '2026-04-10',
        'particulars' => 'Sales',
        'voucher_type' => 'Sales',
        'voucher_no' => 'SL-MAP-1',
        'debit' => 300,
        'credit' => 0,
        'source' => DealerTallyEntry::SOURCE_TALLY_IMPORT,
        'fingerprint' => DealerTallyEntry::makeFingerprint(
            (int) $dealer->id,
            '2026-04-10',
            'Sales',
            'SL-MAP-1',
            300.0,
            0.0,
            'Sales',
        ),
    ]);
    TallyConnectorLedger::query()->create([
        'tally_ledger_guid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
        'tally_ledger_name' => 'Safety Party One',
        'tally_ledger_name_normalized' => 'safety party one',
        'last_seen_at' => now('Asia/Kolkata'),
    ]);
    TallyConnectorLedger::query()->create([
        'tally_ledger_guid' => '99999999-9999-9999-9999-999999999999',
        'tally_ledger_name' => 'Safety Party Two',
        'tally_ledger_name_normalized' => 'safety party two',
        'last_seen_at' => now('Asia/Kolkata'),
    ]);

    $mappings = app(TallyDealerMappingService::class);
    $mappings->assign($dealer, 'ffffffff-ffff-ffff-ffff-ffffffffffff');
    $mappings->assign($dealer, '99999999-9999-9999-9999-999999999999', overwrite: true);
    $mappings->remove($dealer->fresh());

    $account->refresh();
    expect((float) $account->opening_balance)->toBe(1200.0)
        ->and($account->opening_balance_type)->toBe('debit')
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(1);
});
