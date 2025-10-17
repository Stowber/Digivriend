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
      margin-top: 32px;
      border-top: 2px dashed var(--border-color);
      padding: 26px 26px 22px;
      background: linear-gradient(180deg, #ffffff 0%, rgba(240, 90, 40, 0.05) 100%);
      border-radius: 0 0 16px 16px;
    }

    .tearoff__header {
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 18px;
    }

    .tearoff__icon {
      width: 44px;
      height: 44px;
      border-radius: 14px;
      background: var(--accent);
      color: #fff;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      letter-spacing: 0.08em;
    }

    .tearoff__title {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      color: var(--text-main);
    }

    .tearoff__subtitle {
      margin: 4px 0 0;
      font-size: 12px;
      color: var(--text-muted);
    }

    .tearoff__grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      gap: 18px;
    }

    .tearoff__column {
      background: #fff;
      border-radius: 14px;
      border: 1px solid var(--border-color);
      box-shadow: 0 8px 20px rgba(240, 90, 40, 0.12);
      padding: 16px 18px;
    }

    .tearoff__column h3 {
      margin: 0 0 10px;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      color: var(--accent);
    }

    .tearoff__list {
      list-style: none;
      padding: 0;
      margin: 0;
      display: grid;
      gap: 8px;
    }

    .tearoff__list li {
      display: flex;
      align-items: center;
      gap: 10px;
      padding-bottom: 8px;
      border-bottom: 1px dashed rgba(240, 90, 40, 0.25);
      font-size: 12.5px;
      font-weight: 500;
      color: var(--text-main);
    }

    .tearoff__list li:last-child {
      border-bottom: none;
      padding-bottom: 0;
    }

    .tearoff__checkbox {
      width: 16px;
      height: 16px;
      border: 1.6px solid var(--accent);
      border-radius: 4px;
      background: #fff;
      box-shadow: inset 0 0 0 2px #fff;
    }

    .tearoff__note {
      margin: 18px 0 0;
      font-size: 11.5px;
      color: var(--text-muted);
      text-align: center;
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
        <span class="tearoff__icon">DV</span>
        <div>
          <p class="tearoff__title">Afspraakherinnering voor op de deur</p>
          <p class="tearoff__subtitle">Knip deze strook uit, vink het gewenste moment aan en hang hem zichtbaar aan uw deur.</p>
        </div>
      </div>
      <div class="tearoff__grid">
        <div class="tearoff__column">
          <h3>Dag</h3>
          <ul class="tearoff__list">
            <li><span class="tearoff__checkbox"></span>Maandag</li>
            <li><span class="tearoff__checkbox"></span>Dinsdag</li>
            <li><span class="tearoff__checkbox"></span>Woensdag</li>
            <li><span class="tearoff__checkbox"></span>Donderdag</li>
            <li><span class="tearoff__checkbox"></span>Vrijdag</li>
            <li><span class="tearoff__checkbox"></span>Zaterdag</li>
          </ul>
        </div>
        <div class="tearoff__column">
          <h3>Tijd</h3>
          <ul class="tearoff__list">
            <li><span class="tearoff__checkbox"></span>08:00 - 10:00</li>
            <li><span class="tearoff__checkbox"></span>10:00 - 12:00</li>
            <li><span class="tearoff__checkbox"></span>12:00 - 14:00</li>
            <li><span class="tearoff__checkbox"></span>14:00 - 16:00</li>
            <li><span class="tearoff__checkbox"></span>16:00 - 18:00</li>
            <li><span class="tearoff__checkbox"></span>18:00 - 20:00</li>
          </ul>
        </div>
        <div class="tearoff__column">
          <h3>Opmerkingen</h3>
          <ul class="tearoff__list">
            <li><span class="tearoff__checkbox"></span>Bel graag aan</li>
            <li><span class="tearoff__checkbox"></span>Bel aan, maar wacht even</li>
            <li><span class="tearoff__checkbox"></span>Ik ben niet thuis</li>
          </ul>
        </div>
      </div>
      <p class="tearoff__note">Vul uw voorkeuren duidelijk in en bevestig de kaart bij de deurklink of op ooghoogte voor onze specialist.</p>
    </div>
  </main>
  <footer class="footer">
    Digivriend · Betrouwbare computerhulp aan huis · KvK 87566543 · BTW NL004558765B12
  </footer>
</div>
</body>
</html>