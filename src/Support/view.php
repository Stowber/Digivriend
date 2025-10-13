<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class View
{
    /**
     * @param array<string, mixed> $context
     */
    public static function render(string $template, array $context = []): string
    {
        $templatePath = __DIR__ . '/../../templates/' . ltrim($template, '/');
        if (!is_file($templatePath)) {
            throw new RuntimeException(sprintf('Sjabloon %s bestaat niet.', $template));
        }

        extract($context, EXTR_SKIP);

        ob_start();
        /** @psalm-suppress UnresolvableInclude */
        require $templatePath;
        return (string) ob_get_clean();
    }
}