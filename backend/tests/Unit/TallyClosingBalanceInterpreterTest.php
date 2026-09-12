<?php

use App\Services\TallySync\TallyClosingBalanceInterpreter;

it('treats a negative tally xml closing balance as debit even when type is credit', function (): void {
    $parsed = TallyClosingBalanceInterpreter::interpret([
        'closing_balance' => 3393284.20,
        'closing_balance_type' => 'credit',
        'closing_balance_raw' => '-3393284.20',
        'closing_balance_numeric' => -3393284.20,
    ]);

    expect($parsed['amount'])->toBe(3393284.20)
        ->and($parsed['type'])->toBe('debit')
        ->and($parsed['numeric'])->toBe(-3393284.20)
        ->and($parsed['raw'])->toBe('-3393284.20');
});

it('treats a positive unsigned debtor closing balance as debit', function (): void {
    $parsed = TallyClosingBalanceInterpreter::interpret([
        'closing_balance' => 3393284.20,
        'closing_balance_type' => 'credit',
        'closing_balance_raw' => '3393284.20',
        'closing_balance_numeric' => 3393284.20,
    ]);

    expect($parsed['type'])->toBe('debit')
        ->and($parsed['amount'])->toBe(3393284.20);
});

it('uses tally $$IsDebit no as credit', function (): void {
    $parsed = TallyClosingBalanceInterpreter::interpret([
        'closing_balance' => 12500.50,
        'closing_balance_type' => 'debit',
        'closing_balance_raw' => '12500.50',
        'tally_is_debit' => false,
    ]);

    expect($parsed['type'])->toBe('credit');
});
