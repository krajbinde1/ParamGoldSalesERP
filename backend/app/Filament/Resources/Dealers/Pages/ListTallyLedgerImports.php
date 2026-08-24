<?php

namespace App\Filament\Resources\Dealers\Pages;

use App\Filament\Resources\Dealers\DealerResource;
use App\Models\DealerTallyImport;
use App\Support\IndianCurrency;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class ListTallyLedgerImports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = DealerResource::class;

    protected static ?string $title = 'Tally Import History';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'tally-import-history';

    protected string $view = 'filament.resources.dealers.pages.list-tally-ledger-imports';

    public static function canAccess(array $parameters = []): bool
    {
        $user = auth()->user();

        return ($user?->isAdminUser() ?? false) || ($user?->isDirectorUser() ?? false);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                DealerTallyImport::query()
                    ->with(['dealer:id,firm_name,dealer_code', 'importedByUser:id,name'])
                    ->latest('imported_at')
            )
            ->columns([
                TextColumn::make('imported_at')
                    ->label('Import Date & Time')
                    ->dateTime('d M Y • h:i A', 'Asia/Kolkata')
                    ->sortable(),
                TextColumn::make('original_filename')
                    ->label('File name')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('dealer.firm_name')
                    ->label('Dealer')
                    ->description(fn (DealerTallyImport $record): ?string => $record->dealer?->dealer_code)
                    ->searchable(),
                TextColumn::make('importedByUser.name')
                    ->label('Imported by')
                    ->placeholder('—'),
                TextColumn::make('opening_balance')
                    ->label('Opening Balance')
                    ->formatStateUsing(function (DealerTallyImport $record): string {
                        $signed = strtolower((string) $record->opening_balance_type) === 'credit'
                            ? -1 * (float) $record->opening_balance
                            : (float) $record->opening_balance;

                        return IndianCurrency::formatDrCr($signed);
                    }),
                TextColumn::make('transaction_count')
                    ->label('Txns')
                    ->alignRight(),
                TextColumn::make('imported_count')
                    ->label('Imported')
                    ->alignRight(),
                TextColumn::make('duplicate_count')
                    ->label('Duplicates')
                    ->alignRight(),
                TextColumn::make('failed_count')
                    ->label('Failed')
                    ->alignRight(),
                TextColumn::make('balance_matched')
                    ->label('Tally vs ERP')
                    ->badge()
                    ->formatStateUsing(fn (DealerTallyImport $record): string => $record->matchStatusLabel())
                    ->color(fn (DealerTallyImport $record): string => match (true) {
                        $record->tally_closing_balance === null => 'gray',
                        (bool) $record->balance_matched => 'success',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('imported_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToDealers')
                ->label('Back to Dealers')
                ->color('gray')
                ->url(DealerResource::getUrl('index')),
        ];
    }
}
