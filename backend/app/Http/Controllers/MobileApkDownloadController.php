<?php

namespace App\Http\Controllers;

use App\Services\MobileApp\MobileApkPublisher;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MobileApkDownloadController extends Controller
{
    public function __invoke(MobileApkPublisher $publisher): BinaryFileResponse
    {
        $path = $publisher->publicPath();

        abort_unless(is_file($path) && is_readable($path), 404, 'APK is not available.');

        return response()->file($path, [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Content-Disposition' => 'attachment; filename="'.MobileApkPublisher::FILENAME.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
