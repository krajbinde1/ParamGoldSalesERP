<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MaharashtraGeography;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function maharashtra(): JsonResponse
    {
        $districts = collect(MaharashtraGeography::districts())
            ->map(fn (array $district): array => [
                'name' => $district['name'],
                'former_name' => $district['former_name'],
                'label' => MaharashtraGeography::districtLabel($district['name'], $district['former_name']),
                'talukas' => $district['talukas'],
            ])
            ->values();

        return response()->json([
            'state' => MaharashtraGeography::STATE_NAME,
            'districts' => $districts,
        ]);
    }
}
