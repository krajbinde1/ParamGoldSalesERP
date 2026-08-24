<?php

namespace App\Services\TallyLedger;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class TallyLedgerExcelParser
{
    private const HEADER_SCAN_MIN_ROWS = 50;

    private const MAX_COLUMNS = 30;

    private const MAX_ROWS = 10000;

    /** @var array<string, string> */
    private const HEADER_ALIASES = [
        'date' => 'date',
        'dt' => 'date',
        'txndate' => 'date',
        'transactiondate' => 'date',
        'voucherdate' => 'date',
        'particulars' => 'particulars',
        'particular' => 'particulars',
        'narration' => 'particulars',
        'details' => 'particulars',
        'vchtype' => 'voucher_type',
        'vouchertype' => 'voucher_type',
        'vchtyp' => 'voucher_type',
        'vchno' => 'voucher_no',
        'voucherno' => 'voucher_no',
        'vouchernumber' => 'voucher_no',
        'vchnumber' => 'voucher_no',
        'debit' => 'debit',
        'dr' => 'debit',
        'debitamount' => 'debit',
        'debitamt' => 'debit',
        'credit' => 'credit',
        'cr' => 'credit',
        'creditamount' => 'credit',
        'creditamt' => 'credit',
    ];

    public function parse(string $path): TallyLedgerParseResult
    {
        $loaded = $this->loadBestSheet($path);
        $sheetRows = $loaded['rows'];
        $worksheetName = $loaded['worksheet'];

        if ($sheetRows === []) {
            $this->logParserDebug($worksheetName, [
                'error' => 'empty_sheet',
            ]);

            throw ValidationException::withMessages([
                'file' => 'The Tally Excel file is empty.',
            ]);
        }

        $header = $this->findHeaderRow($sheetRows);
        if ($header === null) {
            $this->logParserDebug($worksheetName, [
                'error' => 'header_not_found',
                'scanned_row_count' => min(self::HEADER_SCAN_MIN_ROWS, count($sheetRows)),
                'normalized_header_preview' => $this->normalizedPreview($sheetRows, self::HEADER_SCAN_MIN_ROWS),
            ]);

            throw ValidationException::withMessages([
                'file' => 'Could not find a transaction table header. Expected columns such as Date, Particulars, Vch Type, Vch No., Debit, and Credit.',
            ]);
        }

        $headerRowIndex = $header['index'];
        $columnMap = $header['map'];
        $headerSpan = $header['span'];

        $tallyLedgerName = $this->extractLedgerName($sheetRows, $headerRowIndex);
        $openingBalance = 0.0;
        $openingType = DealerTallyBalance::DEBIT;
        $openingExplicit = false;
        $closingBalance = null;
        $closingType = null;
        $transactions = [];
        $failed = [];
        $skippedBeforeStart = 0;
        $startDate = TallyLedgerConfig::FINANCIAL_START_DATE;

        $preHeaderOpening = $this->extractOpeningFromPreamble($sheetRows, $headerRowIndex);
        if ($preHeaderOpening !== null) {
            $openingBalance = $preHeaderOpening['amount'];
            $openingType = $preHeaderOpening['type'];
            $openingExplicit = true;
        }

        for ($index = $headerRowIndex + $headerSpan; $index < count($sheetRows); $index++) {
            $row = $sheetRows[$index];
            if (! is_array($row) || $this->isBlankRow($row)) {
                continue;
            }

            $rowNumber = $index + 1;
            $columnShift = $this->toByColumnShift($row, $columnMap);
            $dateRaw = $this->cellValue($row, $columnMap['date'] ?? null);
            $voucherType = $this->cellString($row, $this->shiftedIndex($columnMap['voucher_type'] ?? null, $columnShift));
            $voucherNo = $this->cellString($row, $this->shiftedIndex($columnMap['voucher_no'] ?? null, $columnShift));
            $debit = $this->parseAmount($this->cellValue($row, $this->shiftedIndex($columnMap['debit'] ?? null, $columnShift)));
            $credit = $this->parseAmount($this->cellValue($row, $this->shiftedIndex($columnMap['credit'] ?? null, $columnShift)));
            if ($columnShift === 1 && $credit <= 0 && $debit > 0
                && preg_match('/^by$/iu', $this->cellString($row, $columnMap['particulars'] ?? null)) === 1) {
                $credit = $debit;
                $debit = 0.0;
            }
            $nextRow = $sheetRows[$index + 1] ?? null;
            $particulars = $this->resolveParticulars(
                $row,
                $columnMap,
                is_array($nextRow) ? $nextRow : null,
                $columnShift,
            );
            $kind = $this->classifyRow($row, $particulars, $dateRaw, $debit, $credit);

            if ($kind === 'skip') {
                continue;
            }

            if ($kind === 'opening') {
                $parsedOpening = $this->openingFromAmounts($debit, $credit, $particulars);
                if ($parsedOpening !== null) {
                    $openingBalance = $parsedOpening['amount'];
                    $openingType = $parsedOpening['type'];
                    $openingExplicit = true;
                }

                continue;
            }

            if ($kind === 'closing') {
                $parsedClosing = $this->closingFromTallyTotals($debit, $credit, $particulars);
                if ($parsedClosing !== null) {
                    $closingBalance = $parsedClosing['amount'];
                    $closingType = $parsedClosing['type'];
                }

                continue;
            }

            if ($kind === 'total') {
                continue;
            }

            $date = $this->parseDate($dateRaw);
            if ($date === null) {
                $failed[] = [
                    'row_number' => $rowNumber,
                    'reason' => 'Invalid or missing date.',
                    'particulars' => $particulars,
                ];

                continue;
            }

            if ($date < $startDate) {
                // Exports should only contain 01-04-2026 onwards. Older rows are ignored,
                // never used to invent an Opening Balance, and are not failed transactions.
                $skippedBeforeStart++;

                continue;
            }

            if ($debit <= 0 && $credit <= 0) {
                $failed[] = [
                    'row_number' => $rowNumber,
                    'reason' => 'Missing Debit and Credit amounts.',
                    'particulars' => $particulars,
                ];

                continue;
            }

            $transactions[] = [
                'date' => $date,
                'particulars' => $particulars !== '' ? $particulars : '—',
                'voucher_type' => $voucherType,
                'voucher_no' => $voucherNo,
                'debit' => round($debit, 2),
                'credit' => round($credit, 2),
                'row_number' => $rowNumber,
            ];
        }

        $totalDebit = round(array_sum(array_column($transactions, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($transactions, 'credit')), 2);

        $this->logParserDebug($worksheetName, [
            'tally_ledger_name' => $tallyLedgerName,
            'detected_header_row_number' => $headerRowIndex + 1,
            'header_span_rows' => $headerSpan,
            'normalized_header_values' => $header['normalized'],
            'mapped_column_indexes' => $columnMap,
            'transaction_rows_detected' => count($transactions),
            'opening_balance' => round($openingBalance, 2),
            'opening_balance_type' => $openingType,
            'opening_balance_explicit' => $openingExplicit,
            'closing_balance' => $closingBalance,
            'closing_balance_type' => $closingType,
            'skipped_before_start_date' => $skippedBeforeStart,
            'failed_rows' => count($failed),
        ]);

        return new TallyLedgerParseResult(
            tallyLedgerName: $tallyLedgerName,
            openingBalance: round($openingBalance, 2),
            openingBalanceType: $openingType,
            openingBalanceExplicit: $openingExplicit,
            tallyClosingBalance: $closingBalance,
            tallyClosingBalanceType: $closingType,
            transactions: $transactions,
            failed: $failed,
            totalDebit: $totalDebit,
            totalCredit: $totalCredit,
            skippedBeforeStartDate: $skippedBeforeStart,
        );
    }

    /**
     * @return array{rows: list<array<int, mixed>>, worksheet: string}
     */
    private function loadBestSheet(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            Log::debug('tally_ledger_excel_load_failed', [
                'message' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'file' => 'Could not find a transaction table header. Expected columns such as Date, Particulars, Vch Type, Vch No., Debit, and Credit.',
            ]);
        }

        $best = null;
        $bestScore = -1;

        try {
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $this->expandMergedCells($sheet);
                $rows = $this->worksheetToRows($sheet);
                $header = $this->findHeaderRow($rows);
                $score = $header === null ? 0 : 100 + count($header['map']);
                if (count($rows) > 0 && $score === 0) {
                    $score = 1;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [
                        'rows' => $rows,
                        'worksheet' => $sheet->getTitle(),
                        'header' => $header,
                    ];
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return [
            'rows' => $best['rows'] ?? [],
            'worksheet' => $best['worksheet'] ?? 'Sheet1',
        ];
    }

    private function expandMergedCells(Worksheet $sheet): void
    {
        $ranges = $sheet->getMergeCells();
        foreach ($ranges as $range) {
            $cells = Coordinate::extractAllCellReferencesInRange($range);
            if ($cells === []) {
                continue;
            }

            $value = $this->rawCellValue($sheet->getCell($cells[0]));
            $sheet->unmergeCells($range);

            foreach ($cells as $coordinate) {
                $sheet->getCell($coordinate)->setValue($value);
            }
        }
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function worksheetToRows(Worksheet $sheet): array
    {
        $highestRow = (int) $sheet->getHighestDataRow();
        if ($highestRow < 1) {
            return [];
        }

        $highestRow = min($highestRow, self::MAX_ROWS);
        $highestColumn = $sheet->getHighestDataColumn();
        $columnCount = min(Coordinate::columnIndexFromString($highestColumn), self::MAX_COLUMNS);
        $rows = [];

        for ($rowNumber = 1; $rowNumber <= $highestRow; $rowNumber++) {
            $row = [];
            for ($column = 1; $column <= $columnCount; $column++) {
                $coordinate = Coordinate::stringFromColumnIndex($column).$rowNumber;
                $row[] = $this->rawCellValue($sheet->getCell($coordinate));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function rawCellValue(Cell $cell): mixed
    {
        $value = $cell->getCalculatedValue();
        if ($value instanceof RichText) {
            return trim($value->getPlainText());
        }

        if (is_string($value)) {
            return $this->cleanText($value);
        }

        return $value;
    }

    /**
     * @param  list<array<int, mixed>>  $sheetRows
     * @return array{index: int, map: array<string, int>, span: int, normalized: list<string>}|null
     */
    private function findHeaderRow(array $sheetRows): ?array
    {
        $limit = count($sheetRows);

        for ($index = 0; $index < $limit; $index++) {
            $row = $sheetRows[$index] ?? [];
            if (! is_array($row)) {
                continue;
            }

            $single = $this->mapColumns($row);
            if ($this->isValidHeaderMap($single)) {
                return [
                    'index' => $index,
                    'map' => $single,
                    'span' => 1,
                    'normalized' => $this->normalizedCells($row),
                ];
            }

            if ($index + 1 >= $limit) {
                continue;
            }

            $next = $sheetRows[$index + 1] ?? [];
            if (! is_array($next)) {
                continue;
            }

            $combined = $this->combineHeaderRows($row, $next);
            $mapped = $this->mapColumns($combined);
            if ($this->isValidHeaderMap($mapped)) {
                return [
                    'index' => $index,
                    'map' => $mapped,
                    'span' => 2,
                    'normalized' => $this->normalizedCells($combined),
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string, int>  $map
     */
    private function isValidHeaderMap(array $map): bool
    {
        return isset($map['date'], $map['particulars'], $map['debit'], $map['credit']);
    }

    /**
     * @param  array<int, mixed>  $first
     * @param  array<int, mixed>  $second
     * @return array<int, mixed>
     */
    private function combineHeaderRows(array $first, array $second): array
    {
        $max = max(count($first), count($second));
        $combined = [];

        for ($index = 0; $index < $max; $index++) {
            $top = $first[$index] ?? null;
            $bottom = $second[$index] ?? null;
            $combined[$index] = $this->cellText($top) !== '' ? $top : $bottom;
        }

        return $combined;
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<string, int>
     */
    private function mapColumns(array $row): array
    {
        $map = [];

        foreach ($row as $index => $value) {
            foreach ($this->headerTokens($value) as $alias) {
                if (! isset(self::HEADER_ALIASES[$alias])) {
                    continue;
                }

                $field = self::HEADER_ALIASES[$alias];
                if (! isset($map[$field])) {
                    $map[$field] = (int) $index;
                }
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function headerTokens(mixed $value): array
    {
        $normalized = $this->normalizeHeader($value);
        if ($normalized === '') {
            return [];
        }

        $tokens = [$normalized];

        $spaced = $this->normalizeHeaderKeepSpaces($value);
        if ($spaced !== '' && str_contains($spaced, ' ')) {
            foreach (preg_split('/\s+/', $spaced) ?: [] as $part) {
                $compact = str_replace(' ', '', $part);
                if ($compact !== '') {
                    $tokens[] = $compact;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    private function normalizeHeader(mixed $value): string
    {
        return str_replace(' ', '', $this->normalizeHeaderKeepSpaces($value));
    }

    private function normalizeHeaderKeepSpaces(mixed $value): string
    {
        $text = Str::of($this->cellText($value))
            ->lower()
            ->replace(["\xC2\xA0", "\u{00A0}"], ' ')
            ->replace(['.', '_', '-', '/', '\\', ':', '(', ')', '[', ']', '{', '}'], ' ')
            ->squish()
            ->toString();

        return (string) preg_replace('/[^a-z0-9 ]+/', '', $text);
    }

    /**
     * @param  array<int, mixed>  $row
     * @return list<string>
     */
    private function normalizedCells(array $row): array
    {
        $cells = [];
        foreach ($row as $value) {
            $text = $this->normalizeHeaderKeepSpaces($value);
            if ($text !== '') {
                $cells[] = $text;
            }
        }

        return $cells;
    }

    /**
     * @param  list<array<int, mixed>>  $sheetRows
     * @return list<list<string>>
     */
    private function normalizedPreview(array $sheetRows, int $limit): array
    {
        $preview = [];
        $count = min($limit, count($sheetRows));

        for ($index = 0; $index < $count; $index++) {
            $row = $sheetRows[$index] ?? [];
            if (! is_array($row)) {
                continue;
            }

            $preview[] = $this->normalizedCells($row);
        }

        return $preview;
    }

    /**
     * @param  list<array<int, mixed>>  $sheetRows
     */
    private function extractLedgerName(array $sheetRows, int $headerRowIndex): string
    {
        $candidates = [];
        $companyName = null;

        for ($index = 0; $index < $headerRowIndex; $index++) {
            $row = $sheetRows[$index] ?? [];
            if (! is_array($row) || $this->isBlankRow($row)) {
                continue;
            }

            $cells = [];
            foreach ($row as $cell) {
                $text = $this->cellText($cell);
                if ($text !== '' && ! in_array($text, $cells, true)) {
                    $cells[] = $text;
                }
            }

            if ($cells === []) {
                continue;
            }

            if ($companyName === null) {
                $companyName = $cells[0];
            }

            $joined = implode(' ', $cells);

            if (preg_match('/ledger(?:\s*name)?\s*[:\-]\s*(.+)$/iu', $joined, $matches) === 1) {
                $name = $this->stripLedgerPrefix(trim($matches[1]));
                if ($name !== '' && ! $this->shouldIgnoreLedgerCandidate($name, $companyName)) {
                    return $name;
                }
            }

            if (count($cells) >= 2 && preg_match('/^ledger(?:\s*name)?$/iu', $cells[0]) === 1) {
                $name = $this->stripLedgerPrefix(trim($cells[1]));
                if ($name !== '' && ! $this->shouldIgnoreLedgerCandidate($name, $companyName)) {
                    return $name;
                }
            }

            if (count($cells) <= 2) {
                $candidate = $this->stripLedgerPrefix($cells[count($cells) === 2 && preg_match('/^ledger/iu', $cells[0]) === 1 ? 1 : 0]);
                if ($candidate !== '' && ! $this->shouldIgnoreLedgerCandidate($candidate, $companyName)) {
                    $candidates[] = $candidate;
                }
            }
        }

        $candidates = array_values(array_filter(
            $candidates,
            fn (string $name): bool => mb_strlen($name) >= 3,
        ));

        return $candidates === [] ? '' : (string) end($candidates);
    }

    private function shouldIgnoreLedgerCandidate(string $text, ?string $companyName): bool
    {
        if ($this->looksLikePeriodOrAddress($text) || $this->looksLikeCompanyMeta($text)) {
            return true;
        }

        if ($companyName !== null && Str::lower(trim($text)) === Str::lower(trim($companyName))) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<array<int, mixed>>  $sheetRows
     * @return array{amount: float, type: string}|null
     */
    private function extractOpeningFromPreamble(array $sheetRows, int $headerRowIndex): ?array
    {
        for ($index = 0; $index < $headerRowIndex; $index++) {
            $row = $sheetRows[$index] ?? [];
            if (! is_array($row) || $this->isBlankRow($row)) {
                continue;
            }

            $joined = trim(implode(' ', array_map(fn ($cell): string => $this->cellText($cell), $row)));
            if (preg_match('/opening\s*balance/i', $joined) !== 1) {
                continue;
            }

            $fromText = $this->openingFromText($joined);
            if ($fromText !== null) {
                return $fromText;
            }
        }

        return null;
    }

    /**
     * Tally often stores "To" / "By" in the Particulars column and the real ledger
     * name in the next cell or on the following continuation row.
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columnMap
     * @param  array<int, mixed>|null  $nextRow
     */
    private function resolveParticulars(array $row, array $columnMap, ?array $nextRow, int $shift = 0): string
    {
        $particulars = $this->particularsFromRow($row, $columnMap, $shift);
        if ($particulars !== '') {
            return $particulars;
        }

        if ($nextRow === null || $this->isBlankRow($nextRow)) {
            return '';
        }

        $nextDate = $this->cellValue($nextRow, $columnMap['date'] ?? null);
        $nextDebit = $this->parseAmount($this->cellValue($nextRow, $columnMap['debit'] ?? null));
        $nextCredit = $this->parseAmount($this->cellValue($nextRow, $columnMap['credit'] ?? null));
        if ($this->parseDate($nextDate) !== null || $nextDebit > 0 || $nextCredit > 0) {
            return '';
        }

        $fromNext = $this->particularsFromRow($nextRow, $columnMap);
        if ($this->isSpecialLedgerLabel($fromNext)) {
            return '';
        }

        return $fromNext;
    }

    /**
     * When Tally puts To/By in its own cell, the remaining columns sit one place
     * to the right of the header map: Date | To | Sales @5% | Sales | Vch No | Amount.
     *
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columnMap
     */
    private function toByColumnShift(array $row, array $columnMap): int
    {
        $particularsIndex = $columnMap['particulars'] ?? null;
        if ($particularsIndex === null || ! $this->isToByToken($this->cellString($row, $particularsIndex))) {
            return 0;
        }

        $nameIndex = $particularsIndex + 1;
        $name = $this->stripToByPrefix($this->cellString($row, $nameIndex));
        if ($name === '' || $this->isToByToken($name) || $this->isSpecialLedgerLabel($name) || $this->looksLikeVoucherType($name)) {
            return 0;
        }

        $nextMapped = $this->nextMappedIndexAfter($columnMap, $particularsIndex);
        if ($nextMapped === null || $nameIndex < $nextMapped) {
            return 0;
        }

        $debitIndex = $this->shiftedIndex($columnMap['debit'] ?? null, 1);
        $creditIndex = $this->shiftedIndex($columnMap['credit'] ?? null, 1);
        $shiftedDebit = $this->parseAmount($this->cellValue($row, $debitIndex));
        $shiftedCredit = $this->parseAmount($this->cellValue($row, $creditIndex));

        return ($shiftedDebit > 0 || $shiftedCredit > 0) ? 1 : 0;
    }

    private function shiftedIndex(?int $index, int $shift): ?int
    {
        if ($index === null) {
            return null;
        }

        return $index + $shift;
    }

    private function looksLikeVoucherType(string $text): bool
    {
        $normalized = Str::of($text)->lower()->squish()->toString();

        return in_array($normalized, [
            'sales',
            'purchase',
            'receipt',
            'payment',
            'journal',
            'contra',
            'credit note',
            'debit note',
        ], true);
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columnMap
     */
    private function particularsFromRow(array $row, array $columnMap, int $shift = 0): string
    {
        $start = $columnMap['particulars'] ?? null;
        if ($start === null) {
            return '';
        }

        $start += $shift;
        $stop = $this->nextMappedIndexAfter($columnMap, $columnMap['particulars']);
        $end = $stop === null ? count($row) : $stop + $shift;
        $parts = [];

        for ($index = $start; $index < $end; $index++) {
            $text = $this->stripToByPrefix($this->cellString($row, $index));
            if ($text === '' || $this->isToByToken($text)) {
                continue;
            }

            $parts[] = $text;
        }

        $unique = [];
        foreach ($parts as $part) {
            if (! in_array($part, $unique, true)) {
                $unique[] = $part;
            }
        }

        return trim(implode(' ', $unique));
    }

    /**
     * @param  array<string, int>  $columnMap
     */
    private function nextMappedIndexAfter(array $columnMap, int $start): ?int
    {
        $next = null;

        foreach ($columnMap as $index) {
            if ($index <= $start) {
                continue;
            }

            $next = $next === null ? $index : min($next, $index);
        }

        return $next;
    }

    private function stripToByPrefix(string $text): string
    {
        $stripped = trim((string) preg_replace('/^(to|by)\s+/iu', '', $text));

        return $stripped === '' ? $text : $stripped;
    }

    private function isToByToken(string $text): bool
    {
        return preg_match('/^(to|by)$/iu', trim($text)) === 1;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function classifyRow(array $row, string $particulars, mixed $dateRaw, float $debit, float $credit): string
    {
        $haystack = Str::of($particulars.' '.$this->rowText($row))->lower()->squish()->toString();

        if (preg_match('/opening\s*balance/i', $haystack) === 1) {
            return 'opening';
        }

        if (preg_match('/closing\s*balance/i', $haystack) === 1) {
            return 'closing';
        }

        if ($this->isTotalLabel($particulars)
            || ($this->parseDate($dateRaw) === null && $this->rowHasTotalLabel($row))) {
            return 'total';
        }

        if ($this->parseDate($dateRaw) === null && $debit <= 0 && $credit <= 0) {
            return 'skip';
        }

        return 'transaction';
    }

    private function isSpecialLedgerLabel(string $text): bool
    {
        $normalized = Str::of($text)->lower()->squish()->toString();

        return $normalized === ''
            || preg_match('/opening\s*balance/i', $normalized) === 1
            || preg_match('/closing\s*balance/i', $normalized) === 1
            || $this->isTotalLabel($text);
    }

    private function isTotalLabel(string $text): bool
    {
        return preg_match('/^(grand\s*)?totals?$/iu', trim($text)) === 1;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function rowHasTotalLabel(array $row): bool
    {
        foreach ($row as $cell) {
            if ($this->isTotalLabel($this->cellText($cell))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function rowText(array $row): string
    {
        $parts = [];
        foreach ($row as $cell) {
            $text = $this->cellText($cell);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{amount: float, type: string}|null
     */
    private function openingFromAmounts(float $debit, float $credit, string $particulars): ?array
    {
        $fromText = $this->openingFromText($particulars);
        if ($fromText !== null) {
            return $fromText;
        }

        if ($debit > 0 && $credit <= 0) {
            return ['amount' => $debit, 'type' => DealerTallyBalance::DEBIT];
        }

        if ($credit > 0 && $debit <= 0) {
            return ['amount' => $credit, 'type' => DealerTallyBalance::CREDIT];
        }

        return null;
    }

    /**
     * Tally prints Closing Balance on the opposite side so debit/credit totals match.
     * Credit-side closing amount means a Debit outstanding, and vice versa.
     *
     * @return array{amount: float, type: string}|null
     */
    private function closingFromTallyTotals(float $debit, float $credit, string $particulars): ?array
    {
        $fromText = $this->openingFromText($particulars);
        if ($fromText !== null) {
            return $fromText;
        }

        if ($credit > 0 && $debit <= 0) {
            return ['amount' => $credit, 'type' => DealerTallyBalance::DEBIT];
        }

        if ($debit > 0 && $credit <= 0) {
            return ['amount' => $debit, 'type' => DealerTallyBalance::CREDIT];
        }

        return null;
    }

    /**
     * @return array{amount: float, type: string}|null
     */
    private function openingFromText(string $text): ?array
    {
        if (preg_match('/([\d,.]+)\s*(dr|cr|debit|credit)\b/i', $text, $matches) === 1) {
            $amount = $this->parseAmount($matches[1]);
            if ($amount <= 0) {
                return null;
            }

            $type = in_array(Str::lower($matches[2]), ['cr', 'credit'], true)
                ? DealerTallyBalance::CREDIT
                : DealerTallyBalance::DEBIT;

            return ['amount' => $amount, 'type' => $type];
        }

        if (preg_match('/\b(dr|cr|debit|credit)\b\s*[:\-]?\s*([\d,.]+)/i', $text, $matches) === 1) {
            $amount = $this->parseAmount($matches[2]);
            if ($amount <= 0) {
                return null;
            }

            $type = in_array(Str::lower($matches[1]), ['cr', 'credit'], true)
                ? DealerTallyBalance::CREDIT
                : DealerTallyBalance::DEBIT;

            return ['amount' => $amount, 'type' => $type];
        }

        return null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->acceptLedgerDate(Carbon::instance(\DateTimeImmutable::createFromInterface($value)));
        }

        if (is_numeric($value)) {
            try {
                return $this->acceptLedgerDate(Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value)));
            } catch (\Throwable) {
                return null;
            }
        }

        $raw = $this->cleanText((string) $value);
        if ($raw === '') {
            return null;
        }

        $raw = str_replace(['/', '.'], '-', $raw);
        $formats = [
            'Y-m-d',
            'd-M-y',
            'j-M-y',
            'd-M-Y',
            'j-M-Y',
            'd-m-Y',
            'd-m-y',
            'j-n-Y',
            'j-n-y',
            'd M y',
            'j M y',
            'd M Y',
            'j M Y',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $raw);
                if ($date === false) {
                    continue;
                }

                $year = (int) $date->year;
                if ($year < 100) {
                    $date->addYears(2000);
                    $year = (int) $date->year;
                }

                if ($year < 1990 || $year > 2100) {
                    continue;
                }

                return $this->acceptLedgerDate($date);
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return $this->acceptLedgerDate(Carbon::parse($raw));
        } catch (\Throwable) {
            return null;
        }
    }

    private function acceptLedgerDate(Carbon $date): ?string
    {
        $year = (int) $date->year;
        if ($year < 1990 || $year > 2100) {
            return null;
        }

        return $date->toDateString();
    }

    private function parseAmount(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $text = $this->cleanText((string) $value);
        $text = str_ireplace(['₹', 'inr', 'rs.', 'rs', 'dr', 'cr', 'debit', 'credit'], '', $text);
        $text = str_replace([',', ' '], '', $text);
        $text = trim($text);

        if ($text === '' || ! is_numeric($text)) {
            return 0.0;
        }

        return round((float) $text, 2);
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($this->cellText($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function cellValue(array $row, ?int $index): mixed
    {
        if ($index === null) {
            return null;
        }

        return $row[$index] ?? null;
    }

    /**
     * @param  array<int, mixed>  $row
     */
    private function cellString(array $row, ?int $index): string
    {
        return $this->cellText($this->cellValue($row, $index));
    }

    private function cellText(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof RichText) {
            return $this->cleanText($value->getPlainText());
        }

        if (is_bool($value)) {
            return '';
        }

        return $this->cleanText((string) $value);
    }

    private function cleanText(string $text): string
    {
        $text = str_replace(["\xC2\xA0", "\u{00A0}", "\t", "\r", "\n"], ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function looksLikePeriodOrAddress(string $text): bool
    {
        if (preg_match('/\bto\b/i', $text) === 1 && preg_match('/\d/', $text) === 1) {
            return true;
        }

        if (preg_match('/^\d{1,2}[- \/][A-Za-z]{3}/', $text) === 1) {
            return true;
        }

        if (preg_match('/\d{6}/', $text) === 1 && preg_match('/\b(road|street|nagar|pincode|pin)\b/i', $text) === 1) {
            return true;
        }

        if (substr_count($text, ',') >= 2) {
            return true;
        }

        if (preg_match('/\bgut\s*no\.?\b/i', $text) === 1) {
            return true;
        }

        return false;
    }

    private function looksLikeCompanyMeta(string $text): bool
    {
        $normalized = Str::lower($text);

        foreach (['gstin', 'gst no', 'cin', 'phone', 'mobile', 'email', 'address', 'pincode', 'pin code', 'www.', 'http', 'tel:', 'fax'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function stripLedgerPrefix(string $name): string
    {
        return trim((string) preg_replace('/^ledger(?:\s*name)?\s*[:\-]?\s*/iu', '', $name));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logParserDebug(string $worksheetName, array $context): void
    {
        Log::debug('tally_ledger_excel_parser', array_merge([
            'worksheet_name' => $worksheetName,
        ], $context));
    }
}
