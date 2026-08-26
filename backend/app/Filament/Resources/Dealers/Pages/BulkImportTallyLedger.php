<?php

namespace App\Filament\Resources\Dealers\Pages;

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
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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

    public ?string $uploadedFilePath = null;

    public ?string $originalFilename = null;

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
                FileUpload::make('file')
                    ->label('Tally Ledger Excel')
                    ->helperText('Upload one Tally Excel that contains ledgers for this employee’s dealers. Unmatched parties are not imported.')
                    ->acceptedFileTypes([
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
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
            Notification::make()->danger()->title('Select an employee')->body('Choose the assigned employee before uploading the Tally Excel.')->send();

            return;
        }

        $data = $this->form->getState();
        $uploaded = $data['file'] ?? null;

        if (! $uploaded instanceof TemporaryUploadedFile) {
            Notification::make()->danger()->title('Upload failed')->body('Please choose a Tally Excel file.')->send();

            return;
        }

        $realPath = $uploaded->getRealPath();
        if ($realPath === false) {
            Notification::make()->danger()->title('Upload failed')->body('Unable to read the uploaded file.')->send();

            return;
        }

        $originalName = $uploaded->getClientOriginalName();
        $storedPath = $uploaded->storeAs(
            path: 'tally-ledger-imports',
            name: 'bulk-preview-'.now()->format('YmdHis').'-'.$originalName,
            options: 'local',
        );
        $absolutePath = Storage::disk('local')->path($storedPath);

        try {
            $preview = app(TallyBulkLedgerImportService::class)->preview($absolutePath, $employeeId);
            $this->previewRows = $preview['rows'];
            $this->uploadedFilePath = $absolutePath;
            $this->originalFilename = $originalName;
            $this->resultRows = [];
            $this->step = 2;
        } catch (ValidationException $exception) {
            Storage::disk('local')->delete($storedPath);
            Notification::make()
                ->danger()
                ->title('Unable to read Tally Excel')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Invalid import file.')
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

        if ($this->uploadedFilePath === null || ! is_file($this->uploadedFilePath)) {
            Notification::make()->danger()->title('Import failed')->body('Upload the Tally Excel again before importing.')->send();

            return;
        }

        try {
            $result = app(TallyBulkLedgerImportService::class)->import(
                path: $this->uploadedFilePath,
                employeeId: $employeeId,
                actor: auth()->user(),
                originalFilename: $this->originalFilename ?: 'tally-ledger.xlsx',
            );
            $this->resultRows = $result['rows'];
            $this->step = 3;

            $importedDealers = collect($result['rows'])->where('import_status_label', 'Ledger Imported')->count();
            $failed = collect($result['rows'])->where('import_status_label', 'Failed')->count();
            $unmatched = collect($result['rows'])->where('matched', false)->count();

            Notification::make()
                ->success()
                ->title('Bulk Tally import finished')
                ->body('Imported: '.$importedDealers.' · Failed: '.$failed.' · Not matched: '.$unmatched)
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Import failed')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Unable to import this file.')
                ->send();
        }
    }

    public function resetUpload(): void
    {
        $employeeId = $this->data['employee_id'] ?? null;
        $this->step = 1;
        $this->previewRows = [];
        $this->resultRows = [];
        $this->uploadedFilePath = null;
        $this->originalFilename = null;
        $this->data = ['employee_id' => $employeeId];
        $this->form->fill($this->data);
    }

    private function resetAfterEmployeeChange(): void
    {
        $this->step = 1;
        $this->previewRows = [];
        $this->resultRows = [];
        $this->uploadedFilePath = null;
        $this->originalFilename = null;
        $this->data['file'] = null;
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
