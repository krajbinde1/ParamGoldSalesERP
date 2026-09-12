<?php

use App\Services\TallySync\TallyLiveLedgerName;

it('matches case, trim, collapsed spaces, and spacing around ampersand', function (): void {
    $erp = TallyLiveLedgerName::normalize('Katare Krushi Seva Kendra & Trading Company Walour');
    $tally = TallyLiveLedgerName::normalize('Katare krushi seva kendra & Trading Company Walour');
    $tightAmpersand = TallyLiveLedgerName::normalize('Katare Krushi Seva Kendra&Trading Company Walour');
    $spacedAmpersand = TallyLiveLedgerName::normalize('  Katare   Krushi Seva Kendra  &  Trading Company Walour  ');

    expect($erp)->toBe('katare krushi seva kendra & trading company walour')
        ->and($tally)->toBe($erp)
        ->and($tightAmpersand)->toBe($erp)
        ->and($spacedAmpersand)->toBe($erp);
});

it('does not treat punctuation or extra words as the same name', function (): void {
    expect(TallyLiveLedgerName::normalize('ABC & Co'))
        ->not->toBe(TallyLiveLedgerName::normalize('ABC Co'))
        ->and(TallyLiveLedgerName::normalize('ABC (Pune)'))
        ->not->toBe(TallyLiveLedgerName::normalize('ABC Pune'))
        ->and(TallyLiveLedgerName::normalize('Katare Krushi Seva Kendra'))
        ->not->toBe(TallyLiveLedgerName::normalize('Katare Krushi Seva Kendra & Trading Company Walour'))
        ->and(TallyLiveLedgerName::normalize('Mirai Krushi Seva Kendra (Murtajapur)'))
        ->not->toBe(TallyLiveLedgerName::normalize('Mirai Krushi Seva Kendra(Murtajapur)'));
});

it('matches mirai names that only differ by encoding or invisible characters', function (): void {
    $erp = 'Mirai Krushi Seva Kendra (Murtajapur)';
    $expected = 'mirai krushi seva kendra (murtajapur)';

    expect(TallyLiveLedgerName::normalize($erp))->toBe($expected)
        ->and(TallyLiveLedgerName::normalize("Mirai Krushi Seva Kendra\u{00A0}(Murtajapur)"))->toBe($expected)
        ->and(TallyLiveLedgerName::normalize("Mirai Krushi Seva Kendra \u{200B}(Murtajapur)"))->toBe($expected)
        ->and(TallyLiveLedgerName::normalize('Mirai Krushi Seva Kendra (Murtajapur)&#4;MIRAI'))->toBe($expected)
        ->and(TallyLiveLedgerName::normalize("Mirai Krushi Seva Kendra (Murtajapur)\x04MIRAI"))->toBe($expected)
        ->and(TallyLiveLedgerName::normalize('Mirai Krushi Seva Kendra （Murtajapur）'))->toBe($expected)
        ->and(TallyLiveLedgerName::compare($erp, 'Mirai Krushi Seva Kendra (Murtajapur)')['exact_match'])->toBeTrue();
});
