<?php

namespace App\Support;

final class PaymentFollowUpWhatsAppCopy
{
    public static function body(
        string $dealerName,
        float $outstanding,
        ?float $expectedAmount,
        string $followUpDate,
    ): string {
        $expected = $expectedAmount !== null
            ? IndianCurrency::format($expectedAmount)
            : 'As discussed';

        return implode("\n", [
            'Hello '.$dealerName.',',
            '',
            'This is a payment reminder from ParamGold.',
            '',
            'Current Outstanding: '.IndianCurrency::format($outstanding),
            'Expected Payment: '.$expected,
            'Follow-up Date: '.$followUpDate,
            '',
            'Please arrange the payment as discussed with our sales executive.',
            '',
            'Thank you,',
            'ParamGold',
        ]);
    }
}
