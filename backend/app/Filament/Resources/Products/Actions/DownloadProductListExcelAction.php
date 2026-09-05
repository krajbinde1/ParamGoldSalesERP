<?php

namespace App\Filament\Resources\Products\Actions;

use App\Exports\ProductListExport;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadProductListExcelAction
{
    public static function make(): ActionGroup
    {
        return ActionGroup::make([
            Action::make('downloadFilteredProducts')
                ->label('Download Current Filtered List')
                ->action(fn (ListProducts $livewire): BinaryFileResponse => self::download(
                    $livewire->getTableQueryForExport(),
                    'products-filtered-'.now()->format('Y-m-d').'.xlsx',
                )),
            Action::make('downloadAllProducts')
                ->label('Download All Products')
                ->action(fn (): BinaryFileResponse => self::download(
                    Product::query(),
                    'products-all-'.now()->format('Y-m-d').'.xlsx',
                )),
        ])
            ->label('Download Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->button()
            ->visible(fn (): bool => ProductResource::canViewAny());
    }

    /**
     * @param  Builder<Product>  $query
     */
    private static function download(Builder $query, string $filename): BinaryFileResponse
    {
        return Excel::download(new ProductListExport($query), $filename);
    }
}
