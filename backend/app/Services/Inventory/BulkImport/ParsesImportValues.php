<?php

namespace App\Services\Inventory\BulkImport;

use App\Enums\InventoryUnit;
use App\Services\Inventory\InventoryUnitConversion;
use Carbon\Carbon;
use Illuminate\Support\Str;

trait ParsesImportValues
{
    protected function blank(mixed $value): bool
    {
        return trim((string) ($value ?? '')) === '';
    }

    protected function stringValue(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    protected function parseYesNo(mixed $value, bool $default = true): ?bool
    {
        if ($this->blank($value)) {
            return $default;
        }

        $normalized = Str::lower($this->stringValue($value));

        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'active'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n', 'inactive'], true)) {
            return false;
        }

        return null;
    }

    protected function parseDecimal(mixed $value, float $default = 0.0): ?float
    {
        if ($this->blank($value)) {
            return $default;
        }

        $normalized = Str::of($this->stringValue($value))
            ->replace(',', '')
            ->toString();

        if (! is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    protected function parseDate(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value))
                    ->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        $raw = $this->stringValue($value);

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'Y/m/d', 'd-M-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $raw);
                if ($date !== false && $date->format($format) === $raw) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
                // try next format
            }
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolveInventoryUnit(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        $normalized = app(InventoryUnitConversion::class)->normalize($this->stringValue($value));

        return InventoryUnit::tryFrom($normalized)?->value;
    }

    /**
     * @param  list<string>  $required
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected function missingRequired(array $required, array $data): array
    {
        $missing = [];

        foreach ($required as $field) {
            if ($this->blank($data[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
