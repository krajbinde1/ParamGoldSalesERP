<?php

namespace App\Services\TallyLedger;

final class TallyLedgerParseResult
{
    public function __construct(
        public readonly string $tallyLedgerName,
        public readonly float $openingBalance,
        public readonly string $openingBalanceType,
        public readonly bool $openingBalanceExplicit,
        public readonly ?float $tallyClosingBalance,
        public readonly ?string $tallyClosingBalanceType,
        /** @var list<array{date: string, particulars: string, voucher_type: string, voucher_no: string, debit: float, credit: float, row_number: int}> */
        public readonly array $transactions,
        /** @var list<array{row_number: int, reason: string, particulars?: string}> */
        public readonly array $failed,
        public readonly float $totalDebit,
        public readonly float $totalCredit,
        public readonly int $skippedBeforeStartDate,
    ) {}

    public function signedOpeningBalance(): float
    {
        $amount = round($this->openingBalance, 2);

        return $this->openingBalanceType === DealerTallyBalance::CREDIT ? -$amount : $amount;
    }

    public function calculatedClosingSigned(): float
    {
        return round($this->signedOpeningBalance() + $this->totalDebit - $this->totalCredit, 2);
    }
}
