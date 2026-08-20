<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AppVersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $apkUrl = (string) config('mobile_app.apk_url');
        if ($apkUrl === '') {
            $apkUrl = 'https://paramgold.in/apk/paramgold-latest.apk';
        }

        return response()->json([
            'success' => true,
            'latest_version' => (string) config('mobile_app.latest_version', '1.0.0'),
            'latest_build' => (int) config('mobile_app.latest_build', 2),
            'apk_url' => $apkUrl,
            'force_update' => (bool) config('mobile_app.force_update', true),
            'message' => (string) config(
                'mobile_app.message',
                'A new version of ParamGold is available. Please update to continue.',
            ),
        ]);
    }
}
