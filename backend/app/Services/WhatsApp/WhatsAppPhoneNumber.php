<?php

namespace App\Services\WhatsApp;

final class WhatsAppPhoneNumber
{
    public static function fromDealer(?\App\Models\Dealer $dealer): ?string
    {
        if ($dealer === null) {
            return null;
        }

        $whatsapp = trim((string) ($dealer->getAttribute('whatsapp') ?? ''));
        $mobile = trim((string) ($dealer->mobile ?? ''));

        return self::toE164($whatsapp !== '' ? $whatsapp : $mobile);
    }

    public static function toE164(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) !== 10 || ! preg_match('/^[6-9]/', $digits)) {
            return null;
        }

        return '+91'.$digits;
    }

    public static function toApi(?string $e164): ?string
    {
        $normalized = self::toE164($e164) ?? (str_starts_with((string) $e164, '+91') ? $e164 : null);
        if ($normalized === null) {
            return null;
        }

        return ltrim($normalized, '+');
    }
}
