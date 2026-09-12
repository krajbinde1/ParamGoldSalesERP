<?php

use App\Models\Collection;
use App\Models\DealerTallyEntry;
use App\Models\DealerTallyLedger;
use App\Models\TallyDealerMapping;
use App\Services\TallyLedger\TallyDealerLedgerService;
use App\Services\TallySync\TallyDealerMappingService;

it('posts a mapped tally journal debit onto the dealer ledger', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000401');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Journal Party Agro']);
    TallyDealerMapping::query()->create([
        'tally_ledger_name' => 'Journal Party Agro',
        'tally_ledger_name_normalized' => TallyDealerMapping::normalizeName('Journal Party Agro'),
        'tally_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-journal0001',
        'dealer_id' => $dealer->id,
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/journal-vouchers', [
            'connector_id' => 'office-pc-1',
            'tally_online' => true,
            'sync_complete' => true,
            'seen_voucher_guids' => ['dddddddd-dddd-dddd-dddd-ddddddddddd1'],
            'entries' => [[
                'voucher_type' => 'Journal',
                'voucher_guid' => 'dddddddd-dddd-dddd-dddd-ddddddddddd1',
                'master_id' => '501',
                'voucher_no' => 'JV-12',
                'date' => '2026-05-16',
                'narration' => 'Interest receivable',
                'party_ledger_name' => 'Journal Party Agro',
                'party_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-journal0001',
                'debit' => 1500,
                'credit' => 0,
                'entry_index' => 0,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.created', 1);

    $entry = DealerTallyEntry::query()->where('dealer_id', $dealer->id)->first();
    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());

    expect($entry)->not->toBeNull()
        ->and($entry->source)->toBe(DealerTallyEntry::SOURCE_TALLY_JOURNAL)
        ->and($entry->voucher_type)->toBe('Journal')
        ->and($entry->voucher_no)->toBe('JV-12')
        ->and($entry->entry_date?->toDateString())->toBe('2026-05-16')
        ->and((float) $entry->debit)->toBe(1500.0)
        ->and((float) $entry->credit)->toBe(0.0)
        ->and($entry->particulars)->toBe('Interest receivable')
        ->and($entry->tally_voucher_guid)->toBe('dddddddd-dddd-dddd-dddd-ddddddddddd1')
        ->and($entry->tally_master_id)->toBe('501')
        ->and($statement['ledger'][1]['source_label'])->toBe('Tally - Journal')
        ->and($statement['summary']['current_outstanding_signed'])->toBe(1500.0);
});

it('does not post a journal to an unmapped dealer ledger', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000402');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Unmapped Journal Firm']);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/journal-vouchers', [
            'tally_online' => true,
            'sync_complete' => true,
            'seen_voucher_guids' => ['dddddddd-dddd-dddd-dddd-ddddddddddd2'],
            'entries' => [[
                'voucher_type' => 'Journal',
                'voucher_guid' => 'dddddddd-dddd-dddd-dddd-ddddddddddd2',
                'voucher_no' => 'JV-99',
                'date' => '2026-05-16',
                'party_ledger_name' => 'Someone Else In Tally',
                'debit' => 900,
                'credit' => 0,
                'entry_index' => 0,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.unmatched', 1)
        ->assertJsonPath('data.created', 0);

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(0);
});

it('does not duplicate an already synced journal voucher', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000403');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Duplicate Journal Agro']);
    tallySyncMapDealer($dealer, 'Duplicate Journal Agro');
    $token = tallySyncConnectorToken($user);
    $payload = [
        'tally_online' => true,
        'sync_complete' => true,
        'seen_voucher_guids' => ['dddddddd-dddd-dddd-dddd-ddddddddddd3'],
        'entries' => [[
            'voucher_type' => 'Journal',
            'voucher_guid' => 'dddddddd-dddd-dddd-dddd-ddddddddddd3',
            'voucher_no' => 'JV-20',
            'date' => '2026-06-01',
            'narration' => 'Discount',
            'party_ledger_name' => 'Duplicate Journal Agro',
            'debit' => 0,
            'credit' => 400,
            'entry_index' => 0,
        ]],
    ];

    $this->withToken($token)->postJson('/api/tally-connector/journal-vouchers', $payload)->assertOk();
    $this->withToken($token)
        ->postJson('/api/tally-connector/journal-vouchers', $payload)
        ->assertOk()
        ->assertJsonPath('data.created', 0)
        ->assertJsonPath('data.unchanged', 1);

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(1)
        ->and((float) DealerTallyEntry::query()->where('dealer_id', $dealer->id)->value('credit'))->toBe(400.0);
});

