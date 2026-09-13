<?php

use App\Services\TallyLedger\DealerTallyBalance;

it('does not add two credit balances of the same amount', function (): void {
    $result = DealerTallyBalance::compareSignedToBalance(-28808.80, 28808.80, 'credit');

    expect($result['matched'])->toBeTrue()
        ->and($result['difference'])->toBe(0.0)
        ->and($result['right_signed'])->toBe(-28808.80);
});

it('compares live tally amount and dr/cr without double-counting the same side', function (
    float $erpSigned,
    float $liveAmount,
    string $liveType,
    bool $matched,
    float $difference,
): void {
    $result = DealerTallyBalance::compareSignedToBalance($erpSigned, $liveAmount, $liveType);

    expect($result['matched'])->toBe($matched)
        ->and($result['difference'])->toBe($difference);
})->with([
    'cr vs cr' => [-28808.80, 28808.80, 'credit', true, 0.0],
    'cr vs Cr alias' => [-28808.80, 28808.80, 'Cr', true, 0.0],
    'dr vs dr' => [28808.80, 28808.80, 'debit', true, 0.0],
    'dr vs Dr alias' => [28808.80, 28808.80, 'Dr', true, 0.0],
    'dr vs cr' => [28808.80, 28808.80, 'credit', false, 57617.60],
    'cr vs dr' => [-28808.80, 28808.80, 'debit', false, -57617.60],
    'zero vs zero debit' => [0.0, 0.0, 'debit', true, 0.0],
    'zero vs zero credit' => [0.0, 0.0, 'credit', true, 0.0],
    'negative stored credit amount' => [-28808.80, -28808.80, 'credit', true, 0.0],
    'different credit amounts' => [-28808.80, 10000.00, 'credit', false, -18808.80],
]);

it('treats cr aliases as credit when converting to a signed balance', function (): void {
    expect(DealerTallyBalance::signed(28808.80, 'Cr'))->toBe(-28808.80)
        ->and(DealerTallyBalance::signed(28808.80, 'CREDIT'))->toBe(-28808.80)
        ->and(DealerTallyBalance::signed(28808.80, 'Dr'))->toBe(28808.80);
});

it('matches same amount and same dr/cr after sign normalization', function (): void {
    expect(DealerTallyBalance::matches(28808.80, 'credit', 28808.80, 'Cr'))->toBeTrue()
        ->and(DealerTallyBalance::matches(28808.80, 'credit', -28808.80, 'credit'))->toBeTrue()
        ->and(DealerTallyBalance::matches(0.0, 'debit', 0.0, 'credit'))->toBeTrue()
        ->and(DealerTallyBalance::matches(28808.80, 'debit', 28808.80, 'credit'))->toBeFalse();
});
