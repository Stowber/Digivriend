<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * Digivriend — EmailLayoutRenderer (v3, Blue+Orange, premium)
 *
 * - Mocny hero (blue→orange), poprawione kontrasty/typografia
 * - Logo: preferuje $contact['logo'] (URL/cid), fallback DATA-URI
 * - Szczegóły: gradient, mini-ikony SVG inline, ostatni wiersz bez obramowania
 * - CTA: bulletproof (VML dla Outlook), gradient + fallback
 * - Preheader ukryty, a11y, dark-mode hint
 * - Footer: 4 równe kolumny, baseline, responsywne stackowanie
 * - Proste media queries dla mobile
 */
final class EmailLayoutRenderer
{
    /** Fallback DATA-URI (Outlook może blokować SVG, zalecane $contact['logo'] = URL/cid). */
    private const LOGO_DATA_URI =
        'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB3aWR0aD0iMTYwIiBoZWlnaHQ9IjYwIiB2aWV3Qm94PSIwIDAgMTYwIDYwIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHJvbGU9ImltZyI+PHJlY3Qgd2lkdGg9IjE2MCIgaGVpZ2h0PSI2MCIgcng9IjEyIiBmaWxsPSIjMTIzMDVmIi8+PHBhdGggZmlsbD0iI2ZmZiIgZD0iTTQwIDM2aDgwYzEuMSAwIDEuOS44IDEuOSAxLjkgMCAxLjEtLjguMS0xLjkuMUg0MGMtMS4xIDAtMS45LS44LTEuOS0xLjkgMC0xLjEuOC0xLjkgMS45LTEuOXoiLz48Y2lyY2xlIGN4PSI0OCIgeyBjeT0iMzAiIHI9IjciIGZpbGw9IiNmOTczMTYiLz48dGV4dCB4PSI3OCIgeT0iMzkiIGZpbGw9IiNmZmYiIGZvbnQtZmFtaWx5PSJTZWdvcGUgVUksSGVsdmV0aWNhLEFyaWFsLHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMTciIHRleHQtYW5jaG9yPSJtaWRkbGUiPkRpZ2l2cmllbmQ8L3RleHQ+PC9zdmc+';

