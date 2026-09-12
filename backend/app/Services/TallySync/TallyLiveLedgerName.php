<?php

namespace App\Services\TallySync;

final class TallyLiveLedgerName
{
    /**
     * Strict Live Tally name key. Names must be exactly equal after this cleanup.
     * Does not strip punctuation, shorten names, or apply fuzzy/partial matching.
     */
    public static function normalize(string $name): string
    {
        return mb_strtolower(self::canonical($name), 'UTF-8');
    }

    /**
     * Visible ledger/dealer name after encoding cleanup, keeping original case.
     */
    public static function canonical(string $name): string
    {
        $text = self::decode($name);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*&\s*/u', ' & ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @return array{
     *     raw: string,
     *     escaped: string,
     *     hex: string,
     *     length: int,
     *     char_length: int,
     *     normalized: string,
     *     normalized_length: int,
     *     normalized_char_length: int
     * }
     */
    public static function inspect(string $name): array
    {
        $normalized = self::normalize($name);

        return [
            'raw' => $name,
            'escaped' => self::escaped($name),
            'hex' => bin2hex($name),
            'length' => strlen($name),
            'char_length' => mb_strlen($name, 'UTF-8'),
            'normalized' => $normalized,
            'normalized_length' => strlen($normalized),
            'normalized_char_length' => mb_strlen($normalized, 'UTF-8'),
        ];
    }

    /**
     * @return array{
     *     erp: array<string, mixed>,
     *     tally: array<string, mixed>,
     *     exact_match: bool
     * }
     */
    public static function compare(string $erpName, string $tallyName): array
    {
        $erp = self::inspect($erpName);
        $tally = self::inspect($tallyName);

        return [
            'erp' => $erp,
            'tally' => $tally,
            'exact_match' => $erp['normalized'] !== '' && $erp['normalized'] === $tally['normalized'],
        ];
    }

    public static function escaped(string $name): string
    {
        $json = json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json) ? $json : addcslashes($name, "\0..\37");
    }

    public static function isDebugName(string $name): bool
    {
        return mb_stripos($name, 'mirai', 0, 'UTF-8') !== false;
    }

    private static function decode(string $name): string
    {
        $text = str_replace("\xC2\xA0", ' ', $name);
        $text = preg_replace('/&#(?:x0*4|0*4);/i', "\x04", $text) ?? $text;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = str_replace("\x00", '', $text);

        $separator = strpos($text, "\x04");
        if ($separator !== false) {
            $text = substr($text, 0, $separator);
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (is_string($normalized) && $normalized !== '') {
                $text = $normalized;
            }
        }

        $text = str_replace(
            [
                "\u{00A0}", "\u{1680}", "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}",
                "\u{2004}", "\u{2005}", "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}",
                "\u{200A}", "\u{202F}", "\u{205F}", "\u{3000}",
                "\u{FF06}", '＆',
                '（', '）', '［', '］',
            ],
            [
                ' ', ' ', ' ', ' ', ' ', ' ',
                ' ', ' ', ' ', ' ', ' ', ' ',
                ' ', ' ', ' ', ' ',
                '&', '&',
                '(', ')', '[', ']',
            ],
            $text,
        );
        $text = preg_replace('/[\p{Cf}\p{Cc}]+/u', '', $text) ?? $text;
        $text = preg_replace('/\p{Z}+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
