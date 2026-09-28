<?php

namespace Tests\Support;

use ZipArchive;

class MinimalAndroidApk
{
    public static function bytes(
        string $package,
        string $versionName,
        int $versionCode,
        string $marker = 'PARAMGOLD-APK',
    ): string {
        $manifest = self::binaryManifest($package, $versionName, $versionCode);
        $path = tempnam(sys_get_temp_dir(), 'apk');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary APK.');
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('AndroidManifest.xml', $manifest);
        $zip->addFromString('assets/marker.txt', $marker.str_repeat('x', 1200));
        $zip->setCompressionName('assets/marker.txt', ZipArchive::CM_STORE);
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private static function binaryManifest(string $package, string $versionName, int $versionCode): string
    {
        $strings = ['manifest', 'package', $package, 'versionName', $versionName, 'versionCode'];
        $pool = self::stringPool($strings);
        $element = self::manifestElement($versionCode);
        $body = $pool.$element;

        return pack('vvV', 0x0003, 8, 8 + strlen($body)).$body;
    }

    /**
     * @param  list<string>  $strings
     */
    private static function stringPool(array $strings): string
    {
        $encoded = '';
        $offsets = [];
        foreach ($strings as $string) {
            $offsets[] = strlen($encoded);
            $encoded .= chr(strlen($string)).chr(strlen($string)).$string."\0";
        }

        $headerSize = 28;
        $offsetsBytes = '';
        foreach ($offsets as $offset) {
            $offsetsBytes .= pack('V', $offset);
        }

        $stringsStart = $headerSize + strlen($offsetsBytes);
        $chunk = pack('VVVV', count($strings), 0, 1 << 8, $stringsStart).pack('V', 0).$offsetsBytes.$encoded;
        $chunkSize = 8 + strlen($chunk);

        return pack('vvV', 0x0001, $headerSize, $chunkSize).$chunk;
    }

    private static function manifestElement(int $versionCode): string
    {
        $attributes = self::stringAttribute(1, 2)
            .self::stringAttribute(3, 4)
            .self::intAttribute(5, $versionCode);

        $attrExt = pack('VV', 0xFFFFFFFF, 0)
            .pack('vvvvvv', 20, 20, 3, 0, 0, 0)
            .$attributes;

        $headerSize = 16;
        $node = pack('VV', 0, 0xFFFFFFFF).$attrExt;
        $chunkSize = 8 + strlen($node);

        return pack('vvV', 0x0102, $headerSize, $chunkSize).$node;
    }

    private static function stringAttribute(int $nameIndex, int $valueIndex): string
    {
        return pack('VVVvCCV', 0xFFFFFFFF, $nameIndex, $valueIndex, 8, 0, 0x03, $valueIndex);
    }

    private static function intAttribute(int $nameIndex, int $value): string
    {
        return pack('VVVvCCV', 0xFFFFFFFF, $nameIndex, 0xFFFFFFFF, 8, 0, 0x10, $value);
    }
}
