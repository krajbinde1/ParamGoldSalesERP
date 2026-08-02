<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Actual output quantity tolerance
    |--------------------------------------------------------------------------
    |
    | Actual output may exceed planned quantity by at most this percentage.
    | Example: 20 means actual cannot exceed planned * 1.20.
    |
    */
    'output_tolerance_percent' => (float) env('INVENTORY_OUTPUT_TOLERANCE_PERCENT', 20),

    /*
    |--------------------------------------------------------------------------
    | BOM formula quantity tolerance (legacy / unused)
    |--------------------------------------------------------------------------
    |
    | Quantity-wise BOMs no longer compare raw-material totals to Formula
    | Quantity (units differ). Retained for config BC only.
    |
    */
    'bom_formula_tolerance' => (float) env('INVENTORY_BOM_FORMULA_TOLERANCE', 0.001),

    /*
    |--------------------------------------------------------------------------
    | Material quantity variance tolerance
    |--------------------------------------------------------------------------
    |
    | Actual consumption may deviate from calculated BOM quantity by at most
    | this percentage before deviation approval is required.
    |
    */
    'quantity_variance_tolerance_percent' => (float) env('INVENTORY_QTY_VARIANCE_TOLERANCE_PERCENT', 10),

    /*
    |--------------------------------------------------------------------------
    | Include recoverable GST in inventory cost
    |--------------------------------------------------------------------------
    |
    | Material inward valuation always includes GST + freight in the effective
    | inventory rate (see MaterialInwardCosting). This flag is retained for
    | other inventory flows that may still reference it.
    |
    */
    'include_gst_in_inventory_cost' => (bool) env('INVENTORY_INCLUDE_GST_IN_COST', true),

    /*
    |--------------------------------------------------------------------------
    | Batch consumption policy for production (future use)
    |--------------------------------------------------------------------------
    */
    'batch_consumption_policy' => env('INVENTORY_BATCH_CONSUMPTION_POLICY', 'fefo'), // fifo|fefo

    /*
    |--------------------------------------------------------------------------
    | Code prefixes
    |--------------------------------------------------------------------------
    */
    // Keep existing prefixes for compatibility (do not rename live codes).
    // RM = Raw Material, PK = Packaging (legacy; not PM), SFM = Semi-Finished, FP = Finished Product inventory.
    'raw_material_code_prefix' => 'RM',
    'packaging_material_code_prefix' => 'PK',
    'semi_finished_code_prefix' => 'SFM',
    'finished_product_code_prefix' => 'FP',
    'bom_number_prefix' => 'BOM',
    'batch_number_prefix' => 'PB',
    'stock_adjustment_prefix' => 'SA',
    'raw_material_inward_prefix' => 'RMI',
    'packaging_material_inward_prefix' => 'PMI',
    'raw_material_batch_prefix' => 'RMB',
    'inward_return_prefix' => 'IRR',

    /*
    |--------------------------------------------------------------------------
    | Bulk products allowed to activate BOM without strict pack variant
    |--------------------------------------------------------------------------
    |
    | Product IDs listed here may use a Bulk variant (is_bulk=true) for BOM
    | activation. All other products require an active non-bulk pack variant.
    |
    */
    'bulk_product_ids' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('INVENTORY_BULK_PRODUCT_IDS', ''))
    ))),
];
