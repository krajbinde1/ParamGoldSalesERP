<?php

namespace App\Services\Dealers;

final class DealerBulkImportTemplate
{
    /** @var list<string> */
    public const MANDATORY_COLUMNS = [
        'firm_name',
        'owner_name',
        'mobile',
        'address',
        'state',
        'district',
        'taluka',
        'village',
        'pincode',
        'assigned_employee_code',
        'status',
    ];

    /** @var list<string> */
    public const OPTIONAL_COLUMNS = [
        'dealer_code',
        'alt_mobile',
        'email',
        'gst_no',
        'credit_limit',
        'outstanding',
        'latitude',
        'longitude',
    ];

    /** @return list<string> */
    public static function allColumns(): array
    {
        return array_merge(self::MANDATORY_COLUMNS, self::OPTIONAL_COLUMNS);
    }

    public static function csv(): string
    {
        $columns = self::allColumns();
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_merge(
            ['Requirement'],
            array_map(
                fn (string $column): string => in_array($column, self::MANDATORY_COLUMNS, true) ? 'MANDATORY' : 'OPTIONAL',
                $columns,
            ),
        ));

        fputcsv($handle, array_merge(['Column'], $columns));

        fputcsv($handle, array_merge(['Example'], [
            'ABC Agro',
            'Rajesh Kumar',
            '9876543210',
            'Market Road',
            'Maharashtra',
            'Pune',
            'Haveli',
            'Wagholi',
            '412207',
            'E001',
            '1',
            '',
            '',
            'abc@example.com',
            '',
            '0',
            '0',
            '',
            '',
        ]));

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
    }
}
