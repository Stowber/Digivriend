<?php
$companyLogoDataUri = $companyLogoDataUri ?? '';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title><?= $documentTitel ?> - <?= $bedrijfsNaam ?></title>
  <style>
    :root {
      --accent: #F05A28;
      --accent-soft: #fde5db;
      --text-main: #1f2533;
      --text-muted: #4c5464;
      --border-color: #e3e7ee;
      --card-bg: #f8fafc;
    }

    * {
      box-sizing: border-box;
    }

    @page {
      margin: 26px;
    }

    body {
      font-family: 'Helvetica Neue', Arial, sans-serif;
      background-color: #eef2f7;
      color: var(--text-main);
      margin: 0;
      font-size: 12.5px;
      line-height: 1.6;
    }

    .document {
      background-color: #fff;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 10px 32px rgba(31, 37, 51, 0.12);
    }

    .header {
      background: linear-gradient(135deg, #f05a28, #dd4d20);
      color: #fff;
      padding: 24px 30px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 32px;
    }

    .brand {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }

    .brand-logo {
      display: flex;
      align-items: center;
      gap: 14px;
      font-weight: 700;
      letter-spacing: 0.06em;
      font-size: 16px;
      text-transform: uppercase;
    }

    .logo-mark {
      width: 40px;
      height: 40px;
      border-radius: 12px;
      background: rgba(255, 255, 255, 0.12);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
    }

    .brand-contact {
      font-size: 11.5px;
      line-height: 1.55;
      color: rgba(255, 255, 255, 0.85);
      margin: 0;
    }

    .meta {
      min-width: 220px;
      text-align: right;
    }

    .meta-title {
      margin: 0 0 8px;
      font-size: 10.5px;
      letter-spacing: 0.18em;
      text-transform: uppercase;
      color: rgba(255, 255, 255, 0.65);
    }

    .meta-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 11.5px;
    }

    .meta-table td {
      padding: 4px 0;
      color: rgba(255, 255, 255, 0.92);
    }

    .meta-table td:first-child {
      padding-right: 14px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: rgba(255, 255, 255, 0.7);
    }

    .main {
      padding: 28px 30px 22px;
    }

    .eyebrow {
      display: inline-block;
      padding: 5px 12px;
      background-color: var(--accent-soft);
      color: var(--accent);
      border-radius: 999px;
      font-size: 10.5px;
      font-weight: 600;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      margin-bottom: 12px;
    }

    .main-title {
      margin: 0;
      font-size: 22px;
      letter-spacing: -0.01em;
      color: var(--text-main);
    }

    .lead {
      margin: 14px 0 22px;
      color: var(--text-muted);
      font-size: 13px;
    }

    .body-text p {
      margin: 0 0 14px;
    }

    .schedule-card {
      border: 1px solid var(--border-color);
      border-radius: 14px;
      padding: 18px 22px;
      background: linear-gradient(180deg, rgba(240, 90, 40, 0.08), rgba(240, 90, 40, 0.02));
      margin: 22px 0;
    }

    .schedule-card h2 {
      margin: 0 0 12px;
      font-size: 15px;
      color: var(--accent);
    }

    .schedule-card ul {
      margin: 0;
      padding-left: 18px;
      color: var(--text-main);
    }

    .schedule-card__note {
      margin-top: 10px;
      font-size: 11.5px;
      color: var(--text-muted);
    }

    .cta-card {
      border-radius: 14px;
      border: 1px solid var(--border-color);
      background: #fff6f1;
      padding: 18px 22px;
      margin: 24px 0 26px;
    }

    .cta-card h2 {
      margin: 0 0 12px;
      font-size: 15px;
      color: var(--accent);
    }

    .cta-card p {
      margin: 0 0 12px;
    }

    .cta-details {
      margin: 0 0 12px;
      padding-left: 16px;
      color: var(--text-main);
    }

    .cta-details li {
      margin-bottom: 3px;
    }

    .closing {
      margin-top: 26px;
      line-height: 1.6;
    }

    .footer {
      padding: 16px 30px;
      border-top: 1px solid var(--border-color);
      background: #f8f9fb;
      font-size: 12px;
      color: #6b758b;
      text-align: center;
    }

    .tearoff {
      position: relative;
      margin: 32px 0 0;
      padding: 18px 28px;
      width: 100%;
      border: 1.5px dashed var(--accent);
      border-radius: 18px;
      background: #fff;
      box-shadow: 0 12px 28px rgba(31, 37, 51, 0.12);
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      row-gap: 14px;
    }

    .tearoff::before {
      content: '✂️';
      position: absolute;
      top: -12px;
      right: 32px;
      background: #fff;
      padding: 0 4px;
      font-size: 13px;
    }

    .tearoff__header {
      display: grid;
      grid-template-columns: auto 1fr;
      gap: 18px;
      align-items: center;
    }

    .tearoff__logo-wrapper {
      width: 52px;
      height: 52px;
      border-radius: 14px;
      background: #fff5ef;
      border: 1px solid rgba(240, 90, 40, 0.25);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      flex-shrink: 0;
    }

    .tearoff__logo {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .tearoff__logo-fallback {
      font-weight: 700;
      font-size: 16px;
      letter-spacing: 0.08em;
      color: var(--accent);
    }

    .tearoff__heading {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .tearoff__title {
      margin: 0;
      font-size: 15px;
      font-weight: 700;
      color: var(--accent);
      letter-spacing: 0.02em;
    }

    .tearoff__subtitle {
      margin: 0;
      font-size: 11px;
      color: var(--text-muted);
      line-height: 1.35;
    }

    .tearoff__body {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 18px;
      align-items: start;
    }

    .tearoff__section {
      display: grid;
      row-gap: 8px;
    }

    .tearoff__section-title {
      margin: 0;
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      color: #6b758b;
    }

    .tearoff__chiplist {
      list-style: none;
      padding: 0;
      margin: 0;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
      gap: 6px;
    }

    .tearoff__chiplist--single {
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    }

    .tearoff__chip {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border: 1px solid rgba(240, 90, 40, 0.35);
      border-radius: 8px;
      font-size: 11px;
      color: var(--text-main);
      background: rgba(240, 90, 40, 0.04);
    }

    .tearoff__checkbox {
     width: 11px;
      height: 11px;
      border: 1.3px solid var(--accent);
      border-radius: 3px;
      background: #fff;
      flex-shrink: 0;
    }

    .tearoff__footer {
      border-top: 1px dashed rgba(240, 90, 40, 0.4);
      padding-top: 10px;
      display: grid;
      grid-template-columns: minmax(0, 2fr) minmax(160px, 1fr);
      column-gap: 18px;
      align-items: center;
    }

    .tearoff__note {
      margin: 0;
      font-size: 10.5px;
      color: var(--text-muted);
      line-height: 1.4;
    }

    .tearoff__signature {
      display: grid;
      row-gap: 6px;
      justify-items: stretch;
    }

    .tearoff__line {
      height: 1px;
      background: rgba(31, 37, 51, 0.16);
    }

    .tearoff__line-label {
      margin: 0;
      font-size: 10px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #8e96a8;
      text-align: right;
    }
  </style>
</head>
<body>
<div class="document">
  <header class="header">
    <div class="brand">
      <div class="brand-logo">
        <span class="logo-mark">DV</span>
        <span>Digivriend</span>
      </div>
      <p class="brand-contact">
        Barchman Wuytierslaan 10 · 3818 LH Amersfoort<br>
        033 783 4251 · hallo@digivriend.nl · www.digivriend.nl
      </p>
    </div>
    <div class="meta">
      <p class="meta-title">Documentinformatie</p>
      <table class="meta-table">
        <tr>
          <td>Document</td>
          <td><?= $documentTitel ?></td>
        </tr>
        <tr>
          <td>Datum</td>
          <td><?= $letterDateHuman ?></td>
        </tr>
      </table>
    </div>
  </header>
  <main class="main">
    <span class="eyebrow"><?= $focusLine ?></span>
    <h1 class="main-title">Gratis netwerksveiligheidscheck in uw buurt</h1>
    <p class="lead"><?= $salutation ?> Wij zijn binnenkort aanwezig <?= $periodSummary ?> in <?= $areaSummary ?> om bewoners kosteloos te helpen bij het verbeteren en beveiligen van het thuisnetwerk.</p>
    <div class="body-text">
      <p>Tijdens de netwerkscan meten we de stabiliteit van uw internetverbinding, wifi-dekking per ruimte en eventuele risico's op ongewenste toegang. U ontvangt direct een overzicht met concrete aanbevelingen voor een sneller en veiliger netwerk.</p>
      <p>We nemen professionele meetapparatuur mee, voeren indien gewenst kleine optimalisaties uit en adviseren over vervolgstappen. Voor uitgebreide werkzaamheden plannen we graag een opvolging in overleg.</p>
    </div>
    <div class="schedule-card">
      <h2>Beschikbare momenten</h2>
      <ul>
        <?php foreach ($timeSlots as $slot): ?>
          <li><?= $slot ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if ($rsvpDeadlineHuman !== ''): ?>
        <p class="schedule-card__note">Aanmelden kan tot <?= $rsvpDeadlineHuman ?>.</p>
      <?php endif; ?>
    </div>
    <div class="cta-card">
      <h2>Plan uw gratis check</h2>
      <p>Neem contact op met <?= $contactName ?><?php if ($contactRole !== ''): ?>, <?= $contactRole ?><?php endif; ?> om een afspraak in te plannen. Wij bevestigen de afspraak schriftelijk en sturen een korte voorbereiding mee.</p>
      <ul class="cta-details">
        <?php if ($contactPhone !== ''): ?>
          <li><strong>Telefoon:</strong> <?= $contactPhone ?></li>
        <?php endif; ?>
        <?php if ($contactEmail !== ''): ?>
          <li><strong>E-mail:</strong> <?= $contactEmail ?></li>
        <?php endif; ?>
        <?php if ($contactUrl !== ''): ?>
          <li><strong>Online:</strong> <?= $contactUrl ?></li>
        <?php endif; ?>
      </ul>
      <p>Na de check ontvangt u binnen 24 uur een digitaal rapport met onze bevindingen en praktische tips op maat.</p>
    </div>
    <?php if ($additionalNoteHtml !== ''): ?>
      <p><?= $additionalNoteHtml ?></p>
    <?php endif; ?>
    <p class="closing">
      Met vriendelijke groet,<br>
      <?= $signatureName ?><br>
      <?= $signatureRole ?><br>
      Digivriend
    </p>
    <div class="tearoff">
      <div class="tearoff__header">
        <div class="tearoff__logo-wrapper">
          <?php if ($companyLogoDataUri !== ''): ?>
            <img src="<?= $companyLogoDataUri ?>" alt="<?= $bedrijfsNaam ?> logo" class="tearoff__logo">
          <?php else: ?>
            <span class="tearoff__logo-fallback">DV</span>
          <?php endif; ?>
        </div>
        <div class="tearoff__heading">
          <p class="tearoff__title">Afspraakkaart voor uw deur</p>
          <p class="tearoff__subtitle">Knip uit, vul uw voorkeur in en laat onze specialist direct zien wanneer het schikt.</p>
        </div>
      </div>
      <div class="tearoff__body">
        <div class="tearoff__section">
          <p class="tearoff__section-title">Dag</p>
          <ul class="tearoff__chiplist">
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>Maandag</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>Dinsdag</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>Woensdag</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>Donderdag</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>Vrijdag</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>Zaterdag</li>
          </ul>
        </div>
        <div class="tearoff__section">
          <p class="tearoff__section-title">Tijdslot</p>
          <ul class="tearoff__chiplist">
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>08:00 - 10:00</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>10:00 - 12:00</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>12:00 - 14:00</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>14:00 - 16:00</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>16:00 - 18:00</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>18:00 - 20:00</li>
          </ul>
        </div>
        <div class="tearoff__section">
          <p class="tearoff__section-title">Voorkeur</p>
          <ul class="tearoff__chiplist tearoff__chiplist--single">
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>Bel graag aan</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>Bel aan, maar wacht even</li>
            <li class="tearoff__chip"><span class="tearoff__checkbox"></span>Ik ben niet thuis</li>
          </ul>
        </div>
      </div>
      <div class="tearoff__footer">
        <p class="tearoff__note">Noteer uw naam of huisnummer en bevestig de kaart bij de deur zodat onze specialist zich direct kan melden.</p>
        <div class="tearoff__signature">
          <div class="tearoff__line"></div>
          <p class="tearoff__line-label">Naam / Huisnummer</p>
        </div>
      </div>
    </div>
  </main>
  <footer class="footer">
    Digivriend · Betrouwbare computerhulp aan huis · KvK 87566543 · BTW NL004558765B12
  </footer>
</div>
</body>
</html>