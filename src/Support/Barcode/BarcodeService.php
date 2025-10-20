<?php

declare(strict_types=1);

namespace App\Support\Barcode;

final class BarcodeService
{
    /** @var array<string, string> */
    private const CODE39_PATTERNS = [
        '0' => 'nnnwwnwnn',
        '1' => 'wnnwnnnnw',
        '2' => 'nnwwnnnnw',
        '3' => 'wnwwnnnnn',
        '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn',
        '6' => 'nnwwwnnnn',
        '7' => 'nnnwnnwnw',
        '8' => 'wnnwnnwnn',
        '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw',
        'B' => 'nnwnnwnnw',
        'C' => 'wnwnnwnnn',
        'D' => 'nnnnwwnnw',
        'E' => 'wnnnwwnnn',
        'F' => 'nnwnwwnnn',
        'G' => 'nnnnnwwnw',
        'H' => 'wnnnnwwnn',
        'I' => 'nnwnnwwnn',
        'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww',
        'L' => 'nnwnnnnww',
        'M' => 'wnwnnnnwn',
        'N' => 'nnnnwnnww',
        'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn',
        'Q' => 'nnnnnnwww',
        'R' => 'wnnnnnwwn',
        'S' => 'nnwnnnwwn',
        'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw',
        'V' => 'nwwnnnnnw',
        'W' => 'wwwnnnnnn',
        'X' => 'nwnnwnnnw',
        'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn',
        '-' => 'nwnnnnwnw',
        '.' => 'wwnnnnwnn',
        ' ' => 'nwwnnnwnn',
        '$' => 'nwnwnwnnn',
        '/' => 'nwnwnnnwn',
        '+' => 'nwnnnwnwn',
        '%' => 'nnnwnwnwn',
        '*' => 'nwnnwnwnn',
    ];

    public static function sanitize(string $value): string
    {
        $upper = strtoupper($value);
        $allowed = implode('', array_keys(self::CODE39_PATTERNS));
        $result = '';

        for ($i = 0, $length = strlen($upper); $i < $length; $i++) {
            $char = $upper[$i];
            if (str_contains($allowed, $char) && $char !== '*') {
                $result .= $char;
            }
        }

        return $result;
    }

    public static function renderToPng(string $value): string
    {
        $data = self::sanitize($value);
        if ($data === '') {
            $data = 'DIGIVRIEND';
        }

        $encoded = '*' . $data . '*';
        $barWidth = 3;
        $wideWidth = $barWidth * 3;
        $height = 120;
        $spacing = $barWidth;

        $characters = str_split($encoded);
        $columns = [];

        foreach ($characters as $character) {
            $pattern = self::CODE39_PATTERNS[$character] ?? null;
            if ($pattern === null) {
                continue;
            }
            $patternLength = strlen($pattern);
            for ($i = 0; $i < $patternLength; $i++) {
                $width = $pattern[$i] === 'n' ? $barWidth : $wideWidth;
                $isBar = $i % 2 === 0;
                for ($w = 0; $w < $width; $w++) {
                    $columns[] = $isBar;
                }
            }
            for ($s = 0; $s < $spacing; $s++) {
                $columns[] = false;
            }
        }

        if ($columns === []) {
            $columns = array_fill(0, 200, false);
        }

        if (function_exists('imagecreatetruecolor') || function_exists('imagecreate')) {
            return self::renderWithGd($columns, $height, $data);
        }

        return self::renderWithoutGd($columns, $height);
    }

    /**
     * @param array<int, bool> $columns
     */
    private static function renderWithGd(array $columns, int $height, string $data): string
    {
        $totalWidth = count($columns);
        $textHeight = 16;

        $imageHeight = $height + $textHeight + 10;
        $image = function_exists('imagecreatetruecolor')
            ? imagecreatetruecolor($totalWidth, $imageHeight)
            : imagecreate($totalWidth, $imageHeight);

        if ($image === false) {
            throw new \RuntimeException('Kan barcode-afbeelding niet genereren.');
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);

        foreach ($columns as $x => $isBar) {
            if ($isBar) {
                imageline($image, $x, 0, $x, $height, $black);
            }
        }

        $textX = max(0, (int) (($totalWidth - imagefontwidth(5) * strlen($data)) / 2));
        $textY = $height + 4;
        imagestring($image, 5, $textX, $textY, $data, $black);

        ob_start();
        imagepng($image);
        $pngData = (string) ob_get_clean();
        imagedestroy($image);

        return $pngData;
    }

    /**
     * @param array<int, bool> $columns
     */
    private static function renderWithoutGd(array $columns, int $height): string
    {
        $width = count($columns);
        $rows = [];

        for ($y = 0; $y < $height; $y++) {
            $row = chr(0); // filter type 0
            foreach ($columns as $isBar) {
                $row .= $isBar ? chr(0) : chr(255);
            }
            $rows[] = $row;
        }

        $rawData = implode('', $rows);
        $compressed = gzcompress($rawData);
        if ($compressed === false) {
            throw new \RuntimeException('Kan barcode-afbeelding niet genereren (compressie mislukt).');
        }

        $png = "\x89PNG\r\n\x1a\n";
        $png .= self::createChunk('IHDR', pack('N', $width) . pack('N', $height) . "\x08\x00\x00\x00\x00");
        $png .= self::createChunk('IDAT', $compressed);
        $png .= self::createChunk('IEND', '');

        return $png;
    }

    private static function createChunk(string $type, string $data): string
    {
        $length = pack('N', strlen($data));
        $crc = pack('N', crc32($type . $data));

        return $length . $type . $data . $crc;
    }
}