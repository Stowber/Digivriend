<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * Aurora — EmailLayoutRenderer (v2, Blue+Orange)
 *
 * Założenia:
 * - Jednokolumnowy, max 640px, lekki i czytelny.
 * - Neutralna typografia, mocne akcenty w kolorach logo: niebieski + pomarańcz.
 * - Logo (URL/cid) → bezpieczny fallback DATA-URI → opcjonalnie DEFAULT_LOGO_URL.
 * - Sekcje: preheader, (opcjonalny) hero, nagłówek z paskiem akcentowym, brand pill, intro, detale (zebra),
 *   CTA (VML), dodatkowe, stopka kontaktowa.
 * - A11y i bezpieczeństwo: sanitizeUrl, poprawne alt/aria, autolinki.
 */
final class EmailLayoutRenderer
{
    // Opcjonalny domyślny URL logo (na samym końcu ścieżki fallbacków).
    private const DEFAULT_LOGO_URL = 'https://images.cdn-files-a.com/uploads/6903475/400_filter_nobg_6631d17c44750.png';

    // Minimalne logo w DATA-URI jako twardy fallback (zawsze dostępne).
    private const LOGO_DATA_URI =
        'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMTIwIiBoZWlnaHQ9IjM2IiB2aWV3Qm94PSIwIDAgMTIwIDM2IiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHJvbGU9ImltZyI+PHJlY3Qgd2lkdGg9IjEyMCIgaGVpZ2h0PSIzNiIgcng9IjE4IiBmaWxsPSIjZjVmN2ZiIi8+PHRleHQgeD0iNjAiIHk9IjIyIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmb250LWZhbWlseT0iU2Vnb2UgVUksQXJpYWwsSGVsdmV0aWNhLHNhbnMtc2VyaWYiIGZvbnQtc2l6ZT0iMTIiIGZpbGw9IiMyYTM4NWUiPkJUS08gTE9HTzwvdGV4dD48L3N2Zz4=';

    /** Lekki „theme” do łatwego rebrandu (BLUE + ORANGE). */
    private array $theme = [
        'brand'   => 'Digivriend',
        'tagline' => 'Betrouwbare computerhulp aan huis',
        'colors'  => [
            'page'      => '#f6f7fb',       // tło strony
            'card'      => '#ffffff',       // tło karty
            'text'      => '#0f172a',       // główny tekst
            'muted'     => '#475569',       // mniej istotny tekst
            'border'    => '#e6eaf2',       // granice komórek/sekcji (subtelne)
            'primary'   => '#2563EB',       // BLUE
            'primaryD'  => '#1D4ED8',       // BLUE (ciemniejszy)
            'accent'    => '#F97316',       // ORANGE
            'success'   => '#16a34a',
            'footer'    => '#0b1220',
            'footerTxt' => 'rgba(226,232,240,.88)',
        ],
        'radius' => '18px',
        'width'  => 640,
        'logo'   => [
            'width'     => 220,   // docelowa szerokość logo w px (można nadpisać)
            'maxHeight' => null,  // opcjonalne ograniczenie wysokości
        ],
    ];

