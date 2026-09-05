<?php

use App\Services\WhatsApp\WhatsAppBillCopy;

it('builds the billed sales invoice whatsapp body', function (): void {
    $body = WhatsAppBillCopy::body('Shree Ganesh Traders', 'ORD123456', 11800);

    expect($body)->toBe(implode("\n", [
        'Dear Shree Ganesh Traders,',
        '',
        'Your Sales Invoice for Order #ORD123456 has been generated.',
        '',
        'Invoice Amount: ₹11,800',
        '',
        'Please find the invoice attached.',
        '',
        'ParamGold Agritech Pvt. Ltd.',
    ]));
});