it('updates a previously synced journal when tally changes the amount', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000404');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Updated Journal Agro']);
    tallySyncMapDealer($dealer, 'Updated Journal Agro');
    $token = tallySyncConnectorToken($user);
    $guid = 'dddddddd-dddd-dddd-dddd-ddddddddddd4';

    $this->withToken($token)->postJson('/api/tally-connector/journal-vouchers', [
        'tally_online' => true,
        'sync_complete' => true,
        'seen_voucher_guids' => [$guid],
        'entries' => [[
            'voucher_type' => 'Journal',
            'voucher_guid' => $guid,
            'voucher_no' => 'JV-21',
            'date' => '2026-06-02',
            'narration' => 'Old amount',
            'party_ledger_name' => 'Updated Journal Agro',
            'debit' => 250,
            'credit' => 0,
            'entry_index' => 0,
        ]],
    ])->assertOk();

    $this->withToken($token)
        ->postJson('/api/tally-connector/journal-vouchers', [
            'tally_online' => true,
            'sync_complete' => true,
            'seen_voucher_guids' => [$guid],
            'entries' => [[
                'voucher_type' => 'Journal',
                'voucher_guid' => $guid,
                'voucher_no' => 'JV-21',
                'date' => '2026-06-02',
                'narration' => 'Revised interest',
                'party_ledger_name' => 'Updated Journal Agro',
                'debit' => 275,
                'credit' => 0,
                'entry_index' => 0,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.updated', 1);

    $entry = DealerTallyEntry::query()->where('dealer_id', $dealer->id)->first();
    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(1)
        ->and((float) $entry->debit)->toBe(275.0)
        ->and($entry->particulars)->toBe('Revised interest');
});

it('reverses a synced journal that was deleted in tally', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000405');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Deleted Journal Agro']);
    tallySyncMapDealer($dealer, 'Deleted Journal Agro');
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)->postJson('/api/tally-connector/journal-vouchers', [
        'tally_online' => true,
        'sync_complete' => true,
        'seen_voucher_guids' => ['dddddddd-dddd-dddd-dddd-ddddddddddd5'],
        'entries' => [[
            'voucher_type' => 'Journal',
            'voucher_guid' => 'dddddddd-dddd-dddd-dddd-ddddddddddd5',
            'voucher_no' => 'JV-22',
            'date' => '2026-06-03',
            'party_ledger_name' => 'Deleted Journal Agro',
            'debit' => 100,
            'credit' => 0,
            'entry_index' => 0,
        ]],
    ])->assertOk();

    $this->withToken($token)
        ->postJson('/api/tally-connector/journal-vouchers', [
            'tally_online' => true,
            'sync_complete' => true,
            'seen_voucher_guids' => [],
            'entries' => [],
        ])
        ->assertOk()
        ->assertJsonPath('data.reversed', 1);

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(0);
});

