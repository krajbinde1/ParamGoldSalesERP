<?php

namespace App\Enums;

enum PackagingType: string
{
    case Pouch = 'Pouch';
    case Bag = 'Bag';
    case Box = 'Box';
    case Bucket = 'Bucket';
    case Bottle = 'Bottle';
    case Jar = 'Jar';
    case Sticker = 'Sticker';
    case Label = 'Label';
    case Cap = 'Cap';
    case Other = 'Other';

    public function label(): string
    {
        return $this->value;
    }

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $direct = self::tryFrom($raw);
        if ($direct instanceof self) {
            return $direct;
        }

        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $raw) === 0) {
                return $case;
            }
        }

        return null;
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
