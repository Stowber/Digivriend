<?php

declare(strict_types=1);

use App\Security\Csrf;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$csrfToken = Csrf::token();

$today = new DateTimeImmutable('today');
$startDate = (new DateTimeImmutable('first day of next month'));
$endDate = $startDate->add(new DateInterval('P2D'));
$rsvpDate = $startDate->modify('-3 days');

$dutchDays = [
    'Mon' => 'maandag',
    'Tue' => 'dinsdag',
    'Wed' => 'woensdag',
    'Thu' => 'donderdag',
    'Fri' => 'vrijdag',
    'Sat' => 'zaterdag',
    'Sun' => 'zondag',
];
$dutchMonths = [
    1 => 'januari',
    2 => 'februari',
    3 => 'maart',
    4 => 'april',
    5 => 'mei',
    6 => 'juni',
    7 => 'juli',
    8 => 'augustus',
    9 => 'september',
    10 => 'oktober',
    11 => 'november',
    12 => 'december',
];

$formatDutchDay = static function (DateTimeImmutable $date) use ($dutchDays, $dutchMonths): string {
    $dayKey = $date->format('D');
    $dayName = $dutchDays[$dayKey] ?? strtolower($date->format('l'));
    $monthNumber = (int) $date->format('n');
    $monthName = $dutchMonths[$monthNumber] ?? strtolower($date->format('F'));

    return sprintf('%s %s %s', ucfirst($dayName), $date->format('j'), $monthName);
};

$defaultTimeSlots = sprintf(
    "%s · Ochtend 09:00 – 12:00\n%s · Middag 12:00 – 15:00\n%s · Avond 17:00 – 20:00",
    $formatDutchDay($startDate),
    $formatDutchDay($startDate->add(new DateInterval('P1D'))),
    $formatDutchDay($startDate->add(new DateInterval('P2D')))
);

$defaultStartDate = $startDate->format('Y-m-d');
$defaultEndDate = $endDate->format('Y-m-d');
$defaultLetterDate = $today->format('Y-m-d');
$defaultRsvpDate = $rsvpDate->format('Y-m-d');
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Netwerkscan brief - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/netwerkcheck-brief.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="Digivriend dashboard">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title">Digivriend</span>
          <span class="logo__subtitle">Serviceplatform</span>
        </span>
      </a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <?php render_main_nav('netwerkcheck_brief'); ?>
      </nav>
    </div>
  </header>

  <section class="hero-band">
    <div class="container">
      <div class="page-header">
        <h1>Gratis netwerkscan brief</h1>
        <p>Personaliseer de buurtbrief en genereer direct een professionele PDF voor de gratis netwerksveiligheidscheck.</p>
      </div>
    </div>
  </section>

  <main>
    <div class="container page-wrapper">
      <form action="generate-netwerkcheck-brief.php" method="POST" class="form-shell" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

        <section class="form-section">
          <h2>Campagnegegevens</h2>
          <div class="form-grid">
            <label for="letter_date">Datum brief</label>
            <input type="date" id="letter_date" name="letter_date" value="<?= $defaultLetterDate ?>" required>

            <label for="start_date">Startdatum aanwezigheid</label>
            <input type="date" id="start_date" name="start_date" value="<?= $defaultStartDate ?>" required>

            <label for="end_date">Einddatum aanwezigheid</label>
            <input type="date" id="end_date" name="end_date" value="<?= $defaultEndDate ?>" required>

            <label for="rsvp_deadline">Aanmelden tot (optioneel)</label>
            <input type="date" id="rsvp_deadline" name="rsvp_deadline" value="<?= $defaultRsvpDate ?>">

            <label for="area">Gebied / straat</label>
            <input type="text" id="area" name="area" value="Barchman Wuytierslaan" required>

            <label for="city">Plaats (optioneel)</label>
            <input type="text" id="city" name="city" value="Amersfoort">

            <label for="focus_line">Kopregel</label>
            <input type="text" id="focus_line" name="focus_line" value="Gratis Netwerksveiligheidscheck in uw buurt – van Digivriend" required>

            <label for="salutation">Aanhef</label>
            <input type="text" id="salutation" name="salutation" value="Beste buurtbewoner," required>
          </div>
        </section>

        <section class="form-section">
          <h2>Planning en toelichting</h2>
          <div class="form-grid">
            <label for="time_slots">Beschikbare tijdsloten (één per regel)</label>
            <textarea id="time_slots" name="time_slots" rows="4" required><?= htmlspecialchars($defaultTimeSlots, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>

            <label for="additional_note">Aanvullende notitie (optioneel)</label>
            <textarea id="additional_note" name="additional_note" rows="3" placeholder="Bijvoorbeeld: bewoners ontvangen na afloop een digitale rapportage."></textarea>
          </div>
        </section>

        <section class="form-section">
          <h2>Contactpersoon</h2>
          <div class="form-grid">
            <label for="contact_name">Naam</label>
            <input type="text" id="contact_name" name="contact_name" value="Team Digivriend" required>

            <label for="contact_role">Functietitel (optioneel)</label>
            <input type="text" id="contact_role" name="contact_role" value="Coördinator Netwerksveiligheid">

            <label for="contact_phone">Telefoon (optioneel)</label>
            <input type="text" id="contact_phone" name="contact_phone" value="033 783 4251">

            <label for="contact_email">E-mailadres (optioneel)</label>
            <input type="email" id="contact_email" name="contact_email" value="hallo@digivriend.nl">

            <label for="contact_url">Online afspraaklink (optioneel)</label>
            <input type="text" id="contact_url" name="contact_url" value="https://digivriend.nl/netwerkscan">
          </div>
        </section>

        <section class="form-section">
          <h2>Ondertekening</h2>
          <div class="form-grid">
            <label for="signature_name">Naam ondertekening</label>
            <input type="text" id="signature_name" name="signature_name" value="Bartjan van Digivriend" required>

            <label for="signature_role">Functie ondertekening</label>
            <input type="text" id="signature_role" name="signature_role" value="Netwerkspecialist Digivriend" required>
          </div>
        </section>

        <div class="form-actions">
          <button type="submit" class="btn">Genereer PDF</button>
        </div>
      </form>
    </div>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
</body>
</html>