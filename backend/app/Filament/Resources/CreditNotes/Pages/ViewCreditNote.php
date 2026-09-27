<?php

namespace App\Filament\Resources\CreditNotes\Pages;

use App\Actions\CreditNotes\ApproveCreditNoteByProduction;
use App\Actions\CreditNotes\CompleteCreditNote;
use App\Actions\CreditNotes\RejectCreditNoteWithRemarks;
use App\Filament\Resources\CreditNotes\CreditNoteResource;
use App\Models\CreditNote;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewCreditNote extends ViewRecord
{
    protected static string $resource = CreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return [
            Action::make('approveProduction')
                ->label('Approve Factory Return')
                ->color('success')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('approveAsProduction', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('approveAsProduction', $record))
                ->requiresConfirmation()
                ->modalHeading('Approve factory return and add stock')
                ->form([
                    Textarea::make('remark')
                        ->label('Remarks')
                        ->rows(2),
                ])
                ->action(function (array $data) use ($record): void {
                    app(ApproveCreditNoteByProduction::class)->execute(
                        $record,
                        auth()->user(),
                        $data['remark'] ?? null,
                    );

                    Notification::make()->title('Factory return approved. Stock updated.')->success()->send();
                    $this->refreshFormData(['status', 'production_approved_by', 'production_approved_at', 'stock_posted_at']);
                }),
            Action::make('complete')
                ->label('Mark Generated / Completed')
                ->color('success')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('complete', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('complete', $record))
                ->requiresConfirmation()
                ->modalHeading('Mark Credit Note Generated')
                ->form([
                    Textarea::make('completion_remark')
                        ->label('Remarks')
                        ->rows(2),
                ])
                ->action(function (array $data) use ($record): void {
                    app(CompleteCreditNote::class)->execute(
                        creditNote: $record,
                        actor: auth()->user(),
                        remark: $data['completion_remark'] ?? null,
                    );

                    Notification::make()->title('Credit Note marked as generated.')->success()->send();

                    $this->refreshFormData([
                        'status',
                        'completed_by',
                        'completed_at',
                        'completion_remark',
                    ]);
                }),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('reject', $record))
                ->modalHeading('Reject Credit Note')
                ->form([
                    Textarea::make('rejection_remark')
                        ->label('Reason / Remarks')
                        ->required()
                        ->minLength(3)
                        ->rows(3),
                ])
                ->action(function (array $data) use ($record): void {
                    app(RejectCreditNoteWithRemarks::class)->execute(
                        creditNote: $record,
                        actor: auth()->user(),
                        remark: $data['rejection_remark'],
                        rejectedByRole: CreditNote::rejectedByRoleFor(auth()->user()),
                    );

                    Notification::make()->title('Credit Note rejected.')->danger()->send();

                    $this->refreshFormData([
                        'status',
                        'rejected_by',
                        'rejected_by_role',
                        'rejected_at',
                        'rejection_remark',
                    ]);
                }),
        ];
    }
}
