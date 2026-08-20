<?php

namespace App\Http\Controllers\Api\Director;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use Illuminate\Http\JsonResponse;

class DirectorCollectionController extends Controller
{
    public function show(Collection $collection): JsonResponse
    {
        $collection->load([
            'dealer:id,firm_name,owner_name,village,mobile',
            'salesEmployee:id,full_name,employee_code',
        ]);

        return response()->json([
            'data' => [
                'id' => $collection->id,
                'receipt_no' => $collection->receipt_no,
                'employee_id' => $collection->sales_employee_id,
                'employee_name' => $collection->salesEmployee?->full_name ?? '-',
                'employee_code' => $collection->salesEmployee?->employee_code,
                'dealer' => $collection->dealer === null ? null : [
                    'id' => $collection->dealer->id,
                    'firm_name' => $collection->dealer->firm_name,
                    'owner_name' => $collection->dealer->owner_name,
                    'village' => $collection->dealer->village,
                    'mobile' => $collection->dealer->mobile,
                ],
                'dealer_name' => $collection->dealer?->firm_name ?? '-',
                'amount' => (float) $collection->amount,
                'collection_date' => $collection->collection_date?->toDateString(),
                'photo_url' => $collection->photoUrl(),
                'remarks' => $collection->remarks,
                'employee_remarks' => $collection->remarks,
                'admin_remark' => $collection->admin_remark,
                'status' => $collection->status,
                'status_label' => Collection::statusLabels()[$collection->status] ?? (string) $collection->status,
            ],
        ]);
    }
}
