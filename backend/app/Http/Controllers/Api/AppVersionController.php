<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MobileApp\MobileAppVersionService;
use Illuminate\Http\JsonResponse;

class AppVersionController extends Controller
{
    public function __invoke(MobileAppVersionService $settings): JsonResponse
    {
        $current = $settings->current();

        return response()->json([
            'success' => true,
            'latest_version' => $current['latest_version'],
            'latest_build' => $current['latest_build'],
            'apk_url' => $current['apk_url'],
            'apk_file_size' => $current['apk_file_size'],
            'apk_sha256' => $current['apk_sha256'],
            'force_update' => $current['force_update'],
            'message' => $current['message'],
        ]);
    }
}
