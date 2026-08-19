<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\PaymentRequest;
use Filament\Widgets\Widget;

class AdminDirectorPaymentOverviewWidget extends Widget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.admin-director-payment-overview-widget';

    public static function canView(): bool
    {
        return auth()->user()?->usesAdminDirectorDashboard() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $canOpen = PaymentRequestResource::canAccess();
        $counts = PaymentRequest::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->whereIn('status', [
                PaymentRequest::STATUS_PENDING_FIRST,
                PaymentRequest::STATUS_PENDING_SECOND,
                PaymentRequest::STATUS_APPROVED_FOR_PAYMENT,
                PaymentRequest::STATUS_PAYMENT_DONE,
            ])
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $indexUrl = $canOpen ? PaymentRequestResource::getUrl('index') : null;

        return [
            'stats' => [
                [
                    'label' => 'Pending First Approval',
                    'value' => (int) ($counts[PaymentRequest::STATUS_PENDING_FIRST] ?? 0),
                    'tone' => 'amber',
                    'icon' => 'heroicon-o-clock',
                    'url' => $indexUrl,
                    'showArrow' => true,
                ],
                [
                    'label' => 'Pending Second Approval',
                    'value' => (int) ($counts[PaymentRequest::STATUS_PENDING_SECOND] ?? 0),
                    'tone' => 'amber',
                    'icon' => 'heroicon-o-clipboard-document-check',
                    'url' => $indexUrl,
                    'showArrow' => true,
                ],
                [
                    'label' => 'Approved for Payment',
                    'value' => (int) ($counts[PaymentRequest::STATUS_APPROVED_FOR_PAYMENT] ?? 0),
                    'tone' => 'blue',
                    'icon' => 'heroicon-o-check-badge',
                    'url' => $indexUrl,
                    'showArrow' => true,
                ],
                [
                    'label' => 'Payment Done',
                    'value' => (int) ($counts[PaymentRequest::STATUS_PAYMENT_DONE] ?? 0),
                    'tone' => 'green',
                    'icon' => 'heroicon-o-banknotes',
                    'url' => $indexUrl,
                    'showArrow' => false,
                ],
            ],
        ];
    }
}
