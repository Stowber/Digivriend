<?php

declare(strict_types=1);

use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Lang\Translator;

if (!function_exists('render_main_nav')) {
    function platform_is_partner(): bool
    {
        return Auth::role() === 'partner';
    }

    function platform_subtitle(): string
    {
        return platform_is_partner()
            ? __('dashboard.header.subtitle_partner')
            : __('dashboard.header.subtitle');
    }

    function platform_theme_class(): string
    {
        return platform_is_partner() ? 'theme--partner' : '';
    }

    function platform_body_attributes(string $additionalClasses = ''): string
    {
        $classes = array_filter([
            trim($additionalClasses),
            platform_theme_class(),
        ], static fn (string $value): bool => $value !== '');

        if ($classes === []) {
            return '';
        }

        return ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
    }
    function render_main_nav(string $currentKey): void
    {
        $isPartner = platform_is_partner();

        $items = $isPartner
            ? [
                'intake' => ['label' => __('nav.intake'), 'href' => 'intake.php'],
                'device_register' => ['label' => __('nav.device_register'), 'href' => 'device-intake.php'],
                'partner_cases' => ['label' => __('nav.partner_cases'), 'href' => 'partner-cases.php'],
            ]
            : [
                'dashboard' => ['label' => __('nav.dashboard'), 'href' => 'index.php'],
                'intake' => ['label' => __('nav.intake'), 'href' => 'intake.php'],
                'customers' => ['label' => __('nav.customers'), 'href' => 'customers.php'],
                'devices' => ['label' => __('nav.devices'), 'href' => 'devices.php'],
                'archive' => ['label' => __('nav.archive'), 'href' => 'archive.php'],
                'data_recovery' => ['label' => __('nav.data_recovery'), 'href' => 'data-recovery.php'],
                'calendar' => ['label' => __('nav.calendar'), 'href' => 'calendar.php'],
                'documents' => ['label' => __('nav.documents'), 'href' => 'documents.php'],
                'inventory' => ['label' => __('nav.inventory'), 'href' => 'magazyn.php'],
                'pc_builder' => ['label' => __('nav.pc_builder'), 'href' => 'pc-builder.php'],
                'partners' => ['label' => __('nav.partners'), 'href' => 'partners.php'],
                'employees' => ['label' => __('nav.employees'), 'href' => 'employees.php'],
            ];

        if (!$isPartner && Auth::role() === 'admin') {
            $items['dump_database'] = [
                'label' => __('nav.dump_database'),
                'href' => 'dumpdatabase.php',
                'class' => 'nav-link--danger',
            ];
        }

        $logoutItem = ['label' => __('nav.logout'), 'href' => 'logout.php', 'class' => 'btn btn--ghost'];

        $currentLocale = Auth::check() ? Auth::language() : Translator::locale();
        $csrfToken = Csrf::token();
        $redirectTo = $_SERVER['REQUEST_URI'] ?? 'index.php';

        echo '<ul>';

        foreach ($items as $key => $item) {
            $attributes = [];
            if ($currentKey === $key) {
                $attributes['aria-current'] = 'page';
            }

            if (isset($item['class']) && is_string($item['class']) && $item['class'] !== '') {
                $attributes['class'] = $item['class'];
            }

            $attributeString = '';
            foreach ($attributes as $name => $value) {
                $attributeString .= sprintf(
                    ' %s="%s"',
                    htmlspecialchars((string) $name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
            }

            echo sprintf(
                '<li><a href="%s"%s>%s</a></li>',
                htmlspecialchars($item['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $attributeString,
                htmlspecialchars($item['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            );
        }

        echo '<li class="main-nav__language">';
        echo '<form method="post" action="language.php" class="language-switcher">';
        echo '<label for="main-nav-language" class="sr-only">' . htmlspecialchars(__('language.switcher.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</label>';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        echo '<input type="hidden" name="redirect" value="' . htmlspecialchars($redirectTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        echo '<select id="main-nav-language" name="locale" onchange="this.form.submit()">';

        foreach (Translator::availableLocales() as $locale) {
            $selected = $locale === $currentLocale ? ' selected' : '';
            echo '<option value="' . htmlspecialchars($locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"' . $selected . '>';
            echo htmlspecialchars(language_name($locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '</option>';
        }

        echo '</select>';
        echo '</form>';
        echo '</li>';

        echo '<li class="main-nav__spacer" aria-hidden="true"></li>';
        echo sprintf(
            '<li><a href="%s" class="%s">%s</a></li>',
            htmlspecialchars($logoutItem['href'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($logoutItem['class'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($logoutItem['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
        echo '</ul>';
    }
}