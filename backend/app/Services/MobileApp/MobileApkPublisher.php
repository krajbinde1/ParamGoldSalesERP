<?php

namespace App\Services\MobileApp;

use App\Http\Controllers\MobileApkDownloadController;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class MobileApkPublisher
{
    public const RELATIVE_PATH = 'apk/paramgold-latest.apk';

    public const FILENAME = 'paramgold-latest.apk';

    public const MAX_KILOBYTES = 102400;

    public const MIN_BYTES = 1024;

    public function publicPath(): string
    {
        return public_path(self::RELATIVE_PATH);
    }

    public function downloadUrlPath(): string
    {
        return '/'.self::RELATIVE_PATH;
    }

    public function canonicalDownloadUrl(): string
    {
        return url($this->downloadUrlPath());
    }

    /**
     * @return array{exists: bool, size_bytes: ?int, updated_at: ?string, url_path: string}
     */
    public function currentMeta(): array
    {
        $path = $this->publicPath();
        $exists = is_file($path);

        return [
            'exists' => $exists,
            'size_bytes' => $exists ? filesize($path) ?: null : null,
            'updated_at' => $exists
                ? date('d M Y, h:i A', (int) filemtime($path))
                : null,
            'url_path' => $this->downloadUrlPath(),
        ];
    }

    /**
     * @return array{package: string, version_name: string, version_code: int, size: int, sha256: string}
     */
    public function assertMatchesVersion(string $path, string $version, int $build): array
    {
        try {
            $meta = app(AndroidApkMetadata::class)->read($path);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'apk' => $exception->getMessage().' The current APK and version settings were not changed.',
            ]);
        }

        $expectedPackage = (string) config('mobile_app.package_name', 'com.example.mobile');
        $errors = [];

        if ($meta['package'] !== $expectedPackage) {
            $errors['apk'] = "The APK package is {$meta['package']}. ParamGold requires {$expectedPackage}. Publishing was blocked and the current APK was not changed.";
        }

        if ($meta['version_name'] !== $version) {
            $errors['latest_version'] = "Latest Version must match the APK version name ({$meta['version_name']}). Publishing was blocked and the current APK was not changed.";
        }

        if ($meta['version_code'] !== $build) {
            $errors['latest_build'] = "Latest Build must match the APK version code ({$meta['version_code']}). Publishing was blocked and the current APK was not changed.";
        }

        if ($errors !== []) {
            if (! array_key_exists('apk', $errors)) {
                $errors['apk'] = 'The APK version does not match the version and build entered here. Publishing was blocked and the current APK was not changed.';
            }

            throw ValidationException::withMessages($errors);
        }

        $size = filesize($path);

        return [
            'package' => $meta['package'],
            'version_name' => $meta['version_name'],
            'version_code' => $meta['version_code'],
            'size' => $size === false ? 0 : (int) $size,
            'sha256' => (string) hash_file('sha256', $path),
        ];
    }

    public function replaceLatest(UploadedFile $upload): void
    {
        $this->assertValidApk($upload);

        $destination = $this->publicPath();
        $directory = dirname($destination);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages([
                'apk' => 'Could not create the APK directory on the server. The current APK and version settings were not changed.',
            ]);
        }

        $source = $upload->getRealPath();
        if ($source === false || ! is_readable($source)) {
            throw ValidationException::withMessages([
                'apk' => 'Unable to read the uploaded APK. Please try again. The current APK and version settings were not changed.',
            ]);
        }

        $temporary = $destination.'.uploading';
        $backup = $destination.'.bak';
        $hadExisting = is_file($destination);

        try {
            $this->copyOrFail($source, $temporary);
            $this->assertValidApkFile($temporary, 'apk');

            if ($hadExisting) {
                $this->deleteIfExists($backup);
                $this->moveOrFail($destination, $backup);
            }

            try {
                $this->moveOrFail($temporary, $destination);
                $this->assertDownloadWorks();
            } catch (Throwable $exception) {
                $this->deleteIfExists($destination);
                $this->deleteIfExists($temporary);
                if ($hadExisting && is_file($backup)) {
                    $this->moveOrFail($backup, $destination);
                }

                throw $exception;
            }

            $this->deleteIfExists($backup);
        } catch (ValidationException $exception) {
            $this->deleteIfExists($temporary);

            throw $exception;
        } catch (Throwable $exception) {
            $this->deleteIfExists($temporary);

            throw ValidationException::withMessages([
                'apk' => 'APK upload failed: '.$exception->getMessage().' The current APK and version settings were not changed.',
            ]);
        }
    }

    public function assertDownloadWorks(): void
    {
        $path = $this->publicPath();
        if (! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'apk' => 'APK was written but could not be read at '.$this->downloadUrlPath().'. The previous APK has been restored and version settings were not changed.',
            ]);
        }

        $expectedSize = filesize($path);
        if ($expectedSize === false || $expectedSize < self::MIN_BYTES) {
            throw ValidationException::withMessages([
                'apk' => 'APK download file is empty or unreadable. The previous APK has been restored and version settings were not changed.',
            ]);
        }

        $this->assertValidApkFile($path, 'apk');

        $route = app('router')->getRoutes()->match(Request::create($this->downloadUrlPath(), 'GET'));
        if ($route->getName() !== 'mobile.apk.latest') {
            throw ValidationException::withMessages([
                'apk' => 'APK download URL '.$this->downloadUrlPath().' is not available. The previous APK has been restored and version settings were not changed.',
            ]);
        }

        $response = app(MobileApkDownloadController::class)($this);
        if (! $response instanceof BinaryFileResponse || $response->getStatusCode() !== 200) {
            throw ValidationException::withMessages([
                'apk' => 'APK download URL '.$this->downloadUrlPath().' did not succeed. The previous APK has been restored and version settings were not changed.',
            ]);
        }

        if ($response->getFile()->getSize() !== $expectedSize) {
            throw ValidationException::withMessages([
                'apk' => 'APK download URL did not serve the uploaded file. The previous APK has been restored and version settings were not changed.',
            ]);
        }
    }

    private function assertValidApk(UploadedFile $upload): void
    {
        $originalName = strtolower((string) $upload->getClientOriginalName());
        if (! str_ends_with($originalName, '.apk')) {
            throw ValidationException::withMessages([
                'apk' => 'Please upload a .apk file. The current APK and version settings were not changed.',
            ]);
        }

        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw ValidationException::withMessages([
                'apk' => $this->uploadErrorMessage($upload->getError()),
            ]);
        }

        $size = (int) $upload->getSize();
        if ($size < self::MIN_BYTES) {
            throw ValidationException::withMessages([
                'apk' => 'The selected file is too small to be a valid Android APK. The current APK and version settings were not changed.',
            ]);
        }

        if ($size > self::MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages([
                'apk' => 'APK is too large. Maximum size is 100 MB. The current APK and version settings were not changed.',
            ]);
        }

        $realPath = $upload->getRealPath();
        if ($realPath === false) {
            throw ValidationException::withMessages([
                'apk' => 'Unable to read the uploaded APK. Please try again. The current APK and version settings were not changed.',
            ]);
        }

        $this->assertValidApkFile($realPath, 'apk');
    }

    private function assertValidApkFile(string $path, string $attribute): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages([
                $attribute => 'Unable to read the APK file. The current APK and version settings were not changed.',
            ]);
        }

        $magic = (string) fread($handle, 4);
        fclose($handle);

        if (! str_starts_with($magic, 'PK')) {
            throw ValidationException::withMessages([
                $attribute => 'The selected file is not a valid Android APK. The current APK and version settings were not changed.',
            ]);
        }
    }

    private function copyOrFail(string $from, string $to): void
    {
        $this->deleteIfExists($to);

        if (! @copy($from, $to) || ! is_file($to)) {
            throw ValidationException::withMessages([
                'apk' => 'Could not copy the APK to the server. The current APK and version settings were not changed.',
            ]);
        }
    }

    private function moveOrFail(string $from, string $to): void
    {
        if (@rename($from, $to) && is_file($to)) {
            return;
        }

        $this->copyOrFail($from, $to);
        $this->deleteIfExists($from);
    }

    private function deleteIfExists(string $path): void
    {
        if (is_file($path)) {
            File::delete($path);
        }
    }

    private function uploadErrorMessage(int $error): string
    {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The APK is larger than the server upload limit. Increase PHP upload_max_filesize and post_max_size (at least 100M) and try again.',
            UPLOAD_ERR_PARTIAL => 'The APK upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No APK file was received. Please choose a .apk file and try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded APK. Please try again or contact support.',
            default => 'APK upload failed. Please try again.',
        };

        return $message.' The current APK and version settings were not changed.';
    }
}
