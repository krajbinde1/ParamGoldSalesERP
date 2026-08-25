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
        public readonly ?float $tallySheetTotalDebit = null,
        public readonly ?float $tallySheetTotalCredit = null,
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

    public function inclusiveTotalDebit(): float
    {
        $opening = $this->openingBalanceType === DealerTallyBalance::DEBIT ? $this->openingBalance : 0.0;

        return round($opening + $this->totalDebit, 2);
    }

    public function inclusiveTotalCredit(): float
    {
        $opening = $this->openingBalanceType === DealerTallyBalance::CREDIT ? $this->openingBalance : 0.0;

        return round($opening + $this->totalCredit, 2);
    }

    public function tallyClosingMatches(): ?bool
    {
        if ($this->tallyClosingBalance === null || $this->tallyClosingBalanceType === null) {
            return null;
        }

        $erpSigned = $this->calculatedClosingSigned();

        return DealerTallyBalance::matches(
            DealerTallyBalance::amountFromSigned($erpSigned),
            DealerTallyBalance::typeFromSigned($erpSigned),
            $this->tallyClosingBalance,
            $this->tallyClosingBalanceType,
        );
    }

    /**
     * @return list<string>
     */
    public function importErrors(): array
    {
        $errors = [];

        foreach ($this->failed as $row) {
            $errors[] = 'Row '.($row['row_number'] ?? '?').': '.($row['reason'] ?? 'Could not parse this row.');
        }

        foreach ($this->transactions as $transaction) {
            $year = (int) substr((string) $transaction['date'], 0, 4);
            if ($year < 2000 || $year > 2040) {
                $errors[] = 'Invalid or fake date detected ('.$transaction['date'].'). Import is blocked.';
            }
        }

        if ($this->tallyClosingMatches() === false) {
            $errors[] = 'ERP calculated closing balance does not match Tally closing balance. Import is blocked.';
        }

        return $errors;
    }

    public function canImport(): bool
    {
        return $this->importErrors() === [];
    }
}
