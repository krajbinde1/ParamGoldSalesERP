<?php

namespace App\Services\MobileApp;

use RuntimeException;
use ZipArchive;

class AndroidApkMetadata
{
    /**
     * @return array{package: string, version_name: string, version_code: int}
     */
    public function read(string $apkPath): array
    {
        $manifest = $this->manifestBytes($apkPath);
        $values = $this->manifestAttributes($manifest);

        $package = $values['package'] ?? '';
        $versionName = $values['versionName'] ?? '';
        $versionCode = $values['versionCode'] ?? null;

        if ($package === '' || $versionName === '' || ! is_int($versionCode)) {
            throw new RuntimeException('The APK manifest does not contain a package name, version name, and version code.');
        }

        return [
            'package' => $package,
            'version_name' => $versionName,
            'version_code' => $versionCode,
        ];
    }

    private function manifestBytes(string $apkPath): string
    {
        $zip = new ZipArchive;
        $opened = $zip->open($apkPath);
        if ($opened !== true) {
            throw new RuntimeException('The uploaded file is not a readable APK archive.');
        }

        $manifest = $zip->getFromName('AndroidManifest.xml');
        $zip->close();

        if (! is_string($manifest) || $manifest === '') {
            throw new RuntimeException('The APK does not contain AndroidManifest.xml.');
        }

        return $manifest;
    }

    /**
     * @return array<string, string|int>
     */
    private function manifestAttributes(string $xml): array
    {
        if (strlen($xml) < 8) {
            throw new RuntimeException('The APK manifest is incomplete.');
        }

        $type = $this->u16($xml, 0);
        if ($type !== 0x0003) {
            throw new RuntimeException('The APK manifest is not Android binary XML.');
        }

        $offset = 8;
        $strings = [];
        $limit = strlen($xml);

        while ($offset + 8 <= $limit) {
            $chunkType = $this->u16($xml, $offset);
            $headerSize = $this->u16($xml, $offset + 2);
            $chunkSize = $this->u32($xml, $offset + 4);
            if ($chunkSize < 8 || $offset + $chunkSize > $limit) {
                break;
            }

            if ($chunkType === 0x0001) {
                $strings = $this->stringPool($xml, $offset, $headerSize);
            }

            if ($chunkType === 0x0102) {
                $name = $strings[$this->u32($xml, $offset + $headerSize + 4)] ?? '';
                if ($name === 'manifest') {
                    return $this->elementAttributes($xml, $offset, $headerSize, $strings);
                }
            }

            $offset += $chunkSize;
        }

        throw new RuntimeException('The APK manifest has no manifest element.');
    }

    /**
     * @return list<string>
     */
    private function stringPool(string $xml, int $offset, int $headerSize): array
    {
        $stringCount = $this->u32($xml, $offset + 8);
        $flags = $this->u32($xml, $offset + 16);
        $stringsStart = $this->u32($xml, $offset + 20);
        $utf8 = ($flags & (1 << 8)) !== 0;
        $offsets = [];

        for ($index = 0; $index < $stringCount; $index++) {
            $offsets[] = $this->u32($xml, $offset + $headerSize + ($index * 4));
        }

        $strings = [];
        foreach ($offsets as $stringOffset) {
            $position = $offset + $stringsStart + $stringOffset;
            $strings[] = $utf8
                ? $this->utf8String($xml, $position)
                : $this->utf16String($xml, $position);
        }

        return $strings;
    }

    /**
     * @param  list<string>  $strings
     * @return array<string, string|int>
     */
    private function elementAttributes(string $xml, int $offset, int $headerSize, array $strings): array
    {
        $attributeStart = $this->u16($xml, $offset + $headerSize + 8);
        $attributeSize = $this->u16($xml, $offset + $headerSize + 10);
        $attributeCount = $this->u16($xml, $offset + $headerSize + 12);
        if ($attributeSize < 20) {
            $attributeSize = 20;
        }

        $values = [];
        $cursor = $offset + $headerSize + $attributeStart;

        for ($index = 0; $index < $attributeCount; $index++) {
            $nameIndex = $this->u32($xml, $cursor + 4);
            $rawIndex = $this->u32($xml, $cursor + 8);
            $dataType = ord($xml[$cursor + 15]);
            $data = $this->u32($xml, $cursor + 16);
            $name = $strings[$nameIndex] ?? '';

            if ($dataType === 0x03 && isset($strings[$rawIndex])) {
                $values[$name] = $strings[$rawIndex];
            } elseif (in_array($dataType, [0x10, 0x11, 0x12], true)) {
                $values[$name] = $data;
            }

            $cursor += $attributeSize;
        }

        return $values;
    }

    private function utf8String(string $xml, int $position): string
    {
        $position += (ord($xml[$position]) & 0x80) !== 0 ? 2 : 1;
        $byteLength = ord($xml[$position]);
        $position++;
        if (($byteLength & 0x80) !== 0) {
            $byteLength = (($byteLength & 0x7F) << 8) | ord($xml[$position]);
            $position++;
        }

        return substr($xml, $position, $byteLength);
    }

    private function utf16String(string $xml, int $position): string
    {
        $charLength = $this->u16($xml, $position);
        $position += 2;
        if (($charLength & 0x8000) !== 0) {
            $charLength = (($charLength & 0x7FFF) << 16) | $this->u16($xml, $position);
            $position += 2;
        }

        $bytes = substr($xml, $position, $charLength * 2);

        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
    }

    private function u16(string $bytes, int $offset): int
    {
        return unpack('v', substr($bytes, $offset, 2))[1];
    }

    private function u32(string $bytes, int $offset): int
    {
        return unpack('V', substr($bytes, $offset, 4))[1];
    }
}
