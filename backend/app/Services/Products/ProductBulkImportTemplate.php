<?php

namespace App\Services\Products;

final class ProductBulkImportTemplate
{
    /** @var list<string> */
    public const MANDATORY_COLUMNS = [
        'product_name',
        'dealer_price',
        'nos_per_case',
        'gst_percentage',
        'status',
    ];

    /** @var list<string> */
    public const OPTIONAL_COLUMNS = [
        'product_code',
    ];

    /** @var array<string, string> */
    public const COLUMN_LABELS = [
        'product_name' => 'Product Name *',
        'product_code' => 'Product Code',
        'dealer_price' => 'Dealer Price *',
        'nos_per_case' => 'Nos Per Case *',
        'gst_percentage' => 'GST % *',
        'status' => 'Status *',
    ];

    /** @var list<string> */
    public const ALLOWED_GST = ['0', '5', '12', '18', '28'];

    /** @var list<string> */
    public const ALLOWED_UOM = [
        'Bag',
        'Bottle',
        'Box',
        'Gram',
        'Kg',
        'Litre',
        'Millilitre',
        'Packet',
        'Piece',
    ];

    /** @var list<string> */
    public const ALL_COLUMNS = [
        'product_name',
        'product_code',
        'dealer_price',
        'nos_per_case',
        'gst_percentage',
        'status',
    ];

    /** @return list<string> */
    public static function allColumns(): array
    {
        return self::ALL_COLUMNS;
    }

    /** @return list<string> */
    public static function columnLabels(): array
    {
        return array_map(
            fn (string $column): string => self::COLUMN_LABELS[$column],
            self::allColumns(),
        );
    }
}
