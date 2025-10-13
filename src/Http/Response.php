<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function redirect(string $url, int $status = 302): never
    {
        header('Location: ' . $url, true, $status);
        exit;
    }

    /**
     * @param array<string, string>|string $message
     */
    public static function error(array|string $message, int $status = 400): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $payload = is_array($message) ? $message : ['message' => $message];
        echo json_encode([
            'status' => $status,
            'error' => $payload,
        ], JSON_THROW_ON_ERROR);
        exit;
    }
}