<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Exports\EmployeeImportErrorReportExport;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Services\Employees\EmployeeBulkImportService;
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

class BulkUploadEmployees extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = EmployeeResource::class;

    protected static ?string $title = 'Bulk Upload Employees';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'bulk-upload';

    protected string $view = 'filament.resources.employees.bulk-upload-employees';

    public int $step = 1;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var list<array<string, mixed>> */
    public array $previewRows = [];

    /** @var array<string, int>|null */
    public ?array $summary = null;

    /** @var list<\App\Services\Employees\EmployeeBulkImportRowError> */
    public array $failedRows = [];

    public bool $isImporting = false;

    public ?string $uploadedFilePath = null;

    public static function canAccess(array $parameters = []): bool
    {
        return EmployeeResource::canCreate();
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
                    ->label('Employee File')
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
            Action::make('backToEmployees')
                ->label('Back to Employees')
                ->icon('heroicon-o-arrow-left')
                ->url(EmployeeResource::getUrl('index')),
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
                ->body('Please choose an employee file to upload.')
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
            path: 'employee-imports',
            name: 'preview-'.now()->format('YmdHis').'-'.$uploaded->getClientOriginalName(),
            options: 'local',
        );

        $absolutePath = Storage::disk('local')->path($storedPath);

        try {
            $this->previewRows = app(EmployeeBulkImportService::class)->preview($absolutePath);
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
                ->body('Upload the employee file again before importing.')
                ->send();

            return;
        }

        $this->isImporting = true;

        try {
            $result = app(EmployeeBulkImportService::class)->import($this->uploadedFilePath);
            $this->summary = $result->summary();
            $this->failedRows = $result->errors;
            $this->step = 3;

            Notification::make()
                ->success()
                ->title('Employee import completed')
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
            new EmployeeImportErrorReportExport($this->failedRows),
            'employee-import-errors-'.now()->format('YmdHis').'.xlsx',
        );
    }
}