    public function renderEmailLayout(
        string $preheader,
        string $headline,
        array $introParagraphs,
        array $detailRows,
        ?array $cta,
        array $additionalParagraphs,
        array $contact
    ): string {
        $preheaderText  = $this->escape($preheader);
        $headlineText   = $this->escape($headline);
        $introHtml      = $this->buildParagraphs($introParagraphs);
        $detailsHtml    = $this->buildDetailRows($detailRows);
        $ctaHtml        = $this->buildCtaBlock($cta);
        $additionalHtml = $this->buildParagraphs($additionalParagraphs, true);
        $contactHtml    = $this->buildContactBlock($contact);
        $logoHtml       = $this->getLogoHtml($contact);
        $year           = date('Y');

        $signatureHtml = '<div style="margin-top:36px;">'
            . '<p style="margin:0 0 6px; font-size:15px; line-height:1.7; color:#1b2c59;">Met vriendelijke groet,</p>'
            . '<p style="margin:0; font-size:15px; line-height:1.7; color:#0f1f3d; font-weight:800; letter-spacing:0.2px;">Team Digivriend</p>'
            . '</div>';

        return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta http-equiv="x-ua-compatible" content="ie=edge"/>
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<title>Digivriend</title>
<style>
@media screen and (max-width:600px){
  h1{font-size:24px !important; line-height:1.3 !important;}
  p,td{font-size:15px !important;}
  .pv-wrap{padding-left:18px !important; padding-right:18px !important;}
  .contact-col{display:block !important; width:100% !important; padding:8px 0 !important;}
}
a[x-apple-data-detectors]{color:inherit !important; text-decoration:none !important;}
a:hover{opacity:.95;}
</style>
</head>
<body style="margin:0; padding:0; background-color:#0a1222; font-family:'Segoe UI',Helvetica,Arial,sans-serif; color:#0f172a;">
  <!-- Preheader (ukryty) -->
  <div style="display:none; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden; max-height:0; max-width:0; line-height:0; mso-hide:all;">
    {$preheaderText}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
  </div>

  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%;">
    <tr>
      <td align="center" style="padding:52px 16px;">
        <!-- CARD -->
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:760px; width:100%; background:#ffffff; border-radius:28px; overflow:hidden; box-shadow:0 28px 80px rgba(7,12,28,0.35);">
          <!-- HERO -->
          <tr>
            <td style="padding:0; background:linear-gradient(135deg,#13244f 0%,#1b2c59 38%,#2563eb 72%,#f97316 125%);">
              <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td align="center" style="padding:26px 24px 12px;">
                    {$logoHtml}
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding:6px 24px 0;">
                    <span style="display:inline-block; padding:7px 14px; border-radius:999px; background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.2); font-size:12px; font-weight:800; letter-spacing:.6px; text-transform:uppercase; color:#e6efff;">Service update</span>
                  </td>
                </tr>
                <tr>
                  <td align="center" class="pv-wrap" style="padding:18px 32px 26px;">
                    <h1 style="margin:0; font-size:32px; line-height:1.25; letter-spacing:-.2px; font-weight:900; color:#ffffff;">{$headlineText}</h1>
                    <p style="margin:10px 0 0; font-size:14px; line-height:1.75; color:rgba(255,255,255,.92);">Betrouwbare computerhulp aan huis</p>
                  </td>
                </tr>
                <!-- BLUE→ORANGE STRIPES -->
                <tr>
                  <td style="padding:0;">
                    <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                      <tr>
                        <td width="55%" style="height:6px; background:#2563eb; font-size:0; line-height:0;">&nbsp;</td>
                        <td width="20%" style="height:6px; background:#3b82f6; font-size:0; line-height:0;">&nbsp;</td>
                        <td width="25%" style="height:6px; background:#f97316; font-size:0; line-height:0;">&nbsp;</td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- INTRO -->
          <tr>
            <td class="pv-wrap" style="padding:36px 44px 4px;">
              {$introHtml}
            </td>
          </tr>

          <!-- DETAIL TABLE -->
          <tr>
            <td class="pv-wrap" style="padding:6px 24px 6px;">
              {$detailsHtml}
            </td>
          </tr>

          <!-- CTA -->
          <tr>
            <td class="pv-wrap" style="padding:10px 44px 12px;">
              {$ctaHtml}
            </td>
          </tr>

          <!-- EXTRA / SIGNATURE -->
          <tr>
            <td class="pv-wrap" style="padding:8px 44px 34px;">
              {$additionalHtml}
              {$signatureHtml}
            </td>
          </tr>

          <!-- CONTACT STRIP -->
          <tr>
            <td style="background:#0f1f3d; padding:26px 44px 30px; border-top:1px solid rgba(255,255,255,0.12);">
              {$this->buildContactBlock($contact)}
            </td>
          </tr>
        </table>

        <!-- FOOTER -->
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:760px; width:100%; margin-top:18px;">
          <tr>
            <td align="center" style="font-size:12px; color:rgba(226,232,240,0.72);">
              © {$year} Digivriend · Service met een glimlach
            </td>
          </tr>
        </table>

      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /** Preferuj $contact['logo'] = URL lub "cid:logo". W przeciwnym razie fallback DATA-URI. */
    private function getLogoHtml(array $contact): string
    {
        $src = '';
        if (isset($contact['logo']) && is_string($contact['logo']) && trim($contact['logo']) !== '') {
            $src = trim($contact['logo']);
        }
        if ($src === '') {
            $src = self::LOGO_DATA_URI; // fallback
        }
        $safe = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // max-height (Retina), bez sztywnych width/height — lepsze DPI
        return '<img src="'.$safe.'" alt="Digivriend — Betrouwbare computerhulp aan huis" style="display:block; max-height:60px; width:auto; height:auto; border:0; outline:none; text-decoration:none; background:#ffffff; border-radius:12px; padding:8px;">';
    }

    /** Plain blok używany w różnych miejscach */
    public function renderPlainBlock(string $content): string
    {
        $text = trim($content);

        if ($text === '') {
            return '<p style="margin:0; font-family:\'Segoe UI\',Helvetica,Arial,sans-serif; font-size:16px; line-height:1.75; color:#0f172a;">&nbsp;</p>';
        }

        return '<p style="margin:0 0 20px; font-family:\'Segoe UI\',Helvetica,Arial,sans-serif; font-size:16px; line-height:1.75; color:#0f172a;">'
            . nl2br($this->escape($text))
            . '</p>';
    }

    /** Paragrafy treści (subtle = łagodniejszy kolor / mniejszy font) */
    private function buildParagraphs(array $paragraphs, bool $subtle = false): string
    {
        $blocks = [];

        foreach ($paragraphs as $paragraph) {
            if (!is_string($paragraph)) {
                continue;
            }

            $trimmed = trim($paragraph);
            if ($trimmed === '') {
                continue;
            }

            $blocks[] = sprintf(
                '<p style="margin:0 0 %dpx; font-size:%s; line-height:1.75; color:%s;">%s</p>',
                $subtle ? 14 : 20,
                $subtle ? '14px' : '16px',
                $subtle ? 'rgba(15,23,42,0.75)' : '#0f172a',
                nl2br($this->escape($trimmed))
            );
        }

        return implode('', $blocks);
    }

    /** Mini-ikona SVG (inline, bez zewn. obrazków) */
    private function smallIcon(string $name): string
    {
        $icons = [
            'date'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="#2563eb" xmlns="http://www.w3.org/2000/svg" style="vertical-align:-2px;margin-right:8px"><path d="M7 2v2H5a2 2 0 0 0-2 2v2h18V6a2 2 0 0 0-2-2h-2V2h-2v2H9V2H7zm14 8H3v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V10z"/></svg>',
            'ref'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="#2563eb" xmlns="http://www.w3.org/2000/svg" style="vertical-align:-2px;margin-right:8px"><path d="M3 5a2 2 0 0 1 2-2h8l6 6v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5zm9 1H5v12h14V10h-7V6z"/></svg>',
            'desc'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="#2563eb" xmlns="http://www.w3.org/2000/svg" style="vertical-align:-2px;margin-right:8px"><path d="M4 6h16v2H4V6zm0 5h16v2H4v-2zm0 5h10v2H4v-2z"/></svg>',
            'default'=> '<svg width="14" height="14" viewBox="0 0 24 24" fill="#2563eb" xmlns="http://www.w3.org/2000/svg" style="vertical-align:-2px;margin-right:8px"><circle cx="12" cy="12" r="10"/></svg>',
        ];
        return $icons[$name] ?? $icons['default'];
    }

    /** Tabela szczegółów z gradientem, ikonami i ostatnim wierszem bez dolnej krawędzi */
    private function buildDetailRows(array $rows): string
    {
        $rowCells = [];

        // Obsługa formatów: ['Label'=>'Value'], [['label'=>'...','value'=>'...']], [['Label'=>'Value']]
        foreach ($rows as $key => $value) {
            $label = '';
            $val   = '';

            if (is_array($value)) {
                if (array_key_exists('label', $value) && array_key_exists('value', $value)) {
                    $label = (string)$value['label'];
                    $val   = (string)$value['value'];
                } else {
                    $firstKey = array_key_first($value);
                    if ($firstKey !== null) {
                        $label = (string)$firstKey;
                        $val   = (string)$value[$firstKey];
                    }
                }
            } else {
                $label = is_string($key) ? $key : (string)$key;
                $val   = (string)$value;
            }

            $label = trim($label);
            $val   = trim($val);

            if ($label === '' || $val === '') {
                continue;
            }

            // Ikony dla typowych etykiet
            $lower = mb_strtolower($label, 'UTF-8');
            $icon  = $this->smallIcon(
                str_contains($lower, 'afspraak') || str_contains($lower, 'datum') ? 'date' :
                (str_contains($lower, 'refer') || str_contains($lower, 'code') ? 'ref' :
                (str_contains($lower, 'besch') || str_contains($lower, 'opis') ? 'desc' : 'default'))
            );

            $labelText = $icon . '<span style="vertical-align:middle;">' . $this->escape($label) . '</span>';
            $valueText = nl2br($this->escape($val));

            $rowCells[] = [
                'left'  => '<td style="padding:18px 20px; width:40%; font-size:12px; font-weight:900; letter-spacing:.5px; text-transform:uppercase; color:#0f1f3d; background:linear-gradient(180deg,#eef2ff,#f7f9ff); border-bottom:1px solid #e6eaf5;">'.$labelText.'</td>',
                'right' => '<td style="padding:18px 20px; font-size:15px; color:#0f172a; background:#ffffff; border-bottom:1px solid #edf2f7;">'.$valueText.'</td>',
            ];
        }

        if ($rowCells === []) {
            return '';
        }

        // Ostatni wiersz bez dolnej krawędzi
        $last = count($rowCells) - 1;
        $rowCells[$last]['left']  = str_replace('border-bottom:1px solid #e6eaf5;', 'border-bottom:none;', $rowCells[$last]['left']);
        $rowCells[$last]['right'] = str_replace('border-bottom:1px solid #edf2f7;', 'border-bottom:none;', $rowCells[$last]['right']);

        $rowsMarkup = '';
        foreach ($rowCells as $cells) {
            // Divider pionowy między kolumnami
            $cells['left'] = str_replace('">', '; border-right:1px solid #e6eaf5;">', $cells['left']);
            $rowsMarkup .= '<tr>'.$cells['left'].$cells['right'].'</tr>';
        }

        return <<<HTML
<div style="margin:8px 0 0;">
  <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="width:100%; border-radius:18px; overflow:hidden; border:1px solid rgba(37,99,235,.28); background:linear-gradient(145deg,#f8fbff,#ffffff);">
    {$rowsMarkup}
  </table>
</div>
HTML;
    }

    /**
     * CTA z VML (Outlook), gradientowa scena i fallbacki.
     * Akceptuje: ['label'=>'...', 'url'=>'https://...', 'subtext'=>'...'] lub ['label','url','subtext']
     */
    private function buildCtaBlock(?array $cta): string
    {
        if ($cta === null) {
            return '';
        }

        $label   = isset($cta['label']) ? (string) $cta['label'] : (string) ($cta[0] ?? '');
        $url     = isset($cta['url'])   ? (string) $cta['url']   : (string) ($cta[1] ?? '');
        $subtext = isset($cta['subtext']) ? (string) $cta['subtext'] : (string) ($cta[2] ?? '');

        $hasLabel   = trim($label) !== '';
        $hasUrl     = trim($url) !== '';
        $hasSubtext = trim($subtext) !== '';

        if (!$hasLabel && !$hasSubtext) {
            return '';
        }

        $labelText   = $this->escape($label);
        $subtextText = $hasSubtext ? nl2br($this->escape($subtext)) : '';

        if ($hasUrl && $hasLabel) {
            $urlText = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subtextBlock = $hasSubtext
                ? '<p style="margin:14px 0 0; font-size:13px; color:rgba(255,255,255,0.92);">'.$subtextText.'</p>'
                : '';

            return <<<HTML
<div style="margin:32px 0 12px; padding:26px; border-radius:20px; background:linear-gradient(135deg,#2563eb 0%, #1d4ed8 45%, #f97316 120%); background-color:#2563eb; text-align:center;">
  <!--[if mso]>
  <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="{$urlText}" style="height:48px;v-text-anchor:middle;width:280px;" arcsize="50%" strokecolor="#ffffff" fillcolor="#ffffff">
    <w:anchorlock/>
    <center style="color:#0f1f3d; font-family:Segoe UI, Helvetica, Arial, sans-serif; font-size:15px; font-weight:800;">{$labelText}</center>
  </v:roundrect>
  <![endif]-->
  <!--[if !mso]><!-- -->
  <a href="{$urlText}" target="_blank" rel="noopener"
     style="display:inline-block; padding:14px 26px; background:#ffffff; color:#0f1f3d; font-weight:900; font-size:15px; border-radius:999px; text-decoration:none; box-shadow:0 12px 28px rgba(15,31,61,.28);">
    {$labelText}
  </a>
  <!--<![endif]-->
  {$subtextBlock}
</div>
HTML;
        }

        // Bez URL – informacyjny box
        $content = '<p style="margin:0; font-size:15px; font-weight:800; color:#0f1f3d;">'.$labelText.'</p>';
        if ($hasSubtext) {
            $content .= '<p style="margin:8px 0 0; font-size:13px; color:#1f2a44;">'.$subtextText.'</p>';
        }
        return '<div style="margin-top:24px; padding:18px; border-radius:16px; background:#eaf1ff;">'.$content.'</div>';
    }

    /**
     * Sekcja kontaktu – 4 równe kolumny, baseline i responsywne stackowanie.
     * Placeholder „—” dla pustych pól.
     */
    private function buildContactBlock(array $contact): string
    {
        $email   = isset($contact['email'])   ? trim((string) $contact['email'])   : '';
        $phone   = isset($contact['phone'])   ? trim((string) $contact['phone'])   : '';
        $address = isset($contact['address']) ? trim((string) $contact['address']) : '';
        $website = isset($contact['website']) ? trim((string) $contact['website']) : '';

        $labelCss = 'display:block; font-size:11px; line-height:1.2; letter-spacing:.6px; text-transform:uppercase; color:rgba(255,255,255,.66); margin:0 0 6px;';
        $valCss   = 'display:block; font-size:15px; line-height:1.6; font-weight:800; color:#e7eefc; text-decoration:none; margin:0;';
        $colCss   = 'width:25%; vertical-align:top; padding:8px 18px;';

        $labelEmail   = 'E&#8209;mail';
        $labelPhone   = 'Telefoon';
        $labelAddress = 'Adres';
        $labelWebsite = 'Website';

        $emailHtml = $email !== ''
            ? '<a href="mailto:'.htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" style="'.$valCss.' color:#fb923c;">'.$this->escape($email).'</a>'
            : '<span style="'.$valCss.' color:rgba(255,255,255,.7); font-weight:600;">—</span>';

        if ($phone !== '') {
            $sanitisedPhoneLink = preg_replace('/[^+\d]/', '', $phone);
            if ($sanitisedPhoneLink === null || $sanitisedPhoneLink === '') {
                $sanitisedPhoneLink = $phone;
            }
            $phoneHtml = '<a href="tel:'.htmlspecialchars($sanitisedPhoneLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" style="'.$valCss.' color:#93c5fd;">'.$this->escape($phone).'</a>';
        } else {
            $phoneHtml = '<span style="'.$valCss.' color:rgba(255,255,255,.7); font-weight:600;">—</span>';
        }

        $addressHtml = $address !== ''
            ? '<span style="'.$valCss.' color:rgba(255,255,255,.92); font-weight:700; white-space:normal; word-break:break-word;">'.$this->escape($address).'</span>'
            : '<span style="'.$valCss.' color:rgba(255,255,255,.7); font-weight:600;">—</span>';

        $websiteHtml = $website !== ''
            ? '<a href="'.htmlspecialchars($website, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" target="_blank" rel="noopener" style="'.$valCss.' color:#60a5fa; text-decoration:underline; word-break:break-word; overflow-wrap:anywhere;">'.$this->escape($website).'</a>'
            : '<span style="'.$valCss.' color:rgba(255,255,255,.7); font-weight:600;">—</span>';

        $heading = '<p style="margin:0 0 12px; font-size:16px; line-height:1.3; font-weight:900; color:#ffffff;">Even snel contact opnemen?</p>';

        return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="table-layout:fixed;">'
             . '<tr><td colspan="4" style="padding:0 0 4px; border-top:1px solid rgba(255,255,255,0.12);">'.$heading.'</td></tr>'
             . '<tr>'
                . '<td class="contact-col" width="25%" style="'.$colCss.' padding-left:0;">'
                    . '<span style="'.$labelCss.'">'.$labelEmail.'</span>'.$emailHtml
                . '</td>'
                . '<td class="contact-col" width="25%" style="'.$colCss.'">'
                    . '<span style="'.$labelCss.'">'.$labelPhone.'</span>'.$phoneHtml
                . '</td>'
                . '<td class="contact-col" width="25%" style="'.$colCss.'">'
                    . '<span style="'.$labelCss.'">'.$labelAddress.'</span>'.$addressHtml
                . '</td>'
                . '<td class="contact-col" width="25%" style="'.$colCss.' padding-right:0;">'
                    . '<span style="'.$labelCss.'">'.$labelWebsite.'</span>'.$websiteHtml
                . '</td>'
             . '</tr>'
             . '</table>';
    }

    /** HTML escape helper */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
