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
        $textHeight = 16;
        $spacing = $barWidth;

        $totalWidth = 0;
        $characters = str_split($encoded);

        foreach ($characters as $character) {
            $pattern = self::CODE39_PATTERNS[$character] ?? null;
            if ($pattern === null) {
                continue;
            }
            for ($i = 0; $i < strlen($pattern); $i++) {
                $totalWidth += $pattern[$i] === 'n' ? $barWidth : $wideWidth;
            }
            $totalWidth += $spacing;
        }

        if ($totalWidth <= 0) {
            $totalWidth = 200;
        }

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

        $x = 0;
        foreach ($characters as $character) {
            $pattern = self::CODE39_PATTERNS[$character] ?? null;
            if ($pattern === null) {
                continue;
            }

            for ($i = 0; $i < strlen($pattern); $i++) {
                $width = $pattern[$i] === 'n' ? $barWidth : $wideWidth;
                if ($i % 2 === 0) {
                    imagefilledrectangle($image, $x, 0, $x + $width - 1, $height, $black);
                }
                $x += $width;
            }

            $x += $spacing;
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
}