<?php

namespace App\Services\TallyLedger;

use App\Models\Dealer;
use App\Models\Employee;
use App\Models\TallyDealerMapping;
use App\Models\User;
use App\Support\IndianCurrency;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class TallyBulkLedgerImportService
{
    public const STATUS_MATCHED = 'Matched';

    public const STATUS_NOT_MATCHED = 'Not Matched';

    public const STATUS_ALREADY_IMPORTED = 'Already Imported';

    public const STATUS_ERROR = 'Error';

    /** @var Collection<int, Dealer>|null */
    private ?Collection $dealersForSuggestions = null;

    public function __construct(
        private readonly TallyLedgerExcelParser $parser = new TallyLedgerExcelParser,
        private readonly TallyLedgerImportService $importer = new TallyLedgerImportService,
    ) {}

    /**
     * @return list<array{id: int, dealer_code: ?string, firm_name: string, village: ?string, tally_status: string}>
     */
    public function assignedDealers(int $employeeId): array
    {
        return $this->dealersForEmployee($employeeId)
            ->map(fn (Dealer $dealer): array => [
                'id' => (int) $dealer->id,
                'dealer_code' => $dealer->dealer_code,
                'firm_name' => (string) $dealer->firm_name,
                'village' => $dealer->village,
                'tally_status' => $dealer->tallyLedgerImportStatusLabel(),
            ])
            ->all();
    }

    /**
     * @param  list<array{path: string, original_filename: string}>  $files
     * @return array{employee: Employee, assigned_dealers: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function previewFiles(array $files, int $employeeId): array
    {
        $employee = $this->requireEmployee($employeeId);
        $dealers = $this->dealersForEmployee($employeeId);

        return [
            'employee' => $employee,
            'assigned_dealers' => $this->assignedDealers($employeeId),
            'rows' => $this->rowsFromFiles($files, $dealers, $employee),
        ];
    }

    /**
     * @param  list<array{path: string, original_filename: string}>  $files
     * @return array{employee: Employee, assigned_dealers: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function importFiles(array $files, int $employeeId, User $actor): array
    {
        $employee = $this->requireEmployee($employeeId);
        $dealers = $this->dealersForEmployee($employeeId);
        $rows = [];

        foreach ($this->rowsFromFiles($files, $dealers, $employee) as $index => $row) {
            if (($row['status'] ?? '') !== self::STATUS_MATCHED || empty($row['can_import']) || empty($row['dealer_id'])) {
                $rows[] = $row;

                continue;
            }

            $dealer = $dealers->firstWhere('id', (int) $row['dealer_id']);
            $file = $files[$index] ?? null;
            if (! $dealer instanceof Dealer || (int) $dealer->assigned_employee_id !== $employeeId || ! is_array($file)) {
                $rows[] = $this->withStatus($row, self::STATUS_NOT_MATCHED, 'This Tally party is not assigned to the selected employee.');

                continue;
            }

            try {
                $parsed = $this->parser->parse((string) $file['path']);
            } catch (ValidationException $exception) {
                $rows[] = $this->withStatus(
                    $row,
                    self::STATUS_ERROR,
                    collect($exception->errors())->flatten()->first() ?: 'Unable to read this Tally Excel.',
                );

                continue;
            }

            $ledgerPreview = $this->importer->previewParsed($parsed, $dealer);
            if (empty($ledgerPreview['can_import'])) {
                $rows[] = $this->withStatus(
                    $row,
                    self::STATUS_ERROR,
                    collect($ledgerPreview['parse_errors'] ?? [])->first() ?: 'Tally ledger parsing is incomplete.',
                );

                continue;
            }

            try {
                $result = $this->importer->importPreview(
                    $ledgerPreview,
                    $dealer,
                    $actor,
                    (string) ($file['original_filename'] ?? 'tally-ledger.xlsx'),
                );
                $dealer->refresh()->load('tallyLedger');
                $row['matched_dealer'] = (string) $dealer->firm_name;
                $row['closing_balance_label'] = (string) ($result['summary']['current_outstanding_label'] ?? $row['closing_balance_label']);
                $row['imported_count'] = (int) $result['imported_count'];
                $row['duplicate_count'] = (int) $result['duplicate_count'];
                $row['reconciled_count'] = (int) ($result['reconciled_count'] ?? 0);
                $row['tally_status'] = $dealer->tallyLedgerImportStatusLabel();
                $row['import_status_label'] = 'Ledger Imported';
                $row['can_import'] = false;
                $row['reason'] = null;
                $rows[] = $row;
            } catch (ValidationException $exception) {
                $rows[] = $this->withStatus(
                    $row,
                    self::STATUS_ERROR,
                    collect($exception->errors())->flatten()->first() ?: 'Unable to import this ledger.',
                );
            }
        }

        return [
            'employee' => $employee,
            'assigned_dealers' => $this->assignedDealers($employeeId),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<array{path: string, original_filename: string}>  $files
     * @param  Collection<int, Dealer>  $dealers
     * @return list<array<string, mixed>>
     */
    private function rowsFromFiles(array $files, Collection $dealers, Employee $employee): array
    {
        $claimedDealerIds = [];
        $rows = [];

        foreach ($files as $file) {
            $filename = (string) ($file['original_filename'] ?? 'tally-ledger.xlsx');
            $path = (string) ($file['path'] ?? '');

            if ($path === '' || ! is_file($path)) {
                $rows[] = $this->fileRow(
                    filename: $filename,
                    detectedDealer: '—',
                    status: self::STATUS_ERROR,
                    reason: 'The uploaded file could not be read.',
                    employee: $employee,
                );

                continue;
            }

            try {
                $parsed = $this->parser->parse($path);
            } catch (ValidationException $exception) {
                $rows[] = $this->fileRow(
                    filename: $filename,
                    detectedDealer: '—',
                    status: self::STATUS_ERROR,
                    reason: collect($exception->errors())->flatten()->first() ?: 'Unable to read this Tally Excel.',
                    employee: $employee,
                );

                continue;
            }

            $detected = $this->detectDealerName($parsed, $filename);
            $preview = $this->importer->previewParsed($parsed);
            $closingLabel = IndianCurrency::formatDrCr((float) ($preview['erp_closing_signed'] ?? $parsed->calculatedClosingSigned()));
            $match = $this->matchDealer($detected, $dealers);

            if (! $match['matched'] || $match['dealer'] === null) {
                $rows[] = $this->fileRow(
                    filename: $filename,
                    detectedDealer: $detected,
                    status: self::STATUS_NOT_MATCHED,
                    reason: $match['reason'] ?? 'No assigned dealer matches this Tally party.',
                    employee: $employee,
                    assignedDealers: $dealers,
                    transactionCount: count($parsed->transactions),
                    closingLabel: $closingLabel,
                );

                continue;
            }

            $dealer = $match['dealer'];
            $dealerId = (int) $dealer->id;
            if (in_array($dealerId, $claimedDealerIds, true)) {
                $rows[] = $this->fileRow(
                    filename: $filename,
                    detectedDealer: $detected,
                    status: self::STATUS_ERROR,
                    reason: 'This dealer is already matched by another uploaded file.',
                    employee: $employee,
                    dealer: $dealer,
                    transactionCount: count($parsed->transactions),
                    closingLabel: $closingLabel,
                );

                continue;
            }

            $claimedDealerIds[] = $dealerId;

            if (empty($preview['can_import'])) {
                $rows[] = $this->fileRow(
                    filename: $filename,
                    detectedDealer: $detected,
                    status: self::STATUS_ERROR,
                    reason: collect($preview['parse_errors'] ?? [])->first() ?: 'Tally ledger parsing is incomplete.',
                    employee: $employee,
                    dealer: $dealer,
                    transactionCount: count($parsed->transactions),
                    closingLabel: $closingLabel,
                );

                continue;
            }

            $rows[] = $this->fileRow(
                filename: $filename,
                detectedDealer: $detected,
                status: self::STATUS_MATCHED,
                reason: null,
                employee: $employee,
                dealer: $dealer,
                transactionCount: count($parsed->transactions),
                closingLabel: $closingLabel,
                canImport: true,
            );
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Dealer>|null  $assignedDealers
     * @return array<string, mixed>
     */
    private function fileRow(
        string $filename,
        string $detectedDealer,
        string $status,
        ?string $reason,
        Employee $employee,
        ?Collection $assignedDealers = null,
        ?Dealer $dealer = null,
        int $transactionCount = 0,
        string $closingLabel = '—',
        bool $canImport = false,
    ): array {
        $suggested = null;
        if ($status === self::STATUS_NOT_MATCHED && $assignedDealers !== null) {
            $suggested = $this->suggestDealer($detectedDealer, $assignedDealers);
        }

        return [
            'file_name' => $filename,
            'detected_dealer' => $detectedDealer,
            'matched_dealer' => $dealer?->firm_name ?: '—',
            'dealer_id' => $dealer?->id,
            'dealer_code' => $dealer?->dealer_code,
            'status' => $status,
            'reason' => $reason,
            'suggested_dealer' => $suggested,
            'employee_code' => $employee->employee_code,
            'employee_name' => $employee->full_name,
            'tally_status' => $dealer?->tallyLedgerImportStatusLabel() ?? 'Not Imported',
            'transaction_count' => $transactionCount,
            'imported_count' => 0,
            'duplicate_count' => 0,
            'closing_balance_label' => $closingLabel,
            'import_status_label' => '',
            'can_import' => $canImport,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withStatus(array $row, string $status, string $reason): array
    {
        $row['status'] = $status;
        $row['reason'] = $reason;
        $row['can_import'] = false;
        $row['import_status_label'] = '';

        return $row;
    }

    private function detectDealerName(TallyLedgerParseResult $parsed, string $filename): string
    {
        $fromExcel = trim($parsed->tallyLedgerName);
        if ($fromExcel !== '' && ! $this->isUnusableDetectedName($fromExcel)) {
            return $fromExcel;
        }

        $fromFile = trim((string) pathinfo($filename, PATHINFO_FILENAME));
        if ($fromFile !== '' && ! $this->isUnusableDetectedName($fromFile)) {
            return $fromFile;
        }

        return $fromExcel !== '' ? $fromExcel : '—';
    }

    private function isUnusableDetectedName(string $name): bool
    {
        $normalized = TallyDealerMapping::normalizeName($name);
        if ($normalized === '') {
            return true;
        }

        return preg_match(
            '/^(salesman|sales man|group|period|ledger account|ledger name)\b/iu',
            trim($name),
        ) === 1;
    }

    /**
     * Possible ERP dealer for unmatched Tally names. Never used to auto-import.
     *
     * @param  Collection<int, Dealer>  $assignedDealers
     */
    private function suggestDealer(string $tallyLedgerName, Collection $assignedDealers): ?string
    {
        $normalized = TallyDealerMapping::normalizeName($tallyLedgerName);
        if ($normalized === '') {
            return null;
        }

        $exactAssigned = $assignedDealers->filter(
            fn (Dealer $dealer): bool => TallyDealerMapping::normalizeName((string) $dealer->firm_name) === $normalized,
        );
        if ($exactAssigned->isNotEmpty()) {
            return $exactAssigned
                ->map(fn (Dealer $dealer): string => $this->dealerSuggestionLabel($dealer))
                ->unique()
                ->implode('; ');
        }

        $mappedDealerId = TallyDealerMapping::query()
            ->where('tally_ledger_name_normalized', $normalized)
            ->value('dealer_id');
        if ($mappedDealerId !== null) {
            $mapped = Dealer::query()
                ->with('assignedEmployee:id,full_name,employee_code')
                ->find((int) $mappedDealerId);
            if ($mapped instanceof Dealer) {
                return $this->dealerSuggestionLabel($mapped, includeEmployee: true);
            }
        }

        $similarAssigned = $this->similarDealers($normalized, $assignedDealers);
        if ($similarAssigned->isNotEmpty()) {
            return $similarAssigned
                ->map(fn (Dealer $dealer): string => $this->dealerSuggestionLabel($dealer))
                ->implode('; ');
        }

        $assignedIds = $assignedDealers->modelKeys();
        $otherDealers = $this->dealersForSuggestions()
            ->when(
                $assignedIds !== [],
                fn (Collection $dealers): Collection => $dealers->reject(
                    fn (Dealer $dealer): bool => in_array((int) $dealer->id, array_map('intval', $assignedIds), true),
                ),
            );
        $similarOther = $this->similarDealers($normalized, $otherDealers);
        if ($similarOther->isNotEmpty()) {
            return $similarOther
                ->map(fn (Dealer $dealer): string => $this->dealerSuggestionLabel($dealer, includeEmployee: true))
                ->implode('; ');
        }

        return null;
    }

    /**
     * @param  Collection<int, Dealer>  $dealers
     * @return Collection<int, Dealer>
     */
    private function similarDealers(string $normalizedTallyName, Collection $dealers): Collection
    {
        $scored = $dealers
            ->map(function (Dealer $dealer) use ($normalizedTallyName): array {
                return [
                    'dealer' => $dealer,
                    'score' => $this->nameSimilarity(
                        $normalizedTallyName,
                        TallyDealerMapping::normalizeName((string) $dealer->firm_name),
                    ),
                ];
            })
            ->filter(fn (array $row): bool => $row['score'] >= 75.0)
            ->sortByDesc('score')
            ->values();

        if ($scored->isEmpty()) {
            return collect();
        }

        $best = (float) $scored->first()['score'];

        return $scored
            ->filter(fn (array $row): bool => ($best - (float) $row['score']) <= 3.0)
            ->take(3)
            ->pluck('dealer');
    }

    private function nameSimilarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 100.0;
        }

        similar_text($left, $right, $percent);
        $percent = (float) $percent;

        $shorter = mb_strlen($left) <= mb_strlen($right) ? $left : $right;
        $longer = $shorter === $left ? $right : $left;
        if (mb_strlen($shorter) >= 8 && str_contains($longer, $shorter)) {
            $percent = max($percent, 82.0);
        }

        return $percent;
    }

    private function dealerSuggestionLabel(Dealer $dealer, bool $includeEmployee = false): string
    {
        $label = trim((string) $dealer->firm_name);
        if (filled($dealer->dealer_code)) {
            $label .= ' ('.$dealer->dealer_code.')';
        }

        if ($includeEmployee) {
            $employee = $dealer->assignedEmployee;
            if ($employee instanceof Employee) {
                $label .= ' — assigned to '.$employee->assignmentLabel();
            } else {
                $label .= ' — not assigned to this employee';
            }
        }

        return $label;
    }

    /**
     * @return Collection<int, Dealer>
     */
    private function dealersForSuggestions(): Collection
    {
        return $this->dealersForSuggestions ??= Dealer::query()
            ->with('assignedEmployee:id,full_name,employee_code')
            ->orderBy('firm_name')
            ->get();
    }

    /**
     * @param  Collection<int, Dealer>  $dealers
     * @return array{matched: bool, dealer: ?Dealer, reason: ?string}
     */
    private function matchDealer(string $tallyLedgerName, Collection $dealers): array
    {
        $normalized = TallyDealerMapping::normalizeName($tallyLedgerName);
        if ($normalized === '') {
            return [
                'matched' => false,
                'dealer' => null,
                'reason' => 'Tally ledger name is missing.',
            ];
        }

        $nameMatches = $dealers->filter(
            fn (Dealer $dealer): bool => TallyDealerMapping::normalizeName((string) $dealer->firm_name) === $normalized,
        );

        if ($nameMatches->count() === 1) {
            return [
                'matched' => true,
                'dealer' => $nameMatches->first(),
                'reason' => null,
            ];
        }

        if ($nameMatches->count() > 1) {
            return [
                'matched' => false,
                'dealer' => null,
                'reason' => 'Multiple assigned dealers match this Tally party.',
            ];
        }

        $mappedDealerId = TallyDealerMapping::query()
            ->where('tally_ledger_name_normalized', $normalized)
            ->value('dealer_id');
        if ($mappedDealerId !== null) {
            $mapped = $dealers->firstWhere('id', (int) $mappedDealerId);
            if ($mapped instanceof Dealer) {
                return [
                    'matched' => true,
                    'dealer' => $mapped,
                    'reason' => null,
                ];
            }

            return [
                'matched' => false,
                'dealer' => null,
                'reason' => 'This Tally party is not assigned to the selected employee.',
            ];
        }

        return [
            'matched' => false,
            'dealer' => null,
            'reason' => 'No assigned dealer matches this Tally party.',
        ];
    }

    /**
     * @return Collection<int, Dealer>
     */
    private function dealersForEmployee(int $employeeId): Collection
    {
        return Dealer::query()
            ->where('assigned_employee_id', $employeeId)
            ->with('tallyLedger')
            ->orderBy('firm_name')
            ->get();
    }

    private function requireEmployee(int $employeeId): Employee
    {
        $employee = Employee::query()->find($employeeId);
        if ($employee === null) {
            throw ValidationException::withMessages([
                'employee_id' => 'Select an assigned employee before importing Tally ledgers.',
            ]);
        }

        return $employee;
    }
}
