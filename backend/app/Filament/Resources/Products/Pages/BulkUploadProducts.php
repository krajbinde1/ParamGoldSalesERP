<?php

namespace App\Filament\Resources\Products\Pages;

use App\Exports\ProductImportErrorReportExport;
use App\Filament\Resources\Products\ProductResource;
use App\Services\Products\ProductBulkImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BulkUploadProducts extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = ProductResource::class;

    protected static ?string $title = 'Bulk Upload Products';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'bulk-upload';

    protected string $view = 'filament.resources.products.bulk-upload-products';

    public int $step = 1;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var list<array<string, mixed>> */
    public array $previewRows = [];

    /** @var array<string, int>|null */
    public ?array $summary = null;

    /** @var list<\App\Services\Products\ProductBulkImportRowError> */
    public array $failedRows = [];

    public bool $isImporting = false;

    public ?string $uploadedFilePath = null;

    public static function canAccess(array $parameters = []): bool
    {
        return ProductResource::canCreate();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('file')
                    ->label('Product File')
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToProducts')
                ->label('Back to Products')
                ->icon('heroicon-o-arrow-left')
                ->url(ProductResource::getUrl('index')),
        ];
    }

    public function previewUpload(): void
    {
        $data = $this->form->getState();
        $uploaded = $data['file'] ?? null;

        if (! $uploaded instanceof TemporaryUploadedFile) {
            Notification::make()
                ->danger()
                ->title('Upload failed')
                ->body('Please choose a product file to upload.')
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
            path: 'product-imports',
            name: 'preview-'.now()->format('YmdHis').'-'.$uploaded->getClientOriginalName(),
            options: 'local',
        );

        $absolutePath = Storage::disk('local')->path($storedPath);

        try {
            $this->previewRows = app(ProductBulkImportService::class)->preview($absolutePath);
            $this->uploadedFilePath = $absolutePath;
            $this->summary = null;
            $this->failedRows = [];
            $this->step = 2;
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
                ->body('Upload the product file again before importing.')
                ->send();

            return;
        }

        $this->isImporting = true;

        try {
            $result = app(ProductBulkImportService::class)->import($this->uploadedFilePath);
            $this->summary = $result->summary();
            $this->failedRows = $result->errors;
            $this->step = 3;

            Notification::make()
                ->success()
                ->title('Product import completed')
                ->body('Created: '.$result->created.' | Updated: '.$result->updated.' | Failed: '.$result->failed())
                ->send();
        } finally {
            $this->isImporting = false;
        }
    }

    public function resetUpload(): void
    {
        $this->step = 1;
        $this->previewRows = [];
        $this->summary = null;
        $this->failedRows = [];
        $this->uploadedFilePath = null;
        $this->form->fill();
    }

    public function downloadErrorReport(): BinaryFileResponse
    {
        abort_if($this->failedRows === [], 404);

        return Excel::download(
            new ProductImportErrorReportExport($this->failedRows),
            'product-import-errors-'.now()->format('YmdHis').'.xlsx',
        );
    }
}
