<?php

declare(strict_types=1);

use App\Security\Auth;

if (!function_exists('render_main_nav')) {
    function render_main_nav(string $currentKey): void
    {
        $items = [
            'dashboard' => ['label' => 'Dashboard', 'href' => 'index.php'],
            'intake' => ['label' => 'Klant registratie', 'href' => 'intake.php'],
            'devices' => ['label' => 'Klanten & apparaten', 'href' => 'devices.php'],
            'archive' => ['label' => 'Archief', 'href' => 'archive.php'],
            'data_recovery' => ['label' => 'Data Recovery', 'href' => 'data-recovery.php'],
            'calendar' => ['label' => 'Kalendarz', 'href' => 'calendar.php'],
            'documents' => ['label' => 'Documenten', 'href' => 'documents.php'],
            'inventory' => ['label' => 'Magazyn', 'href' => 'magazyn.php'],
            'pc_builder' => ['label' => 'Budowa PC', 'href' => 'pc-builder.php'],
            'employees' => ['label' => 'Pracownicy', 'href' => 'employees.php'],
        ];

        if (Auth::role() === 'admin') {
            $items['dump_database'] = [
                'label' => 'DUMPDATABASE',
                'href' => 'dumpdatabase.php',
                'class' => 'nav-link--danger',
            ];
        }

        $logoutItem = ['label' => 'Afmelden', 'href' => 'logout.php', 'class' => 'btn btn--ghost'];

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