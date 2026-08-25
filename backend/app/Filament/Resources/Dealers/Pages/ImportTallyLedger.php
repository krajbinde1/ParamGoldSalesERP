<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Resources\Dealers\DealerResource;
use App\Models\Dealer;
use App\Services\TallyLedger\TallyLedgerImportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ImportTallyLedger extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = DealerResource::class;

    protected static ?string $title = 'Import Tally Ledger';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'import-tally-ledger';

    protected string $view = 'filament.resources.dealers.pages.import-tally-ledger';

    public Dealer|int|string $record;

    public int $step = 1;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed>|null */
    public ?array $preview = null;

    public ?string $uploadedFilePath = null;

    public ?string $originalFilename = null;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return ($user?->isAdminUser() ?? false) || ($user?->isDirectorUser() ?? false);
    }

    public function mount(int|string|Dealer $record): void
    {
        abort_unless(static::canAccess(), 403);
        $this->record = $record instanceof Dealer
            ? $record
            : Dealer::query()->findOrFail($record);
        $this->form->fill();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Import Tally Ledger';
    }

    public function dealer(): Dealer
    {
        if ($this->record instanceof Dealer) {
            return $this->record;
        }

        return Dealer::query()->findOrFail($this->record);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('file')
                    ->label('Tally Ledger Excel')
                    ->helperText('Upload the Tally Excel ledger for this selected ERP dealer. Transactions are saved only to this dealer.')
                    ->acceptedFileTypes([
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required()
                    ->storeFiles(false),
            ])
            ->statePath('data');
    }

    /**
     * @return array{id: int, dealer_code: ?string, firm_name: string, village: ?string, district: ?string, label: string}
     */
    public function selectedDealerDetails(): array
    {
        $dealer = $this->dealer();

        return [
            'id' => (int) $dealer->id,
            'dealer_code' => $dealer->dealer_code,
            'firm_name' => $dealer->firm_name,
            'village' => $dealer->village,
            'district' => $dealer->district,
            'label' => $this->selectedDealerLabel(),
        ];
    }

    public function selectedDealerLabel(): string
    {
        $dealer = $this->dealer();
        $parts = array_values(array_filter([
            filled($dealer->dealer_code) ? (string) $dealer->dealer_code : null,
            filled($dealer->firm_name) ? (string) $dealer->firm_name : null,
        ]));

        return implode(' – ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToDealer')
                ->label('Back to Dealer')
                ->icon('heroicon-o-arrow-left')
                ->url(DealerResource::getUrl('view', ['record' => $this->dealer()])),
            Action::make('importHistory')
                ->label('Import History')
                ->url(DealerResource::getUrl('tally-import-history'))
                ->color('gray'),
        ];
    }

    public function previewUpload(): void
    {
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
            name: 'preview-'.now()->format('YmdHis').'-'.$originalName,
            options: 'local',
        );
        $absolutePath = Storage::disk('local')->path($storedPath);

        try {
            $preview = app(TallyLedgerImportService::class)->preview($absolutePath, $this->dealer());
            $preview['sample_transactions'] = array_slice($preview['transactions'] ?? [], 0, 25);
            unset($preview['parsed'], $preview['transactions']);
            $this->preview = $preview;
            $this->uploadedFilePath = $absolutePath;
            $this->originalFilename = $originalName;
            $this->result = null;
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
        if ($this->uploadedFilePath === null || ! is_file($this->uploadedFilePath)) {
            Notification::make()->danger()->title('Import failed')->body('Upload the Tally Excel again before importing.')->send();

            return;
        }

        if (is_array($this->preview) && empty($this->preview['can_import'])) {
            Notification::make()
                ->danger()
                ->title('Import blocked')
                ->body(collect($this->preview['parse_errors'] ?? [])->first() ?: 'Tally ledger parsing is incomplete.')
                ->send();

            return;
        }

        try {
            $result = app(TallyLedgerImportService::class)->import(
                path: $this->uploadedFilePath,
                dealerId: (int) $this->dealer()->id,
                actor: auth()->user(),
                originalFilename: $this->originalFilename ?: 'tally-ledger.xlsx',
            );
            $this->result = [
                'imported_count' => $result['imported_count'],
                'duplicate_count' => $result['duplicate_count'],
                'failed_count' => $result['failed_count'],
                'transaction_count' => $result['transaction_count'],
                'dealer_name' => $result['dealer']->firm_name,
                'dealer_id' => $result['dealer']->id,
                'opening_label' => $result['summary']['opening_balance_label'],
                'outstanding_label' => $result['summary']['current_outstanding_label'],
                'balance_matched' => $result['import']->balance_matched,
                'tally_closing' => $result['import']->tally_closing_balance,
                'tally_closing_type' => $result['import']->tally_closing_balance_type,
                'erp_closing' => $result['import']->erp_closing_balance,
                'erp_closing_type' => $result['import']->erp_closing_balance_type,
                'difference' => $result['import']->difference,
                'failed_rows' => $this->preview['failed_rows'] ?? [],
            ];
            $this->step = 3;

            Notification::make()
                ->success()
                ->title('Tally ledger imported')
                ->body('Imported: '.$result['imported_count'].' | Duplicates skipped: '.$result['duplicate_count'].' | Failed: '.$result['failed_count'])
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
        $this->step = 1;
        $this->preview = null;
        $this->result = null;
        $this->uploadedFilePath = null;
        $this->originalFilename = null;
        $this->data = [];
        $this->form->fill();
    }
}
