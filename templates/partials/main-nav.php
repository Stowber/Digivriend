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

    function language_flag(string $locale): string
    {
        return [
            'nl' => '🇳🇱',
            'pl' => '🇵🇱',
            'en' => '🇬🇧',
        ][strtolower(trim($locale))] ?? '🌐';
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
        echo '<form method="post" action="language.php" class="language-switcher" data-language-switcher>';
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        echo '<input type="hidden" name="redirect" value="' . htmlspecialchars($redirectTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        echo '<details class="language-switcher__dropdown">';
        echo '<summary class="language-switcher__trigger" role="button" aria-haspopup="menu" aria-label="' . htmlspecialchars(__('language.switcher.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        echo '<span class="language-switcher__planet" aria-hidden="true">';
        echo '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="M12 2a10 10 0 1 0 10 10A10.011 10.011 0 0 0 12 2Zm0 18a8 8 0 0 1-7.32-11.2 18.5 18.5 0 0 0 6.16 1.82 19.918 19.918 0 0 0 7.31-.76A8 8 0 0 1 12 20Zm7.28-10.83A17.84 17.84 0 0 1 12 10a17.6 17.6 0 0 1-6.88-1.45A8 8 0 0 1 17.38 4.8a19.506 19.506 0 0 1 1.9 4.37c.02.16.06.32.1.47a7.969 7.969 0 0 1-.1.47Z"></path></svg>';
        echo '</span>';
        echo '<span class="language-switcher__current">';
        echo '<span class="language-switcher__flag" aria-hidden="true">' . htmlspecialchars(language_flag($currentLocale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        echo '<span class="language-switcher__label">' . htmlspecialchars(language_name($currentLocale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        echo '</span>';
        echo '<span class="language-switcher__chevron" aria-hidden="true">';
        echo '<svg viewBox="0 0 24 24" role="presentation" focusable="false"><path d="m7 10 5 5 5-5"></path></svg>';
        echo '</span>';
        echo '</summary>';
        echo '<div class="language-switcher__menu" role="menu">';

        foreach (Translator::availableLocales() as $locale) {
            $isActive = $locale === $currentLocale;
            $state = $isActive ? ' aria-current="true"' : '';
            echo '<button type="submit" name="locale" value="' . htmlspecialchars($locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="language-switcher__option" role="menuitem"' . $state . '>';
            echo '<span class="language-switcher__flag" aria-hidden="true">' . htmlspecialchars(language_flag($locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
            echo '<span class="language-switcher__language">' . htmlspecialchars(language_name($locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
            if ($isActive) {
                echo '<span class="language-switcher__active" aria-hidden="true">•</span>';
            }
            echo '</button>';
        }

        echo '</div>';
        echo '</details>';
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
        echo '<script>
            (() => {
                if (window.__languageSwitcherInit) {
                    return;
                }

                window.__languageSwitcherInit = true;

                const closeAll = () => {
                    document.querySelectorAll(".language-switcher__dropdown[open]").forEach((dropdown) => {
                        dropdown.removeAttribute("open");
                    });
                };

                document.addEventListener("click", (event) => {
                    const target = event.target instanceof HTMLElement ? event.target : null;
                    document.querySelectorAll(".language-switcher__dropdown").forEach((dropdown) => {
                        if (!target || !dropdown.contains(target)) {
                            dropdown.removeAttribute("open");
                        }
                    });
                });

                document.addEventListener("keyup", (event) => {
                    if (event.key === "Escape") {
                        closeAll();
                    }
                });
            })();
        </script>';
    }
}