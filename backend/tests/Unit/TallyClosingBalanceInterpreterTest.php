<?php

use App\Services\TallySync\TallyClosingBalanceInterpreter;

it('treats an opening-only sundry debtor positive amount as debit', function (): void {
    $parsed = TallyClosingBalanceInterpreter::interpret([
        'closing_balance' => 30003,
        'closing_balance_type' => 'credit',
        'closing_balance_raw' => '30003.00',
        'tally_is_debit' => false,
        'deemed_positive' => true,
        'ledger_parent' => 'Sundry Debtors',
    ]);

    expect($parsed['amount'])->toBe(30003.0)
        ->and($parsed['type'])->toBe('debit');
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
