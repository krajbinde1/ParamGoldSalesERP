<?php

namespace App\Filament\Pages;

use App\Enums\InventoryBulkImportType;
use App\Exports\Inventory\InventoryBulkImportErrorReportExport;
use App\Exports\Inventory\InventoryBulkImportTemplateExport;
use App\Exports\Inventory\InventoryCodeMappingExport;
use App\Models\User;
use App\Services\Inventory\BulkImport\InventoryBulkImportManager;
use App\Services\Inventory\BulkImport\InventoryBulkImportReadiness;
use App\Services\Inventory\BulkImport\InventoryBulkImportRowError;
use App\Services\Inventory\BulkImport\InventoryBulkImportTemplate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

class InventoryBulkImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|UnitEnum|null $navigationGroup = 'Inventory & Manufacturing';

    protected static ?string $navigationParentItem = 'Bulk Import';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Opening Stock Import';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static ?string $slug = 'inventory-bulk-import';

    protected static ?string $title = 'Inventory Bulk Import';

    protected string $view = 'filament.pages.inventory-bulk-import';

    /** Upload wizard phase: 1 upload, 2 preview, 3 summary */
    public int $wizardStep = 1;

    /** @var array<string, mixed> */
    public array $data = [];

    public string $importType = InventoryBulkImportType::RawMaterial->value;

    /** @var list<array<string, mixed>> */
    public array $previewRows = [];

    /** @var array<string, int>|null */
    public ?array $previewCounts = null;

    /** @var array<string, int>|null */
    public ?array $summary = null;

    /** @var list<array{row_number:int,data:array<string, mixed>,reason:string}> */
    public array $failedRows = [];

    public bool $isImporting = false;

    public ?string $uploadedFilePath = null;

    public ?string $errorCacheKey = null;

    public ?string $mappingCacheKey = null;

    /**
     * Per-module UI status: not_started|validated|imported
     *
     * @var array<string, string>
     */
    public array $moduleStatuses = [];

    /**
     * @var array<string, array{imported:int,failed:int}>
     */
    public array $moduleCounts = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageInventoryMasters();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        foreach (InventoryBulkImportType::cases() as $type) {
            $this->moduleStatuses[$type->value] = 'not_started';
            $this->moduleCounts[$type->value] = ['imported' => 0, 'failed' => 0];
        }

        $this->form->fill([]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('file')
                    ->label('Excel File')
                    ->helperText('Supported formats: .xlsx, .xls, .csv — Opening Stock only (no Material Inward). Codes are auto-generated for masters.')
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required()
                    ->storeFiles(false),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadCombinedMapping')
                ->label('Download Master Code Mapping')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->action(fn (): BinaryFileResponse => Excel::download(
                    new InventoryCodeMappingExport(
                        type: InventoryBulkImportType::RawMaterial,
                        combined: true,
                    ),
                    'master-code-mapping-'.now()->format('YmdHis').'.xlsx',
                )),
        ];
    }

    public function selectModule(string $type): void
    {
        if (InventoryBulkImportType::tryFrom($type) === null) {
            return;
        }

        if ($type === InventoryBulkImportType::Bom->value) {
            $block = app(InventoryBulkImportReadiness::class)->blockReason(InventoryBulkImportType::Bom);
            if ($block !== null) {
                Notification::make()
                    ->warning()
                    ->title('BOM import not ready')
                    ->body($block)
                    ->send();

                return;
            }
        }

        $this->importType = $type;
        $this->resetUpload(keepModule: true);
    }

    public function downloadTemplateFor(string $typeValue): BinaryFileResponse
    {
        $type = InventoryBulkImportType::from($typeValue);

        return Excel::download(
            new InventoryBulkImportTemplateExport($type),
            InventoryBulkImportTemplate::downloadFilename($type),
        );
    }

    public function downloadCodeMappingFor(string $typeValue): BinaryFileResponse
    {
        $type = InventoryBulkImportType::from($typeValue);

        $rows = null;
        if ($this->mappingCacheKey !== null && $this->importType === $typeValue) {
            $cached = Cache::get($this->mappingCacheKey);
            if (is_array($cached['mappings'] ?? null) && ($cached['type'] ?? null) === $typeValue) {
                $rows = $cached['mappings'];
            }
        }

        return Excel::download(
            new InventoryCodeMappingExport($type, $rows),
            str_replace('_', '-', $typeValue).'-code-mapping-'.now()->format('YmdHis').'.xlsx',
        );
    }

    public function previewUpload(): void
    {
        $type = $this->resolvedType();
        $block = app(InventoryBulkImportReadiness::class)->blockReason($type);
        if ($block !== null) {
            Notification::make()->danger()->title('Import blocked')->body($block)->send();

            return;
        }

        $data = $this->form->getState();
        $uploaded = $data['file'] ?? null;

        if (! $uploaded instanceof TemporaryUploadedFile) {
            Notification::make()
                ->danger()
                ->title('Upload failed')
                ->body('Please choose an Excel file to upload.')
                ->send();

            return;
        }

        $realPath = $uploaded->getRealPath();

        if ($realPath === false) {
            Notification::make()
                ->danger()
                ->title('Upload failed')
                ->body('Unable to read the uploaded file.')
                ->send();

            return;
        }

        $storedPath = $uploaded->storeAs(
            path: 'inventory-imports',
            name: 'preview-'.now()->format('YmdHis').'-'.$uploaded->getClientOriginalName(),
            options: 'local',
        );

        $absolutePath = Storage::disk('local')->path($storedPath);

        try {
            $preview = app(InventoryBulkImportManager::class)->preview($absolutePath, $type);
            $this->previewRows = $this->limitPreviewRows($preview->rows);
            $this->previewCounts = $preview->counts;
            $this->uploadedFilePath = $absolutePath;
            $this->summary = null;
            $this->failedRows = [];
            $this->errorCacheKey = null;
            $this->wizardStep = 2;
            $this->moduleStatuses[$type->value] = 'validated';
        } catch (ValidationException $exception) {
            Storage::disk('local')->delete($storedPath);

            Notification::make()
                ->danger()
                ->title('Unable to read file')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Invalid import file.')
                ->send();
        }
    }

    public function runImport(): void
    {
        if ($this->uploadedFilePath === null || ! is_file($this->uploadedFilePath)) {
            Notification::make()
                ->danger()
                ->title('Import failed')
                ->body('Upload the file again before confirming import.')
                ->send();

            return;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $type = $this->resolvedType();
        $this->isImporting = true;

        try {
            $result = app(InventoryBulkImportManager::class)->import(
                $this->uploadedFilePath,
                $type,
                $user,
            );

            $this->summary = $result->summary();
            $this->failedRows = array_map(
                fn (InventoryBulkImportRowError $error): array => [
                    'row_number' => $error->rowNumber,
                    'data' => $error->rowData,
                    'reason' => $error->reason,
                ],
                $result->errors,
            );

            $this->errorCacheKey = 'inventory-bulk-import-errors-'.uniqid('', true);
            Cache::put($this->errorCacheKey, [
                'type' => $this->importType,
                'errors' => $this->failedRows,
            ], now()->addHours(6));

            $this->mappingCacheKey = 'inventory-bulk-import-mappings-'.uniqid('', true);
            Cache::put($this->mappingCacheKey, [
                'type' => $this->importType,
                'mappings' => $result->mappings,
            ], now()->addHours(6));

            $this->moduleStatuses[$type->value] = 'imported';
            $this->moduleCounts[$type->value] = [
                'imported' => $result->imported,
                'failed' => $result->failed,
            ];

            $this->wizardStep = 3;

            Notification::make()
                ->success()
                ->title('Import completed')
                ->body('Imported: '.$result->imported.' | Skipped: '.$result->skipped.' | Failed: '.$result->failed)
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Import blocked')
                ->body(collect($exception->errors())->flatten()->first() ?? 'Unable to import.')
                ->send();
        } finally {
            $this->isImporting = false;
        }
    }

    public function resetUpload(bool $keepModule = false): void
    {
        $this->wizardStep = 1;
        $this->previewRows = [];
        $this->previewCounts = null;
        $this->summary = null;
        $this->failedRows = [];
        $this->uploadedFilePath = null;
        $this->errorCacheKey = null;
        $this->form->fill([]);

        if (! $keepModule) {
            // keep selected module
        }
    }

    public function downloadErrorReport(): BinaryFileResponse
    {
        $payload = $this->errorCacheKey !== null ? Cache::get($this->errorCacheKey) : null;
        $failed = is_array($payload['errors'] ?? null) ? $payload['errors'] : $this->failedRows;
        $typeValue = is_string($payload['type'] ?? null) ? $payload['type'] : $this->importType;
        $type = InventoryBulkImportType::from($typeValue);

        abort_if($failed === [], 404);

        $errors = array_map(
            fn (array $row): InventoryBulkImportRowError => new InventoryBulkImportRowError(
                rowNumber: (int) $row['row_number'],
                rowData: $row['data'] ?? [],
                reason: (string) ($row['reason'] ?? ''),
            ),
            $failed,
        );

        return Excel::download(
            new InventoryBulkImportErrorReportExport($type, $errors),
            'inventory-import-errors-'.now()->format('YmdHis').'.xlsx',
        );
    }

    public function downloadLastImportMapping(): BinaryFileResponse
    {
        return $this->downloadCodeMappingFor($this->importType);
    }

    public function resolvedType(): InventoryBulkImportType
    {
        return InventoryBulkImportType::from($this->importType);
    }

    public function moduleLabel(): string
    {
        return $this->resolvedType()->label();
    }

    /**
     * @return list<array{type:string,label:string,step:int,status:string,imported:int,failed:int,blocked:bool,block_reason:?string}>
     */
    public function sequenceSteps(): array
    {
        $readiness = app(InventoryBulkImportReadiness::class)->snapshot();
        $steps = [];
        $index = 1;

        foreach (InventoryBulkImportType::cases() as $type) {
            $blocked = $type === InventoryBulkImportType::Bom && ! $readiness['bom_ready'];
            $steps[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'step' => $index++,
                'status' => $this->moduleStatuses[$type->value] ?? 'not_started',
                'imported' => $this->moduleCounts[$type->value]['imported'] ?? 0,
                'failed' => $this->moduleCounts[$type->value]['failed'] ?? 0,
                'blocked' => $blocked,
                'block_reason' => $blocked ? $readiness['bom_block_reason'] : null,
            ];
        }

        return $steps;
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'validated' => 'Validated',
            'imported' => 'Imported',
            default => 'Not Started',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function limitPreviewRows(array $rows): array
    {
        if (count($rows) <= 250) {
            return $rows;
        }

        $invalid = array_values(array_filter($rows, fn (array $row): bool => ! ($row['is_valid'] ?? false)));
        $valid = array_values(array_filter($rows, fn (array $row): bool => (bool) ($row['is_valid'] ?? false)));

        return array_slice(array_merge(array_slice($invalid, 0, 150), array_slice($valid, 0, 100)), 0, 250);
    }
}
