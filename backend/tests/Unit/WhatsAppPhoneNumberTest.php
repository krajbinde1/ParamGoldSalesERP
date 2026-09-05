<?php

use App\Services\WhatsApp\WhatsAppPhoneNumber;

it('normalizes 10-digit indian mobiles to e164', function (): void {
    expect(WhatsAppPhoneNumber::toE164('9876543210'))->toBe('+919876543210')
        ->and(WhatsAppPhoneNumber::toE164('98765 43210'))->toBe('+919876543210')
        ->and(WhatsAppPhoneNumber::toE164('+91 98765-43210'))->toBe('+919876543210')
        ->and(WhatsAppPhoneNumber::toE164('919876543210'))->toBe('+919876543210')
        ->and(WhatsAppPhoneNumber::toE164('09876543210'))->toBe('+919876543210')
        ->and(WhatsAppPhoneNumber::toApi('9876543210'))->toBe('919876543210');
});

it('rejects invalid dealer mobiles', function (): void {
    expect(WhatsAppPhoneNumber::toE164(null))->toBeNull()
        ->and(WhatsAppPhoneNumber::toE164(''))->toBeNull()
        ->and(WhatsAppPhoneNumber::toE164('12345'))->toBeNull()
        ->and(WhatsAppPhoneNumber::toE164('5876543210'))->toBeNull();
});

it('prefers the dealer whatsapp number when it is saved', function (): void {
    $dealer = new \App\Models\Dealer(['mobile' => '9876543210']);
    $dealer->setAttribute('whatsapp', '9123456789');

    expect(WhatsAppPhoneNumber::fromDealer($dealer))->toBe('+919123456789')
        ->and(WhatsAppPhoneNumber::fromDealer(new \App\Models\Dealer(['mobile' => '9876543210'])))->toBe('+919876543210');
});
