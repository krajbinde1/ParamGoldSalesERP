<?php

namespace App\Filament\Resources\TaDaClaims\Pages;

use App\Filament\Resources\TaDaClaims\TaDaClaimResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewTaDaClaim extends ViewRecord
{
    protected static string $resource = TaDaClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->canApprove())
                ->action(function (): void {
                    $this->getRecord()->approve(auth()->id());
                    $this->refreshFormData(['status']);
                }),
            Action::make('reject')
                ->label('Reject')
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->canReject())
                ->form([
                    Textarea::make('admin_remark')
                        ->label('Admin Remark')
                        ->required()
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    $this->getRecord()->reject($data['admin_remark'], auth()->id());
                    $this->refreshFormData(['status', 'admin_remark']);
                }),
            Action::make('markPaid')
                ->label('Mark as Paid')
                ->color('info')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->getRecord()->canMarkPaid())
                ->action(function (): void {
                    $this->getRecord()->markAsPaid(auth()->id());
                    $this->refreshFormData(['status']);
                }),
        ];
    }
}
