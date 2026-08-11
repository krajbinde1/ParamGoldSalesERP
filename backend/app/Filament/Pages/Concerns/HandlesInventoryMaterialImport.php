<?php

namespace App\Filament\Pages\Concerns;

use App\Enums\InventoryBulkImportType;
use App\Exports\Inventory\InventoryBulkImportErrorReportExport;
use App\Exports\Inventory\InventoryBulkImportTemplateExport;
use App\Models\User;
use App\Services\Inventory\BulkImport\InventoryBulkImportManager;
use App\Services\Inventory\BulkImport\InventoryBulkImportRowError;
use App\Services\Inventory\BulkImport\InventoryBulkImportTemplate;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Shared Download → Upload → Preview → Confirm → Result flow for inventory master imports.
 */
trait HandlesInventoryMaterialImport
{
    /**
     * home = landing, upload = file select, preview = validate, result = post-import
     */
    public string $phase = 'home';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var list<array<string, mixed>> */
    public array $previewRows = [];

    /** @var array<string, int>|null */
    public ?array $previewCounts = null;

    /** @var array<string, int>|null */
    public ?array $summary = null;

    /**
     * @var list<array{material_name:string,material_code:string,opening_quantity:float|int|string,opening_value:float|int|string,status:string}>
     */
    public array $importedRows = [];

    /** @var list<array{row_number:int,data:array<string, mixed>,reason:string}> */
    public array $failedRows = [];

    public bool $isImporting = false;

    public ?string $uploadedFilePath = null;

    public ?string $errorCacheKey = null;

    abstract protected static function importType(): InventoryBulkImportType;

    abstract protected static function importHeading(): string;

    abstract protected static function importDescription(): string;

    abstract protected static function previewNameField(): string;

    abstract protected static function resultNameLabel(): string;

    abstract protected static function resultCodeLabel(): string;

    abstract protected static function uploadFilePrefix(): string;

    abstract protected static function errorCachePrefix(): string;

    abstract protected static function errorReportFilenamePrefix(): string;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageInventoryMasters();
    }

    public static function shouldRegisterNavigation(): bool
    {
        // Import pages remain available by URL for deep links/tests, but are no longer
        // shown under a separate Bulk Upload sidebar section. Use Material Master header actions.
        return false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([]);
    }

    public function configureImportUploadForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('file')
                    ->label('Excel File')
                    ->helperText('Supported formats: .xlsx, .xls, .csv')
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

    public function getImportHeading(): string
    {
        return static::importHeading();
    }

    public function getImportDescription(): string
    {
        return static::importDescription();
    }

    public function getPreviewNameField(): string
    {
        return static::previewNameField();
    }

    public function getResultNameLabel(): string
    {
        return static::resultNameLabel();
    }

    public function getResultCodeLabel(): string
    {
        return static::resultCodeLabel();
    }

    public function downloadTemplate(): BinaryFileResponse
    {
        $type = static::importType();

        return Excel::download(
            new InventoryBulkImportTemplateExport($type),
            InventoryBulkImportTemplate::downloadFilename($type),
        );
    }

    public function startImport(): void
    {
        $this->resetState();
        $this->phase = 'upload';
    }

    public function cancelImport(): void
    {
        $this->resetState();
        $this->phase = 'home';
    }

    public function previewUpload(): void
    {
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
            name: static::uploadFilePrefix().'-preview-'.now()->format('YmdHis').'-'.$uploaded->getClientOriginalName(),
            options: 'local',
        );

        $absolutePath = Storage::disk('local')->path($storedPath);

        try {
            $preview = app(InventoryBulkImportManager::class)->preview(
                $absolutePath,
                static::importType(),
            );

            $this->previewRows = $this->limitPreviewRows($preview->rows);
            $this->previewCounts = $preview->counts;
            $this->uploadedFilePath = $absolutePath;
            $this->summary = null;
            $this->importedRows = [];
            $this->failedRows = [];
            $this->errorCacheKey = null;
            $this->phase = 'preview';
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

        $this->isImporting = true;

        try {
            $result = app(InventoryBulkImportManager::class)->import(
                $this->uploadedFilePath,
                static::importType(),
                $user,
            );

            $this->summary = $result->summary();
            $this->importedRows = array_values(array_map(
                fn (array $mapping): array => $this->normalizeImportedMapping($mapping),
                $result->mappings,
            ));
            $this->failedRows = array_map(
                fn (InventoryBulkImportRowError $error): array => [
                    'row_number' => $error->rowNumber,
                    'data' => $error->rowData,
                    'reason' => $error->reason,
                ],
                $result->errors,
            );

            $this->errorCacheKey = static::errorCachePrefix().'-'.uniqid('', true);
            Cache::put($this->errorCacheKey, [
                'errors' => $this->failedRows,
            ], now()->addHours(6));

            $this->phase = 'result';

            Notification::make()
                ->success()
                ->title('Import completed')
                ->body('Imported: '.$result->imported.' | Failed: '.$result->failed)
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

    public function downloadErrorReport(): BinaryFileResponse
    {
        $payload = $this->errorCacheKey !== null ? Cache::get($this->errorCacheKey) : null;
        $failed = is_array($payload['errors'] ?? null) ? $payload['errors'] : $this->failedRows;

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
            new InventoryBulkImportErrorReportExport(static::importType(), $errors),
            static::errorReportFilenamePrefix().'-'.now()->format('YmdHis').'.xlsx',
        );
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array{material_name:string,material_code:string,opening_quantity:float|int|string,opening_value:float|int|string,status:string}
     */
    protected function normalizeImportedMapping(array $mapping): array
    {
        return [
            'material_name' => (string) (
                $mapping['material_name']
                ?? $mapping['packaging_name']
                ?? $mapping['product_name']
                ?? ''
            ),
            'material_code' => (string) (
                $mapping['material_code']
                ?? $mapping['packaging_code']
                ?? $mapping['finished_product_code']
                ?? ''
            ),
            'opening_quantity' => $mapping['opening_quantity'] ?? 0,
            'opening_value' => $mapping['opening_value'] ?? 0,
            'status' => 'Success',
        ];
    }

    private function resetState(): void
    {
        $this->previewRows = [];
        $this->previewCounts = null;
        $this->summary = null;
        $this->importedRows = [];
        $this->failedRows = [];
        $this->uploadedFilePath = null;
        $this->errorCacheKey = null;
        $this->form->fill([]);
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
