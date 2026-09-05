<?php

namespace App\Support;

use Filament\Actions\EditAction;
use Throwable;

use function Filament\Support\is_app_url;
use function Filament\Support\original_request;

final class FilamentEditReturnUrl
{
    public static function currentPageUrl(?string $excludeUrl = null): ?string
    {
        $candidates = [];

        if (app()->bound('originalRequest')) {
            try {
                $candidates[] = original_request()->fullUrl();
            } catch (Throwable) {
                // Filament's original request is not always bound in isolated tests.
            }
        }

        $candidates[] = url()->full();
        $candidates[] = url()->previous();

        foreach ($candidates as $candidate) {
            if (self::isUsableReturnUrl($candidate, $excludeUrl)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function isUsableReturnUrl(mixed $url, ?string $currentUrl = null): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        if (! is_app_url($url)) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        if ($path === '' || str_contains($path, '/livewire/')) {
            return false;
        }

        $currentUrl ??= url()->current();
        $currentPath = parse_url((string) $currentUrl, PHP_URL_PATH) ?? '';

        return $path !== $currentPath;
    }

    public static function urlForEditAction(EditAction $action): ?string
    {
        $livewire = $action->getLivewire();
        $record = $action->getRecord();

        if ($record === null || ! is_object($livewire) || ! method_exists($livewire, 'getResource')) {
            return null;
        }

        $resource = $livewire::getResource();
        if (! is_string($resource) || ! $resource::hasPage('edit')) {
            return null;
        }

        $editUrl = $resource::getUrl('edit', ['record' => $record]);
        $returnUrl = self::currentPageUrl($editUrl);

        if ($returnUrl === null) {
            return $editUrl;
        }

        $separator = str_contains($editUrl, '?') ? '&' : '?';

        return $editUrl.$separator.http_build_query(['returnUrl' => $returnUrl]);
    }
}
