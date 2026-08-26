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
     * @return array{employee: Employee, assigned_dealers: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function preview(string $path, int $employeeId): array
    {
        $employee = $this->requireEmployee($employeeId);
        $dealers = $this->dealersForEmployee($employeeId);
        $parsedLedgers = $this->parser->parseAll($path);

        return [
            'employee' => $employee,
            'assigned_dealers' => $this->assignedDealers($employeeId),
            'rows' => array_map(
                fn (TallyLedgerParseResult $parsed): array => $this->rowFromParsed($parsed, $dealers),
                $parsedLedgers,
            ),
        ];
    }

    /**
     * @return array{employee: Employee, assigned_dealers: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public function import(string $path, int $employeeId, User $actor, string $originalFilename): array
    {
        $employee = $this->requireEmployee($employeeId);
        $dealers = $this->dealersForEmployee($employeeId);
        $parsedLedgers = $this->parser->parseAll($path);
        $rows = [];

        foreach ($parsedLedgers as $parsed) {
            $row = $this->rowFromParsed($parsed, $dealers);

            if (! $row['matched'] || $row['dealer_id'] === null) {
                $rows[] = $this->withImportOutcome($row, imported: false, importedCount: 0, duplicateCount: 0, failed: false);

                continue;
            }

            $dealer = $dealers->firstWhere('id', (int) $row['dealer_id']);
            if (! $dealer instanceof Dealer || (int) $dealer->assigned_employee_id !== $employeeId) {
                $rows[] = $this->withImportOutcome(
                    array_merge($row, [
                        'matched' => false,
                        'match_label' => 'Not Matched',
                        'dealer_id' => null,
                        'reason' => 'This Tally party is not assigned to the selected employee.',
                    ]),
                    imported: false,
                    importedCount: 0,
                    duplicateCount: 0,
                    failed: false,
                );

                continue;
            }

            $ledgerPreview = $this->importer->previewParsed($parsed, $dealer);
            if (empty($ledgerPreview['can_import'])) {
                $rows[] = $this->withImportOutcome(
                    array_merge($row, [
                        'reason' => collect($ledgerPreview['parse_errors'] ?? [])->first() ?: 'Tally ledger parsing is incomplete.',
                    ]),
                    imported: false,
                    importedCount: 0,
                    duplicateCount: 0,
                    failed: true,
                );

                continue;
            }

            try {
                $result = $this->importer->importPreview(
                    $ledgerPreview,
                    $dealer,
                    $actor,
                    $originalFilename,
                );
                $dealer->refresh()->load('tallyLedger');
                $rows[] = $this->withImportOutcome(
                    array_merge($row, [
                        'dealer_name' => (string) $dealer->firm_name,
                        'closing_balance_label' => (string) ($result['summary']['current_outstanding_label'] ?? $row['closing_balance_label']),
                        'tally_status' => $dealer->tallyLedgerImportStatusLabel(),
                    ]),
                    imported: true,
                    importedCount: (int) $result['imported_count'],
                    duplicateCount: (int) $result['duplicate_count'],
                    failed: false,
                );
            } catch (ValidationException $exception) {
                $rows[] = $this->withImportOutcome(
                    array_merge($row, [
                        'reason' => collect($exception->errors())->flatten()->first() ?: 'Unable to import this ledger.',
                    ]),
                    imported: false,
                    importedCount: 0,
                    duplicateCount: 0,
                    failed: true,
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
     * @param  Collection<int, Dealer>  $dealers
     * @return array<string, mixed>
     */
    private function rowFromParsed(TallyLedgerParseResult $parsed, Collection $dealers): array
    {
        $match = $this->matchDealer($parsed->tallyLedgerName, $dealers);
        $dealer = $match['dealer'];
        $preview = $this->importer->previewParsed($parsed, $dealer);
        $closingLabel = IndianCurrency::formatDrCr((float) ($preview['erp_closing_signed'] ?? $parsed->calculatedClosingSigned()));

        return [
            'tally_ledger_name' => $parsed->tallyLedgerName !== '' ? $parsed->tallyLedgerName : '—',
            'dealer_id' => $dealer?->id,
            'dealer_name' => $dealer?->firm_name ?? ($parsed->tallyLedgerName !== '' ? $parsed->tallyLedgerName : '—'),
            'dealer_code' => $dealer?->dealer_code,
            'matched' => $match['matched'],
            'match_label' => $match['matched'] ? 'Matched' : 'Not Matched',
            'import_status_label' => '',
            'tally_status' => $dealer?->tallyLedgerImportStatusLabel() ?? 'Not Imported',
            'transaction_count' => count($parsed->transactions),
            'imported_count' => 0,
            'duplicate_count' => 0,
            'closing_balance_label' => $closingLabel,
            'reason' => $match['reason'] ?? (empty($preview['can_import'])
                ? (collect($preview['parse_errors'] ?? [])->first() ?: 'Tally ledger parsing is incomplete.')
                : null),
            'can_import' => $match['matched'] && ! empty($preview['can_import']),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withImportOutcome(
        array $row,
        bool $imported,
        int $importedCount,
        int $duplicateCount,
        bool $failed,
    ): array {
        $row['imported_count'] = $importedCount;
        $row['duplicate_count'] = $duplicateCount;
        $row['can_import'] = false;

        if ($failed) {
            $row['import_status_label'] = 'Failed';

            return $row;
        }

        if ($imported) {
            $row['import_status_label'] = 'Ledger Imported';
            $row['tally_status'] = 'Ledger Imported';
            $row['reason'] = null;

            return $row;
        }

        $row['import_status_label'] = 'Not Imported';

        return $row;
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
