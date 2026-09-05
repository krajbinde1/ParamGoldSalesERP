<?php

namespace App\Exports;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

final class ProductListExport implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  Builder<Product>  $query
     */
    public function __construct(
        private readonly Builder $query,
    ) {}

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        return $this->query
            ->with('activeBom')
            ->get()
            ->map(fn (Product $product): array => [
                $product->product_code,
                $product->product_name,
                $product->uom,
                $product->nos_per_case,
                $product->activeBom !== null ? 'BOM Set' : 'BOM Not Set',
                $product->gst_percentage !== null ? (float) $product->gst_percentage : null,
                $product->dealer_price !== null ? (float) $product->dealer_price : null,
                $product->status ? 'Active' : 'Inactive',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Product Code',
            'Product Name',
            'UOM',
            'Nos/Case',
            'BOM Status',
            'GST',
            'Dealer Price',
            'Status',
        ];
    }

    public function title(): string
    {
        return 'Products';
    }
}
