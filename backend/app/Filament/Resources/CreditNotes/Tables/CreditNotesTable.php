<?php

namespace App\Filament\Resources\CreditNotes\Tables;

use App\Actions\CreditNotes\CompleteCreditNote;
use App\Actions\CreditNotes\RejectCreditNoteWithRemarks;
use App\Filament\Support\EmployeeSelect;
use App\Filament\Support\TodayDateFilter;
use App\Models\CreditNote;
use App\Models\Dealer;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('credit_note_no')->label('Credit Note No')->searchable()->sortable(),
                TextColumn::make('type')
                    ->formatStateUsing(fn (string $state, CreditNote $record): string => $record->typeLabel())
                    ->badge()
                    ->sortable(),
                TextColumn::make('credit_note_date')->label('Date')->date()->sortable(),
                TextColumn::make('dealer.firm_name')->label('Dealer')->searchable(),
                TextColumn::make('salesEmployee.full_name')->label('Employee')->placeholder('-')->searchable(),
                TextColumn::make('bill_reference')->label('Bill Ref.')->searchable()->toggleable(),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state, CreditNote $record): string => $record->displayStatusLabel())
                    ->color(fn (string $state): string => CreditNote::statusColor($state))
                    ->sortable(),
            ])
            ->filters([
                EmployeeSelect::applyRelationshipFilter(
                    SelectFilter::make('sales_employee_id')
                        ->label('Employee')
                        ->relationship('salesEmployee', 'full_name')
                        ->searchable()
                        ->preload(),
                ),
                SelectFilter::make('dealer_id')
                    ->label('Dealer')
                    ->relationship('dealer', 'firm_name')
                    ->getOptionLabelFromRecordUsing(fn (Dealer $record): string => $record->firm_name)
                    ->searchable()
                    ->preload(),
                SelectFilter::make('type')->options(CreditNote::typeLabels()),
                TodayDateFilter::make('credit_note_date', 'Credit Note Date'),
                Filter::make('date_range')
                    ->label('Date range')
                    ->schema([
                        \Filament\Forms\Components\DatePicker::make('from')->label('From')->native(false),
                        \Filament\Forms\Components\DatePicker::make('until')->label('To')->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(filled($data['from'] ?? null), fn (Builder $q) => $q->whereDate('credit_note_date', '>=', $data['from']))
                            ->when(filled($data['until'] ?? null), fn (Builder $q) => $q->whereDate('credit_note_date', '<=', $data['until']));
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('complete')
                    ->label('Mark Generated')
                    ->color('success')
                    ->visible(fn (CreditNote $record): bool => Gate::forUser(auth()->user())->allows('complete', $record))
                    ->authorize(fn (CreditNote $record): bool => Gate::forUser(auth()->user())->allows('complete', $record))
                    ->requiresConfirmation()
                    ->modalHeading('Mark Credit Note Generated')
                    ->form([
                        Textarea::make('completion_remark')
                            ->label('Remarks')
                            ->rows(2),
                    ])
                    ->action(function (CreditNote $record, array $data): void {
                        app(CompleteCreditNote::class)->execute(
                            creditNote: $record,
                            actor: auth()->user(),
                            remark: $data['completion_remark'] ?? null,
                        );

                        Notification::make()->title('Credit Note marked as generated.')->success()->send();
                    }),
                Action::make('reject')
                    ->label('Reject')
                    ->color('danger')
                    ->visible(fn (CreditNote $record): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                    ->authorize(fn (CreditNote $record): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                    ->modalHeading('Reject Credit Note')
                    ->form([
                        Textarea::make('rejection_remark')
                            ->label('Reason / Remarks')
                            ->required()
                            ->minLength(3)
                            ->rows(3),
                    ])
                    ->action(function (CreditNote $record, array $data): void {
                        app(RejectCreditNoteWithRemarks::class)->execute(
                            creditNote: $record,
                            actor: auth()->user(),
                            remark: $data['rejection_remark'],
                            rejectedByRole: CreditNote::REJECTED_BY_ROLE_ADMIN,
                        );

                        Notification::make()->title('Credit Note rejected.')->danger()->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }
}
