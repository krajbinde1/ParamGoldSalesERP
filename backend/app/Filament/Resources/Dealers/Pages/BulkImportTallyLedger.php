<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Exports\Dealers\TallyBulkNotMatchedExport;
use App\Filament\Resources\Dealers\DealerResource;
use App\Models\Employee;
use App\Services\TallyLedger\TallyBulkLedgerImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BulkImportTallyLedger extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = DealerResource::class;

    protected static ?string $title = 'Bulk Tally Ledger Import';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'bulk-import-tally-ledger';

    protected string $view = 'filament.resources.dealers.pages.bulk-import-tally-ledger';

    public int $step = 1;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var list<array<string, mixed>> */
    public array $previewRows = [];

    /** @var list<array<string, mixed>> */
    public array $resultRows = [];

    /** @var list<array{path: string, original_filename: string}> */
    public array $uploadedFiles = [];

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return ($user?->isAdminUser() ?? false) || ($user?->isDirectorUser() ?? false);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Bulk Tally Ledger Import';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('employee_id')
                    ->label('Assigned Employee')
                    ->helperText('Choose the sales employee first. Only dealers assigned to this employee will be matched.')
                    ->options(fn (): array => $this->employeeOptions())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (): void {
                        $this->resetAfterEmployeeChange();
                    }),
                FileUpload::make('files')
                    ->label('Tally Ledger Excel files')
                    ->helperText('Select multiple .xlsx / .xls files together. Each file is one dealer ledger and is matched by the ledger name inside the Excel.')
                    ->acceptedFileTypes([
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->multiple()
                    ->maxFiles(100)
                    ->required()
                    ->storeFiles(false)
                    ->visible(fn (Get $get): bool => filled($get('employee_id'))),
            ])
            ->statePath('data');
    }

    /**
     * @return list<array{id: int, dealer_code: ?string, firm_name: string, village: ?string, tally_status: string}>
     */
    public function assignedDealers(): array
    {
        $employeeId = (int) ($this->data['employee_id'] ?? 0);
        if ($employeeId < 1) {
            return [];
        }

        return app(TallyBulkLedgerImportService::class)->assignedDealers($employeeId);
    }

    public function selectedEmployeeLabel(): ?string
    {
        $employeeId = (int) ($this->data['employee_id'] ?? 0);
        if ($employeeId < 1) {
            return null;
        }

        $employee = Employee::query()->find($employeeId);

        return $employee?->assignmentLabel();
    }

    /**
     * @return array{total: int, matched: int, not_matched: int, error: int}
     */
    public function previewSummary(): array
    {
        $rows = collect($this->currentRows());

        return [
            'total' => $rows->count(),
            'matched' => $rows->where('status', TallyBulkLedgerImportService::STATUS_MATCHED)->count(),
            'not_matched' => $rows->where('status', TallyBulkLedgerImportService::STATUS_NOT_MATCHED)->count(),
            'error' => $rows->where('status', TallyBulkLedgerImportService::STATUS_ERROR)->count(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function matchedRows(): array
    {
        return collect($this->currentRows())
            ->where('status', TallyBulkLedgerImportService::STATUS_MATCHED)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function notMatchedRows(): array
    {
        return collect($this->currentRows())
            ->where('status', TallyBulkLedgerImportService::STATUS_NOT_MATCHED)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function errorRows(): array
    {
        return collect($this->currentRows())
            ->where('status', TallyBulkLedgerImportService::STATUS_ERROR)
            ->values()
            ->all();
    }

    public function downloadNotMatchedReport(): BinaryFileResponse
    {
        $rows = $this->notMatchedRows();
        abort_if($rows === [], 404);

        $employee = Employee::query()->find((int) ($this->data['employee_id'] ?? 0));
        $slug = Str::slug((string) ($employee?->full_name ?: 'employee')) ?: 'employee';

        return Excel::download(
            new TallyBulkNotMatchedExport($rows),
            'Tally_Not_Matched_'.$slug.'_'.now('Asia/Kolkata')->format('Y-m-d').'.xlsx',
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToDealers')
                ->label('Back to Dealers')
                ->icon('heroicon-o-arrow-left')
                ->url(DealerResource::getUrl('index')),
            Action::make('importHistory')
                ->label('Import History')
                ->url(DealerResource::getUrl('tally-import-history'))
                ->color('gray'),
        ];
    }

    public function previewUpload(): void
    {
        $employeeId = (int) ($this->data['employee_id'] ?? 0);
        if ($employeeId < 1) {
            Notification::make()->danger()->title('Select an employee')->body('Choose the assigned employee before uploading Tally Excel files.')->send();

            return;
        }

        $files = $this->storeUploadedFiles();
        if ($files === []) {
            Notification::make()->danger()->title('Upload failed')->body('Please choose one or more Tally Excel files.')->send();

            return;
        }

        try {
            $preview = app(TallyBulkLedgerImportService::class)->previewFiles($files, $employeeId);
            $this->previewRows = $preview['rows'];
            $this->uploadedFiles = $files;
            $this->resultRows = [];
            $this->step = 2;
        } catch (ValidationException $exception) {
            $this->deleteStoredFiles($files);
            Notification::make()
                ->danger()
                ->title('Unable to read Tally Excel files')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Invalid import files.')
                ->send();
        }
    }

    public function runImport(): void
    {
        $employeeId = (int) ($this->data['employee_id'] ?? 0);
        if ($employeeId < 1) {
            Notification::make()->danger()->title('Select an employee')->body('Choose the assigned employee before importing.')->send();

            return;
        }

        if ($this->uploadedFiles === []) {
            Notification::make()->danger()->title('Import failed')->body('Upload the Tally Excel files again before importing.')->send();

            return;
        }

        try {
            $result = app(TallyBulkLedgerImportService::class)->importFiles(
                files: $this->uploadedFiles,
                employeeId: $employeeId,
                actor: auth()->user(),
            );
            $this->resultRows = $result['rows'];
            $this->step = 3;

            $imported = collect($result['rows'])->where('import_status_label', 'Ledger Imported')->count();
            $notMatched = collect($result['rows'])->where('status', TallyBulkLedgerImportService::STATUS_NOT_MATCHED)->count();
            $duplicates = (int) collect($result['rows'])->sum('duplicate_count');
            $errors = collect($result['rows'])->where('status', TallyBulkLedgerImportService::STATUS_ERROR)->count();

            Notification::make()
                ->success()
                ->title('Bulk Tally import finished')
                ->body('Imported: '.$imported.' · Not matched: '.$notMatched.' · Duplicates skipped: '.$duplicates.' · Error: '.$errors)
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Import failed')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Unable to import these files.')
                ->send();
        }
    }

    public function resetUpload(): void
    {
        $employeeId = $this->data['employee_id'] ?? null;
        $this->step = 1;
        $this->previewRows = [];
        $this->resultRows = [];
        $this->uploadedFiles = [];
        $this->data = ['employee_id' => $employeeId];
        $this->form->fill($this->data);
    }

    private function resetAfterEmployeeChange(): void
    {
        $this->step = 1;
        $this->previewRows = [];
        $this->resultRows = [];
        $this->uploadedFiles = [];
        $this->data['files'] = null;
    }

    /**
     * @return list<array{path: string, original_filename: string}>
     */
    private function storeUploadedFiles(): array
    {
        $data = $this->form->getState();
        $uploaded = $data['files'] ?? [];
        if ($uploaded instanceof TemporaryUploadedFile) {
            $uploaded = [$uploaded];
        }

        if (! is_array($uploaded) || $uploaded === []) {
            return [];
        }

        $stored = [];
        $stamp = now()->format('YmdHis');

        foreach (array_values($uploaded) as $index => $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $storedPath = $file->storeAs(
                path: 'tally-ledger-imports',
                name: 'bulk-'.$stamp.'-'.$index.'-'.$originalName,
                options: 'local',
            );

            $stored[] = [
                'path' => Storage::disk('local')->path($storedPath),
                'original_filename' => $originalName,
            ];
        }

        return $stored;
    }

    /**
     * @param  list<array{path: string, original_filename: string}>  $files
     */
    private function deleteStoredFiles(array $files): void
    {
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currentRows(): array
    {
        return $this->step === 3 ? $this->resultRows : $this->previewRows;
    }

    /**
     * @return array<int, string>
     */
    private function employeeOptions(): array
    {
        return Employee::query()
            ->whereHas('assignedDealers')
            ->orderBy('employee_code')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [(int) $employee->id => $employee->assignmentLabel()])
            ->all();
    }
}
