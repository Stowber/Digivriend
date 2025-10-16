<?php

declare(strict_types=1);

use App\Support\FieldHelp;

if (!function_exists('render_field_help')) {
    function render_field_help(string $formKey, string $fieldKey): void
    {
        $help = FieldHelp::get($formKey, $fieldKey);

        if ($help === null) {
            return;
        }

        $id = 'help-' . preg_replace('/[^a-z0-9_\-]/i', '-', $formKey . '-' . $fieldKey);
        $label = $help['label'] ?? null;
        $description = $help['description'] ?? null;
        $example = $help['example'] ?? null;
        $validation = $help['validation'] ?? null;
        $roles = $help['roles'] ?? [];

        echo '<button type="button" class="field-help" aria-expanded="false" aria-controls="' . htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">?';
        echo '<span class="visually-hidden">Informatie over dit veld</span>';
        echo '</button>';
        echo '<div id="' . htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="field-help__popover" role="tooltip" hidden>';

        if (is_string($label) && $label !== '') {
            echo '<h4>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h4>';
        }

        if (is_string($description) && $description !== '') {
            echo '<p>' . nl2br(htmlspecialchars($description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }

        if (is_string($example) && $example !== '') {
            echo '<p class="field-help__example"><strong>Przykład:</strong> ' . htmlspecialchars($example, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        if (is_string($validation) && $validation !== '') {
            echo '<p class="field-help__validation"><strong>Walidacja:</strong> ' . htmlspecialchars($validation, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        if (is_array($roles) && $roles !== []) {
            echo '<p class="field-help__roles"><strong>Dla ról:</strong> ' . htmlspecialchars(implode(', ', array_map(static fn ($role): string => (string) $role, $roles)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        echo '</div>';
    }
}