<?php

namespace App\Services\Dealers;

final class DealerBulkImportTemplate
{
    /** @var list<string> */
    public const MANDATORY_COLUMNS = [
        'firm_name',
        'assigned_employee_code',
        'mobile',
        'state',
        'district',
        'taluka',
        'village',
    ];

    /** @var list<string> */
    public const OPTIONAL_COLUMNS = [
        'owner_name',
        'dealer_type',
        'gst_no',
        'pan_no',
        'fertilizer_license_no',
        'address',
        'pincode',
        'credit_limit',
        'outstanding',
        'latitude',
        'longitude',
        'status',
        'email',
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

        // Example row: only mandatory fields filled; optional left blank so users see the minimum viable import.
        fputcsv($handle, array_merge(['Example'], [
            'ABC Agro',           // firm_name (mandatory)
            'E001',               // assigned_employee_code (mandatory)
            '9876543210',         // mobile (mandatory)
            'Maharashtra',        // state (mandatory)
            'Pune',               // district (mandatory)
            'Haveli',             // taluka (mandatory)
            'Wagholi',            // village (mandatory)
            'Rajesh Kumar',       // owner_name (optional)
            'Retailer',           // dealer_type (optional)
            '',                   // gst_no
            '',                   // pan_no
            '',                   // fertilizer_license_no
            'Market Road',        // address (optional)
            '412207',             // pincode (optional)
            '0',                  // credit_limit
            '0',                  // outstanding
            '',                   // latitude
            '',                   // longitude
            '1',                  // status / Active
            'abc@example.com',    // email
        ]));

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents === false ? '' : $contents;
    }
}