    /**
     * @param array<int,string> $introParagraphs
     * @param array<int|string,mixed> $detailRows  ['Label'=>'Value']|[['label'=>'...','value'=>'...']]|[['Label'=>'Value']]
     * @param array{label?:string,url?:string,subtext?:string}|array<int,string>|null $cta
     * @param array<int,string> $additionalParagraphs
     * @param array{
     *   brand?:string,
     *   logo?:string,        // URL lub "cid:..."
     *   logo_width?:int,     // szerokość logo w px (alias: logoWidth)
     *   logo_height?:int,    // wysokość/maks. wysokość w px (alias: logoHeight)
     *   hero?:string,        // URL obrazka hero (opcjonalny)
     *   email?:string,
     *   phone?:string,
     *   address?:string,
     *   website?:string
     * } $contact
     */
    public function renderEmailLayout(
        string $preheader,
        string $headline,
        array $introParagraphs,
        array $detailRows,
        ?array $cta,
        array $additionalParagraphs,
        array $contact
    ): string {
        // Pozwól nadpisać brand z $contact
        if (!empty($contact['brand']) && is_string($contact['brand'])) {
            $this->theme['brand'] = $contact['brand'];
        }

        $year        = date('Y');
        $c           = $this->theme['colors'];
        $preheaderTx = $this->escape($preheader);
        $headlineTx  = $this->escape($headline);
        $taglineTx   = $this->escape($this->theme['tagline']);

        $logoHtml    = $this->renderLogo($contact);
        $heroHtml    = $this->renderHero($contact['hero'] ?? null);

        $introHtml   = $this->renderParagraphs($introParagraphs, false);
        $detailsHtml = $this->renderDetails($detailRows);
        $ctaHtml     = $this->renderCta($cta);
        $moreHtml    = $this->renderParagraphs($additionalParagraphs, true);
        $footerHtml  = $this->renderFooterContacts($contact);

        // brand pill (subtelny badge nad intro)
        $brandPill = '
<tr>
  <td class="wrap" style="padding:8px 40px 0;">
    <span style="
      display:inline-flex; align-items:center; gap:8px;
      padding:6px 12px; border-radius:999px;
      border:1px solid rgba(37,99,235,.28); background:rgba(37,99,235,.06);
      font-size:12px; font-weight:700; letter-spacing:.3px; color:'.$c['primary'].';
    ">
      <i style="display:inline-block; width:6px; height:6px; border-radius:999px; background:'.$c['accent'].';"></i>
      '.$this->escape($this->theme['brand']).'
    </span>
  </td>
</tr>';

        return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta http-equiv="x-ua-compatible" content="ie=edge"/>
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<meta name="format-detection" content="telephone=no,email=no,address=no,date=no,url=no">
<title>{$this->escape($this->theme['brand'])}</title>
<style>
.detail-table{border-collapse:separate; border-spacing:0;}
@media screen and (max-width:{$this->theme['width']}px){
  .wrap{padding-left:16px !important; padding-right:16px !important;}
  h1{font-size:24px !important; line-height:1.3 !important;}
  p,td{font-size:15px !important;}
  .detail-row{display:block !important;}
  .detail-label,
  .detail-value{display:block !important; width:100% !important; padding:12px 16px !important; box-sizing:border-box !important;}
  .detail-label{border-left-width:0 !important; border-left-color:transparent !important; border-top:3px solid {$c['accent']} !important; border-radius:12px 12px 0 0 !important; font-size:13px !important; letter-spacing:.25px !important;}
  .detail-value{border-radius:0 0 12px 12px !important; border-top:1px solid {$c['border']} !important;}
  .detail-table{border-radius:16px !important; overflow:hidden !important;}
}
@media screen and (max-width:480px){
  .detail-label{font-size:12px !important;}
  .detail-value{font-size:14px !important;}
}
a[x-apple-data-detectors]{color:inherit !important; text-decoration:none !important;}
a:hover{opacity:.96;}
</style>
</head>
<body style="margin:0; padding:0; background-color:{$c['page']}; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif; color:{$c['text']};">
  <!-- preheader (ukryty) -->
  <div style="display:none;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;max-height:0;max-width:0;line-height:0;mso-hide:all;">
    {$preheaderTx}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
  </div>

  <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td align="center" style="padding:36px 12px;">
        <!-- card -->
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:{$this->theme['width']}px;background:{$c['card']};border-radius:{$this->theme['radius']};overflow:hidden;box-shadow:0 18px 64px rgba(11,18,32,.12);">
          <tr>
            <td align="center" style="padding:24px 24px 8px;">
              {$logoHtml}
            </td>
          </tr>

          {$heroHtml}

          <!-- H1 + tagline + akcentowy pasek (blue→orange) -->
          <tr>
            <td align="center" class="wrap" style="padding:8px 40px 4px;">
              <h1 style="margin:0; font-size:28px; line-height:1.3; letter-spacing:-.2px; font-weight:800;">{$headlineTx}</h1>
              <p style="margin:10px 0 0; font-size:13px; color:{$c['muted']};">{$taglineTx}</p>
              <div style="margin:16px auto 0; height:4px; width:92px; border-radius:999px; background:linear-gradient(90deg, {$c['primary']} 0%, {$c['accent']} 100%);"></div>
            </td>
          </tr>

          <tr><td style="height:8px;"></td></tr>

          {$brandPill}

          <tr><td style="height:8px;"></td></tr>

          <!-- intro -->
          <tr>
            <td class="wrap" style="padding:0 40px 8px;">
              {$introHtml}
            </td>
          </tr>

          <!-- details (zebra) -->
          {$detailsHtml}

          <!-- cta -->
          <tr>
            <td class="wrap" style="padding:8px 40px 8px;">
              {$ctaHtml}
            </td>
          </tr>

          <!-- more + signature -->
          <tr>
            <td class="wrap" style="padding:8px 40px 28px;">
              {$moreHtml}
              <div style="margin-top:24px;">
                <p style="margin:0 0 6px; font-size:15px; color:{$c['muted']}">Met vriendelijke groet,</p>
                <p style="margin:0; font-size:15px; font-weight:800;">Team {$this->escape($this->theme['brand'])}</p>
              </div>
            </td>
          </tr>
        </table>

        <!-- footer -->
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:{$this->theme['width']}px; margin-top:14px;">
          <tr>
            <td style="background:{$c['footer']}; border-radius:{$this->theme['radius']}; padding:20px 24px; color:{$c['footerTxt']}; font-size:12px; line-height:1.65;">
              {$footerHtml}
              <div style="text-align:center; margin-top:8px; border-top:1px solid rgba(255,255,255,.12); padding-top:8px;">
                © {$year} {$this->escape($this->theme['brand'])}
              </div>
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

    /* ---------- Sekcje ---------- */

    /** Logo z bezpiecznymi fallbackami. */
    private function renderLogo(array $contact): string
    {
        $brand = $contact['brand'] ?? $this->theme['brand'] ?? 'Brand';

        $raw = isset($contact['logo']) && is_string($contact['logo']) ? trim($contact['logo']) : '';

        // 1) CID
        if ($raw !== '' && str_starts_with($raw, 'cid:')) {
            $src = $raw;
        } else {
            // 2) URL https/http
            $src = $this->sanitizeUrl($raw, ['https', 'http']) ?? null;
        }

        // 3) DATA-URI (lokalne)
        if ($src === null) {
            $src = self::LOGO_DATA_URI;
            // 4) Ewentualny zewnętrzny default (jeśli wolisz)
            $def = $this->sanitizeUrl(self::DEFAULT_LOGO_URL, ['https', 'http']);
            if ($def) {
                $src = $def;
            }
        }

        $alt  = $this->escape($brand . ' — ' . ($this->theme['tagline'] ?? ''));
        $safe = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $maxWidth  = $this->resolveLogoDimension($contact, ['logo_width', 'logoWidth'], $this->theme['logo']['width'] ?? null);
        $maxHeight = $this->resolveLogoDimension($contact, ['logo_height', 'logoHeight'], $this->theme['logo']['maxHeight'] ?? null);

        $style = [
            'display:block',
            'border:0',
            'outline:none',
            'text-decoration:none',
            $maxWidth !== null ? 'width:'.$maxWidth.'px' : 'width:auto',
            'height:auto',
        ];

        if ($maxHeight !== null) {
            $style[] = 'max-height:'.$maxHeight.'px';
        }

        $attributes = [
            'src="'.$safe.'"',
            'alt="'.$alt.'"',
            'style="'.implode('; ', $style).'"',
        ];

        if ($maxWidth !== null) {
            $attributes[] = 'width="'.$maxWidth.'"';
        }

        if ($maxHeight !== null && $this->hasContactKey($contact, ['logo_height', 'logoHeight'])) {
            $attributes[] = 'height="'.$maxHeight.'"';
        }

        return '<img '.implode(' ', $attributes).'>';
    }

    /** Opcjonalny hero (pełna szerokość karty). */
    private function renderHero(?string $heroUrl): string
    {
        $src = $this->sanitizeUrl($heroUrl ?? '', ['https','http']);
        if ($src === null) {
            return '';
        }
        $safe = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<tr>
  <td style="padding:0 0 0;">
    <img src="{$safe}" alt="" role="img" style="display:block; width:100%; height:auto; border:0; outline:none; text-decoration:none;">
  </td>
</tr>
HTML;
    }

    /**
     * Paragrafy: basic + „subtle” (mniejszy i bledszy).
     * @param array<int,string> $paragraphs
     */
    private function renderParagraphs(array $paragraphs, bool $subtle): string
    {
        $out = [];
        $color = $subtle ? $this->theme['colors']['muted'] : $this->theme['colors']['text'];
        foreach ($paragraphs as $p) {
            if (!is_string($p)) continue;
            $p = trim($p);
            if ($p === '') continue;

            $out[] = sprintf(
                '<p style="margin:0 0 %dpx; font-size:%s; line-height:1.7; color:%s;">%s</p>',
                $subtle ? 12 : 16,
                $subtle ? '14px' : '16px',
                $color,
                nl2br($this->escape($p))
            );
        }
        return implode('', $out);
    }

    /**
     * Tabela detali — zebra, wysoka czytelność, autolinki w wartości,
     * obramowanie niebieskawe i pomarańczowy pasek przy label.
     * @param array<int|string,mixed> $rows
     */
    private function renderDetails(array $rows): string
    {
        $norm = $this->normalizeRows($rows);
        if ($norm === []) {
            return '';
        }

        $c = $this->theme['colors'];
        $body = '';
        $lastIndex = array_key_last($norm);
        foreach ($norm as $i => $row) {
            $isEven = ($i % 2) === 0;
            $bg = $isEven ? '#fbfcfe' : '#ffffff';

            $label = $this->escape($row['label']);
            $value = nl2br($this->autoLink($row['value']));

            $borderBottom = $i === $lastIndex
                ? 'border-bottom:none;'
                : 'border-bottom:1px solid '.$c['border'].';';

            $labelStyle = 'padding:14px 16px; font-size:12px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; '
                .'color:'.$c['muted'].'; background:'.$bg.'; '.$borderBottom.' border-left:4px solid '.$c['accent'].';';

            $valueStyle = 'padding:14px 16px; font-size:15px; color:'.$c['text'].'; background:'.$bg.'; '.$borderBottom;

            $body .= '<tr class="detail-row">'.
                        '<td class="detail-label" width="34%" valign="top" style="'.$labelStyle.'">'.$label.'</td>'.
                        '<td class="detail-value" valign="top" style="'.$valueStyle.'">'.$value.'</td>'.
                     '</tr>';
        }

        return <<<HTML
<tr>
  <td class="wrap" style="padding:8px 40px 8px;">
   <table role="presentation" cellpadding="0" cellspacing="0" width="100%" class="detail-table" style="border:1px solid rgba(37,99,235,.25); border-radius:12px; overflow:hidden;">
      {$body}
    </table>
  </td>
</tr>
HTML;
    }

    /**
     * CTA: VML (Outlook) + gradient BLUE → BLUE-DARK, kontener z pomarańczową, subtelną obwódką.
     * @param array{label?:string,url?:string,subtext?:string}|array<int,string>|null $cta
     */
    private function renderCta(?array $cta): string
    {
        if ($cta === null) return '';

        $label = isset($cta['label']) ? (string)$cta['label'] : (string)($cta[0] ?? '');
        $url   = isset($cta['url'])   ? (string)$cta['url']   : (string)($cta[1] ?? '');
        $sub   = isset($cta['subtext']) ? (string)$cta['subtext'] : (string)($cta[2] ?? '');

        $hasLabel = trim($label) !== '';
        $safeUrl  = $this->sanitizeUrl($url, ['https','http']);

        $labelTx = $this->escape($label);
        $subTx   = $sub !== '' ? '<p style="margin:10px 0 0; font-size:13px; color:#475569;">'.nl2br($this->escape($sub)).'</p>' : '';

        $c1 = $this->theme['colors']['primary'];
        $c2 = $this->theme['colors']['primaryD'];

        if ($hasLabel && $safeUrl !== null) {
            $href = htmlspecialchars($safeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return <<<HTML
<div style="margin:8px 0 4px; text-align:center; padding:20px; border-radius:14px; background:#f2f6ff; border:1px solid rgba(249,115,22,.25);">
  <!--[if mso]>
  <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="{$href}" style="height:48px;v-text-anchor:middle;width:280px;" arcsize="50%" strokecolor="{$c1}" fillcolor="{$c1}">
    <w:anchorlock/>
    <center style="color:#ffffff; font-family:Segoe UI, Helvetica, Arial, sans-serif; font-size:15px; font-weight:700;">{$labelTx}</center>
  </v:roundrect>
  <![endif]-->
  <!--[if !mso]><!-- -->
  <a href="{$href}" target="_blank" rel="noopener noreferrer"
     style="display:inline-block; padding:14px 26px; background:linear-gradient(180deg,{$c1},{$c2}); color:#fff; font-weight:800; font-size:15px; border-radius:999px; text-decoration:none; box-shadow:0 10px 24px rgba(37,99,235,.28);">
    {$labelTx}
  </a>
  <!--<![endif]-->
  {$subTx}
</div>
HTML;
        }

        if ($hasLabel || $sub !== '') {
            return '<div style="margin:8px 0 4px; padding:16px; border-radius:12px; background:#f8fafc; border:1px solid rgba(249,115,22,.25);">'
                . ($hasLabel ? '<p style="margin:0; font-size:15px; font-weight:700;">'.$labelTx.'</p>' : '')
                . ($sub !== '' ? '<p style="margin:8px 0 0; font-size:13px; color:#475569;">'.nl2br($this->escape($sub)).'</p>' : '')
                . '</div>';
        }

        return '';
    }

    /** Stopka kontaktowa — prosto, równo, czytelnie. */
    private function renderFooterContacts(array $contact): string
    {
        $c = $this->theme['colors'];

        $email   = isset($contact['email'])   ? trim((string)$contact['email'])   : '';
        $phone   = isset($contact['phone'])   ? trim((string)$contact['phone'])   : '';
        $address = isset($contact['address']) ? trim((string)$contact['address']) : '';
        $website = isset($contact['website']) ? trim((string)$contact['website']) : '';

        $emailHtml = $email !== ''
            ? '<a href="mailto:'.htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" style="color:#93c5fd; text-decoration:none;">'.$this->escape($email).'</a>'
            : '—';

        if ($phone !== '') {
            $link = preg_replace('/[^+\d]/', '', $phone) ?: $phone;
            $phoneHtml = '<a href="tel:'.htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" style="color:#bfdbfe; text-decoration:none;">'.$this->escape($phone).'</a>';
        } else {
            $phoneHtml = '—';
        }

        $websiteSafe = $this->sanitizeUrl($website, ['https','http']);
        $websiteHtml = $websiteSafe !== null
            ? '<a href="'.htmlspecialchars($websiteSafe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" target="_blank" rel="noopener noreferrer" style="color:#c7d2fe; text-decoration:underline;">'.$this->escape($website).'</a>'
            : '—';

        $addressHtml = $address !== '' ? $this->escape($address) : '—';

        return '<div style="text-align:center;">'
            . '<div style="margin:0 0 6px; font-weight:700; letter-spacing:.3px; text-transform:uppercase;">'.$this->escape($this->theme['brand']).'</div>'
            . '<div style="margin:0 0 4px;">'.$addressHtml.'</div>'
            . '<div style="margin:0 0 4px;">'.$phoneHtml.' · '.$emailHtml.' · '.$websiteHtml.'</div>'
            . '</div>';
    }

    /* ---------- Utils ---------- */

    /** Bezpieczny escape dla HTML. */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanity-checker linków (href/src). Zwraca URL lub null, jeśli schemat jest niedozwolony.
     * @param list<string> $allowedSchemes
     */
    private function sanitizeUrl(?string $url, array $allowedSchemes = ['https','http']): ?string
    {
        $url = trim((string)$url);
        if ($url === '') return null;

        if (str_starts_with($url, 'cid:')) return $url;              // cid: dla obrazków osadzonych
        if (str_starts_with($url, 'data:image/')) return $url;       // data:image/* (logo/hero)

        $parts = @parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'])) return null;

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, $allowedSchemes, true)) return null;

        return $url;
    }

    /**
     * Normalizacja tablicy detali do listy ['label'=>..., 'value'=>...].
     * @param array<int|string,mixed> $rows
     * @return list<array{label:string,value:string}>
     */
    private function normalizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $k => $v) {
            $label = '';
            $value = '';

            if (is_array($v) && isset($v['label'], $v['value'])) {
                $label = trim((string)$v['label']);
                $value = trim((string)$v['value']);
            } elseif (is_array($v)) {
                $first = array_key_first($v);
                if ($first !== null) {
                    $label = trim((string)$first);
                    $value = trim((string)$v[$first]);
                }
            } else {
                $label = trim(is_string($k) ? $k : (string)$k);
                $value = trim((string)$v);
            }

            if ($label !== '' && $value !== '') {
                $out[] = ['label' => $label, 'value' => $value];
            }
        }
        return $out;
    }

    /** Autolink URL/e-mail (bez JS, tylko http/https) — kolor z palety primary. */
    private function autoLink(string $text): string
    {
        $primary = $this->theme['colors']['primary'];
        $safe = $this->escape($text);

        // URL
        $safe = preg_replace(
            '~(?<!href=")(https?://[^\s<]+)~i',
            '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:'.$primary.'; text-decoration:underline;">$1</a>',
            $safe
        ) ?? $safe;

        // e-mail
        $safe = preg_replace(
            '/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i',
            '<a href="mailto:$1" style="color:'.$primary.'; text-decoration:underline;">$1</a>',
            $safe
        ) ?? $safe;

        return $safe;
    }

    /** Bezpieczny preg_quote dla kolorów/granic (używane przy podmianie granic zebra). */
    private function pregQuote(string $s): string
    {
        return preg_quote($s, '~');
    }

    /**
     * Odczytuje szerokość/wysokość logo z kontaktu (obsługa `logo_width`, `logoWidth`, ...).
     * @param list<string> $keys
     */
    private function resolveLogoDimension(array $contact, array $keys, ?int $default): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $contact)) {
                continue;
            }

            $value = $contact[$key];

            if (is_string($value)) {
                $value = trim($value);

                if ($value === '') {
                    return null;
                }

                if (preg_match('/^(\d+)$/', $value, $m) === 1) {
                    $value = (int) $m[1];
                } elseif (preg_match('/^(\d+)\s*px$/i', $value, $m) === 1) {
                    $value = (int) $m[1];
                } else {
                    return $default;
                }
            }

            if (is_float($value)) {
                $value = (int) round($value);
            }

            if (is_int($value)) {
                if ($value <= 0) {
                    return null;
                }

                return min($value, 2000);
            }

            return $default;
        }

        return $default;
    }

    /**
     * Sprawdza, czy w danych kontaktowych pojawił się któryś z podanych kluczy.
     * @param list<string> $keys
     */
    private function hasContactKey(array $contact, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $contact)) {
                return true;
            }
        }

        return false;
    }
}
