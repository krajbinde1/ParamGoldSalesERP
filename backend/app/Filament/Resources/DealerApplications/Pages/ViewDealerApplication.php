<?php

namespace App\Filament\Resources\DealerApplications\Pages;

use App\Actions\DealerApplications\FinalizeDealerApplication;
use App\Actions\DealerApplications\ReviewDealerApplication;
use App\Filament\Resources\DealerApplications\DealerApplicationResource;
use App\Models\DealerApplication;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

class ViewDealerApplication extends ViewRecord
{
    protected static string $resource = DealerApplicationResource::class;

    protected function getHeaderActions(): array
    {
        /** @var DealerApplication $record */
        $record = $this->getRecord();

        return [
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('approveAsAdmin', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('approveAsAdmin', $record))
                ->modalHeading('Final Approve Dealer')
                ->modalSubmitActionLabel('Approve & Generate Code')
                ->form([
                    Textarea::make('remark')
                        ->label('Remark')
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data) use ($record): void {
                    $application = app(FinalizeDealerApplication::class)->execute(
                        $record,
                        auth()->user(),
                        $data['remark'] ?? null,
                    );

                    Notification::make()
                        ->title('Dealer approved')
                        ->body('Dealer code '.$application->dealer?->dealer_code.' generated and party created.')
                        ->success()
                        ->send();

                    $this->record->refresh();
                }),
            Action::make('sendBack')
                ->label('Send Back for Correction')
                ->color('warning')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('sendBackAsAdmin', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('sendBackAsAdmin', $record))
                ->modalHeading('Send Back for Correction')
                ->form([
                    Textarea::make('remark')
                        ->label('Remark')
                        ->required()
                        ->minLength(3)
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data) use ($record): void {
                    app(ReviewDealerApplication::class)->execute(
                        $record,
                        auth()->user(),
                        ReviewDealerApplication::ACTION_SEND_BACK,
                        'admin',
                        $data['remark'],
                    );

                    Notification::make()
                        ->title('Sent back for correction')
                        ->success()
                        ->send();

                    $this->record->refresh();
                }),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->visible(fn (): bool => Gate::forUser(auth()->user())->allows('rejectAsAdmin', $record))
                ->authorize(fn (): bool => Gate::forUser(auth()->user())->allows('rejectAsAdmin', $record))
                ->modalHeading('Reject Dealer Application')
                ->form([
                    Textarea::make('remark')
                        ->label('Remark')
                        ->required()
                        ->minLength(3)
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data) use ($record): void {
                    app(ReviewDealerApplication::class)->execute(
                        $record,
                        auth()->user(),
                        ReviewDealerApplication::ACTION_REJECT,
                        'admin',
                        $data['remark'],
                    );

                    Notification::make()
                        ->title('Dealer application rejected')
                        ->success()
                        ->send();

                    $this->record->refresh();
                }),
        ];
    }
}
