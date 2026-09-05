<?php

namespace App\Services\WhatsApp;

use App\Support\IndianCurrency;

final class WhatsAppBillCopy
{
    public static function body(string $dealerName, string $orderNo, float $amount): string
    {
        $name = trim($dealerName) !== '' ? trim($dealerName) : 'Dealer';

        return implode("\n", [
            'Dear '.$name.',',
            '',
            'Your Sales Invoice for Order #'.$orderNo.' has been generated.',
            '',
            'Invoice Amount: '.IndianCurrency::format($amount),
            '',
            'Please find the invoice attached.',
            '',
            'ParamGold Agritech Pvt. Ltd.',
        ]);
    }
}
