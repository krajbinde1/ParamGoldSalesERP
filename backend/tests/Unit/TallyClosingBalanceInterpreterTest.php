<?php

use App\Services\TallySync\TallyClosingBalanceInterpreter;

it('treats $$IsDebit No on a deemed-positive party ledger as credit', function (): void {
    $parsed = TallyClosingBalanceInterpreter::interpret([
        'closing_balance' => 28808.80,
        'closing_balance_type' => 'debit',
        'closing_balance_raw' => '28808.80',
        'closing_balance_numeric' => 28808.80,
        'tally_is_debit' => false,
        'opening_is_debit' => false,
        'tally_is_negative' => false,
        'deemed_positive' => true,
        'ledger_parent' => 'SO Akash Mundhe',
    ]);

    expect($parsed['amount'])->toBe(28808.80)
        ->and($parsed['type'])->toBe('credit');
});

it('keeps a debtor with transactions as debit when $$IsDebit is yes', function (): void {
    $parsed = TallyClosingBalanceInterpreter::interpret([
        'closing_balance' => 3393284.20,
        'closing_balance_raw' => '-3393284.20',
        'tally_is_debit' => true,
        'deemed_positive' => true,
        'ledger_parent' => 'Sundry Debtors',
    ]);

    expect($parsed['type'])->toBe('debit')
        ->and($parsed['amount'])->toBe(3393284.20);
});

it('treats a credit balance on a debtor as credit from the unnatural side', function (): void {
    $parsed = TallyClosingBalanceInterpreter::interpret([
        'closing_balance' => 12500.50,
        'closing_balance_raw' => '-12500.50',
        'tally_is_debit' => false,
        'tally_is_negative' => true,
        'deemed_positive' => true,
        'ledger_parent' => 'Sundry Debtors',
    ]);

    expect($parsed['type'])->toBe('credit')
        ->and($parsed['amount'])->toBe(12500.50);
});

it('treats a deposit or creditor ledger as credit', function (): void {
    $parsed = TallyClosingBalanceInterpreter::interpret([
        'closing_balance' => 8000,
        'closing_balance_raw' => '8000',
        'tally_is_debit' => false,
        'deemed_positive' => false,
        'ledger_parent' => 'Sundry Creditors',
    ]);

    expect($parsed['type'])->toBe('credit')
        ->and($parsed['amount'])->toBe(8000.0);
});
