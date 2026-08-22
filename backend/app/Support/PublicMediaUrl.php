<?php

namespace App\Support;

class PublicMediaUrl
{
    /**
     * Turn a public-disk relative path (or a legacy filesystem path) into an
     * HTTP(S) URL using the current request host when available.
     */
    public static function fromPublicPath(?string $path): ?string
    {
        $relative = self::normalizePublicPath($path);
        if ($relative === null) {
            return null;
        }

        $url = url('storage/'.$relative);
        $host = parse_url($url, PHP_URL_HOST);
        $request = request();

        if (
            $request !== null
            && self::isLoopbackHost($host)
            && ! self::isLoopbackHost($request->getHost())
        ) {
            return $request->getSchemeAndHttpHost().'/storage/'.$relative;
        }

        return $url;
    }

    public static function normalizePublicPath(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $normalized = str_replace('\\', '/', trim($path));
        $normalized = preg_replace('#^[A-Za-z]:/#', '', $normalized) ?? $normalized;

        foreach (['storage/app/public/', '/storage/app/public/'] as $marker) {
            $pos = stripos($normalized, $marker);
            if ($pos !== false) {
                $normalized = substr($normalized, $pos + strlen($marker));
                break;
            }
        }

        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'public/storage/')) {
            $normalized = substr($normalized, strlen('public/storage/'));
        }
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = substr($normalized, strlen('storage/'));
        }

        return $normalized !== '' ? $normalized : null;
    }

    private static function isLoopbackHost(?string $host): bool
    {
        $host = strtolower((string) $host);

        return in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true);
    }
}