it('reverses a synced journal that was cancelled in tally', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000409');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Cancelled Journal Agro']);
    tallySyncMapDealer($dealer, 'Cancelled Journal Agro');
    $token = tallySyncConnectorToken($user);
    $guid = 'dddddddd-dddd-dddd-dddd-ddddddddddd9';

    $this->withToken($token)->postJson('/api/tally-connector/journal-vouchers', [
        'tally_online' => true,
        'sync_complete' => true,
        'seen_voucher_guids' => [$guid],
        'entries' => [[
            'voucher_type' => 'Journal',
            'voucher_guid' => $guid,
            'voucher_no' => 'JV-26',
            'date' => '2026-06-07',
            'party_ledger_name' => 'Cancelled Journal Agro',
            'debit' => 60,
            'credit' => 0,
            'entry_index' => 0,
        ]],
    ])->assertOk();

    $this->withToken($token)
        ->postJson('/api/tally-connector/journal-vouchers', [
            'tally_online' => true,
            'sync_complete' => true,
            'seen_voucher_guids' => [$guid],
            'entries' => [[
                'voucher_type' => 'Journal',
                'voucher_guid' => $guid,
                'voucher_no' => 'JV-26',
                'date' => '2026-06-07',
                'cancelled' => true,
                'party_ledger_name' => 'Cancelled Journal Agro',
                'debit' => 60,
                'credit' => 0,
                'entry_index' => 0,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.reversed', 1);

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(0);
});

it('does not modify erp sales or collection ledger entries during journal sync', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000406');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Protected Journal Agro']);
    tallySyncMapDealer($dealer, 'Protected Journal Agro');
    $order = tallySyncPendingOrder($dealer, $employee, [
        'status' => 'dispatched',
        'grand_total' => 5000,
        'order_date' => '2026-05-01',
        'dispatch_date' => '2026-05-01',
        'dispatched_at' => '2026-05-01 12:00:00',
    ]);
    $collection = tallySyncPendingCollection($dealer, $employee, ['status' => Collection::STATUS_RECEIVED, 'amount' => 1200]);
    $sales = DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_SALES_ORDER)->where('source_id', $order->id)->first();
    $receipt = DealerTallyEntry::query()->where('source', DealerTallyEntry::SOURCE_COLLECTION)->where('source_id', $collection->id)->first();
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)->postJson('/api/tally-connector/journal-vouchers', [
        'tally_online' => true,
        'sync_complete' => true,
        'seen_voucher_guids' => ['dddddddd-dddd-dddd-dddd-ddddddddddd6'],
        'entries' => [[
            'voucher_type' => 'Journal',
            'voucher_guid' => 'dddddddd-dddd-dddd-dddd-ddddddddddd6',
            'voucher_no' => 'JV-23',
            'date' => '2026-06-04',
            'party_ledger_name' => 'Protected Journal Agro',
            'debit' => 300,
            'credit' => 0,
            'entry_index' => 0,
        ]],
    ])->assertOk();

    expect($sales?->fresh()->debit)->toEqual($sales?->debit)
        ->and($receipt?->fresh()->credit)->toEqual($receipt?->credit)
        ->and(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->where('source', DealerTallyEntry::SOURCE_TALLY_JOURNAL)->count())->toBe(1);

    $statement = app(TallyDealerLedgerService::class)->statement($dealer->fresh());
    expect($statement['summary']['current_outstanding_signed'])->toBe(5000.0 - 1200.0 + 300.0);
});

it('uses exact live ledger name fallback when no mapping row exists', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000407');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Live Journal Exact Agro']);
    DealerTallyLedger::query()->create([
        'dealer_id' => $dealer->id,
        'opening_balance' => 0,
        'opening_balance_type' => 'debit',
        'live_tally_ledger_name' => 'Live Journal Exact Agro',
        'live_tally_ledger_guid' => 'aaaaaaaa-bbbb-cccc-dddd-journal0007',
        'live_synced_at' => now('Asia/Kolkata'),
        'financial_start_date' => '2026-04-01',
    ]);
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/journal-vouchers', [
            'tally_online' => true,
            'sync_complete' => true,
            'seen_voucher_guids' => ['dddddddd-dddd-dddd-dddd-ddddddddddd7'],
            'entries' => [[
                'voucher_type' => 'Journal',
                'voucher_guid' => 'dddddddd-dddd-dddd-dddd-ddddddddddd7',
                'voucher_no' => 'JV-24',
                'date' => '2026-06-05',
                'party_ledger_name' => 'Live Journal Exact Agro',
                'debit' => 0,
                'credit' => 80,
                'entry_index' => 0,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.created', 1);

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->where('source', DealerTallyEntry::SOURCE_TALLY_JOURNAL)->count())->toBe(1);
});

it('does not guess a similarly named ledger for journal posting', function (): void {
    $user = tallySyncConnectorUser();
    $employee = tallySyncEmployee('9813000408');
    $dealer = tallySyncDealer($employee, ['firm_name' => 'Shree Ganesh Traders']);
    tallySyncMapDealer($dealer, 'Shree Ganesh Traders');
    $token = tallySyncConnectorToken($user);

    $this->withToken($token)
        ->postJson('/api/tally-connector/journal-vouchers', [
            'tally_online' => true,
            'sync_complete' => true,
            'seen_voucher_guids' => ['dddddddd-dddd-dddd-dddd-ddddddddddd8'],
            'entries' => [[
                'voucher_type' => 'Journal',
                'voucher_guid' => 'dddddddd-dddd-dddd-dddd-ddddddddddd8',
                'voucher_no' => 'JV-25',
                'date' => '2026-06-06',
                'party_ledger_name' => 'Shree Ganesh Trading',
                'debit' => 50,
                'credit' => 0,
                'entry_index' => 0,
            ]],
        ])
        ->assertOk()
        ->assertJsonPath('data.unmatched', 1);

    expect(DealerTallyEntry::query()->where('dealer_id', $dealer->id)->count())->toBe(0)
        ->and(app(TallyDealerMappingService::class)->dealerIdForLedger(null, 'Shree Ganesh Trading'))->toBeNull();
});
