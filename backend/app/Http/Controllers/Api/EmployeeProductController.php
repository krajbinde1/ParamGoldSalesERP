<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeProductController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $search = trim($request->string('search')->toString());

        $products = Product::query()
            ->where('status', true)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('product_name', 'like', "%{$search}%")
                        ->orWhere('product_code', 'like', "%{$search}%");
                });
            })
            ->orderBy('product_name')
            ->get(['id', 'product_code', 'product_name', 'dealer_price', 'gst_percentage', 'uom', 'nos_per_case'])
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'dealer_price' => (float) $product->dealer_price,
                'gst_percentage' => (float) $product->gst_percentage,
                'uom' => $product->uom,
                'nos_per_case' => (int) $product->nos_per_case,
            ])
            ->values();

        return response()->json(['data' => $products]);
    }
}
