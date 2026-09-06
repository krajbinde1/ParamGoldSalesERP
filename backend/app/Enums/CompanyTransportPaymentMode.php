<?php

namespace App\Enums;

enum CompanyTransportPaymentMode: string
{
    case Cash = 'Cash';
    case Upi = 'UPI';
    case Neft = 'NEFT';
    case Rtgs = 'RTGS';
    case Cheque = 'Cheque';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
