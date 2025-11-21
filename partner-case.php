<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Lang\Translator;
use App\Support\Repositories\CaseRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

if (Auth::role() !== 'partner') {
    Response::error('Toegang geweigerd.', 403);
}

$caseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($caseId === null || $caseId === false) {
    Response::error('Ongeldig of ontbrekend case-ID.', 400);
}

$caseRepository = new CaseRepository($pdo);
$case = $caseRepository->findById((int) $caseId);

if ($case === null) {
    Response::error('Case niet gevonden.', 404);
}

$details = [];
if (!empty($case['details'])) {
    $decoded = json_decode((string) $case['details'], true);
    if (is_array($decoded)) {
        $details = $decoded;
    }
}

$partnerId = isset($details['partner_id']) ? (int) $details['partner_id'] : null;
if ($partnerId !== Auth::id()) {
    Response::error('Deze case is niet gekoppeld aan jouw partneraccount.', 403);
}

$workflowDefaults = [
    'status' => 'awaiting_acceptance',
    'accepted_at' => null,
    'estimate' => [
        'amount' => '',
        'notes' => '',
        'submitted_at' => null,
        'decision' => 'pending',
        'decision_at' => null,
        'feedback' => '',
        'counter_amount' => '',
    ],
    'repair_started_at' => null,
    'completed_at' => null,
    'archived_at' => null,
    'archive_reason' => null,
];

$workflow = $details['partner_workflow'] ?? [];
if (!is_array($workflow)) {
    $workflow = [];
}
$workflow = array_replace_recursive($workflowDefaults, $workflow);

$errors = [];
$successMessage = '';

$saveWorkflow = static function (array $newWorkflow) use (&$details, $caseRepository, $caseId, &$workflow): void {
    $details['partner_workflow'] = $newWorkflow;
    $caseRepository->updateDetails((int) $caseId, $details);
    $workflow = $newWorkflow;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Ongeldige sessie.');
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'accept-case') {
            $workflow['status'] = 'diagnosis';
            $workflow['accepted_at'] = date('Y-m-d H:i:s');
            $saveWorkflow($workflow);
            $successMessage = 'Case została przyjęta do diagnozy.';
        } elseif ($action === 'submit-estimate') {
            if ($workflow['status'] === 'awaiting_acceptance') {
                throw new RuntimeException('Najpierw zaakceptuj zlecenie.');
            }

            $amount = InputValidator::requireString($_POST, 'amount', 120);
            $notes = InputValidator::optionalString($_POST, 'notes', 1000);

            $workflow['status'] = 'estimate_submitted';
            $workflow['estimate']['amount'] = $amount;
            $workflow['estimate']['notes'] = $notes;
            $workflow['estimate']['submitted_at'] = date('Y-m-d H:i:s');
            $workflow['estimate']['decision'] = 'pending';
            $workflow['estimate']['decision_at'] = null;
            $workflow['estimate']['feedback'] = '';
            $workflow['estimate']['counter_amount'] = '';

            $saveWorkflow($workflow);
            $successMessage = 'Wycena została wysłana do pracowników.';
        } elseif ($action === 'log-decision') {
            $decision = strtolower(InputValidator::requireString($_POST, 'decision', 32));
            if (!in_array($decision, ['accepted', 'declined', 'counter'], true)) {
                throw new RuntimeException('Nieobsługiwany status decyzji.');
            }

            $feedback = InputValidator::optionalString($_POST, 'feedback', 1000);
            $counterAmount = InputValidator::optionalString($_POST, 'counter_amount', 120);

            $workflow['estimate']['decision'] = $decision;
            $workflow['estimate']['decision_at'] = date('Y-m-d H:i:s');
            $workflow['estimate']['feedback'] = $feedback;
            $workflow['estimate']['counter_amount'] = $decision === 'counter' ? $counterAmount : '';

            if ($decision === 'accepted') {
                $workflow['status'] = 'repair_ready';
            } elseif ($decision === 'declined') {
                $workflow['status'] = 'archived';
                $workflow['archived_at'] = date('Y-m-d H:i:s');
                $workflow['archive_reason'] = $feedback !== '' ? $feedback : 'Wycena została odrzucona przez pracowników.';
            } else {
                $workflow['status'] = 'counter_review';
            }

            $saveWorkflow($workflow);
            $successMessage = 'Zaktualizowano decyzję pracowników.';
        } elseif ($action === 'start-repair') {
            if (!in_array($workflow['estimate']['decision'], ['accepted', 'counter'], true)) {
                throw new RuntimeException('Możesz rozpocząć naprawę dopiero po decyzji pracowników.');
            }

            $workflow['status'] = 'repair_in_progress';
            $workflow['repair_started_at'] = date('Y-m-d H:i:s');
            $saveWorkflow($workflow);
            $successMessage = 'Naprawa została oznaczona jako rozpoczęta.';
        } elseif ($action === 'complete-case') {
            $workflow['status'] = 'archived';
            $workflow['completed_at'] = date('Y-m-d H:i:s');
            $workflow['archived_at'] = $workflow['completed_at'];
            $workflow['archive_reason'] = 'Naprawa ukończona i sprzęt gotowy do zwrotu.';
            $saveWorkflow($workflow);
            $successMessage = 'Zakończono zlecenie po stronie partnera.';
        } elseif ($action === 'decline-case') {
            $reason = InputValidator::requireString($_POST, 'reason', 500);
            $workflow['status'] = 'archived';
            $workflow['archived_at'] = date('Y-m-d H:i:s');
            $workflow['archive_reason'] = $reason;
            $saveWorkflow($workflow);
            $successMessage = 'Zlecenie zostało odrzucone i przeniesione do archiwum.';
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

$problemDescription = '';
if (isset($details['problem_description']) && is_string($details['problem_description'])) {
    $problemDescription = trim($details['problem_description']);
}

$deviceLabel = 'Onbekend apparaat';
if (!empty($details['device_brand']) || !empty($details['device_model'])) {
    $deviceLabel = trim(($details['device_brand'] ?? '') . ' ' . ($details['device_model'] ?? ''));
}

$statusLabels = [
    'awaiting_acceptance' => 'Oczekuje na akceptację partnera',
    'diagnosis' => 'W trakcie diagnozy',
    'estimate_submitted' => 'Wycena wysłana do pracowników',
    'counter_review' => 'Oczekiwanie na zmianę ceny',
    'repair_ready' => 'Wycena zaakceptowana',
    'repair_in_progress' => 'Naprawa w toku',
    'archived' => 'Zarchiwizowano',
];

$currentStatusLabel = $statusLabels[$workflow['status']] ?? 'Status nieznany';

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title>Case #<?= htmlspecialchars((string) ($case['reference_code'] ?? $caseId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Partner</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/partner-case-detail.css">
</head>
<body<?= platform_body_attributes(); ?>>
<header class="main-header">
  <div class="container">
    <a href="index.php" class="logo" aria-label="<?= htmlspecialchars(__('dashboard.header.logo_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <span class="logo__mark" aria-hidden="true">DV</span>
      <span class="logo__text">
        <span class="logo__title"><?= htmlspecialchars(__('app.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <span class="logo__subtitle"><?= htmlspecialchars(platform_subtitle(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </span>
    </a>
    <nav class="main-nav" aria-label="<?= htmlspecialchars(__('nav.aria.main'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <?php render_main_nav('partner_cases'); ?>
    </nav>
  </div>
</header>
<main class="container partner-case">
  <div class="partner-case__header">
    <div>
      <p class="eyebrow">Case #<?= htmlspecialchars((string) ($case['reference_code'] ?? $caseId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <h1><?= htmlspecialchars((string) ($case['summary'] ?? 'Zgłoszenie partnera'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <p class="muted">Urządzenie: <?= htmlspecialchars($deviceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    </div>
    <div class="status-card">
      <p class="status-card__label">Status</p>
      <p class="status-card__value"><?= htmlspecialchars($currentStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <?php if ($workflow['archived_at']): ?>
        <p class="status-card__meta">Zarchiwizowano: <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $workflow['archived_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($successMessage !== ''): ?>
    <div class="alert alert--success"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($errors !== []): ?>
    <div class="alert alert--danger">
      <ul>
        <?php foreach ($errors as $error): ?>
          <li><?= htmlspecialchars((string) $error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <section class="card">
    <div class="card__header">
      <div>
        <p class="eyebrow">Opis problemu</p>
        <h2>Co trzeba naprawić?</h2>
      </div>
    </div>
    <div class="card__body">
      <p><?= nl2br(htmlspecialchars($problemDescription !== '' ? $problemDescription : 'Brak szczegółowego opisu.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
    </div>
  </section>

  <section class="card">
    <div class="card__header">
      <div>
        <p class="eyebrow">Przebieg współpracy</p>
        <h2>Panel partnera</h2>
      </div>
    </div>
    <div class="workflow-grid">
      <div class="workflow-step">
        <div class="workflow-step__header">
          <p class="eyebrow">Krok 1</p>
          <h3>Przyjęcie zlecenia</h3>
        </div>
        <p>Partner widzi problem i może przyjąć go do diagnozy.</p>
        <form method="post" class="workflow-step__form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="accept-case">
          <button type="submit" class="btn" <?= $workflow['status'] !== 'awaiting_acceptance' ? 'disabled' : '' ?>>Przyjmij do diagnozy</button>
          <?php if ($workflow['accepted_at']): ?>
            <p class="workflow-step__meta">Przyjęto: <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $workflow['accepted_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </form>
      </div>

      <div class="workflow-step">
        <div class="workflow-step__header">
          <p class="eyebrow">Krok 2</p>
          <h3>Wycena naprawy</h3>
        </div>
        <p>Po akceptacji partner przygotowuje kosztorys i wysyła go do pracowników.</p>
        <form method="post" class="workflow-step__form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="submit-estimate">
          <label>Kwota<br><input type="text" name="amount" value="<?= htmlspecialchars((string) $workflow['estimate']['amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $workflow['status'] === 'awaiting_acceptance' ? 'disabled' : '' ?>></label>
          <label>Opis<br><textarea name="notes" rows="3" <?= $workflow['status'] === 'awaiting_acceptance' ? 'disabled' : '' ?>><?= htmlspecialchars((string) $workflow['estimate']['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea></label>
          <button type="submit" class="btn" <?= $workflow['status'] === 'awaiting_acceptance' ? 'disabled' : '' ?>>Wyślij wycenę</button>
          <?php if ($workflow['estimate']['submitted_at']): ?>
            <p class="workflow-step__meta">Wysłano: <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $workflow['estimate']['submitted_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </form>
        <div class="workflow-step__status">
          <p class="eyebrow">Decyzja pracowników</p>
          <p><strong><?= htmlspecialchars(strtoupper($workflow['estimate']['decision']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
          <?php if ($workflow['estimate']['feedback'] !== ''): ?>
            <p><?= nl2br(htmlspecialchars((string) $workflow['estimate']['feedback'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
          <?php endif; ?>
          <?php if ($workflow['estimate']['counter_amount'] !== ''): ?>
            <p>Proponowana kwota: <?= htmlspecialchars((string) $workflow['estimate']['counter_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
          <form method="post" class="workflow-step__form workflow-step__decision">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="log-decision">
            <label>Status decyzji
              <select name="decision">
                <option value="accepted">Zaakceptowano</option>
                <option value="declined" <?= $workflow['estimate']['decision'] === 'declined' ? 'selected' : '' ?>>Odrzucono</option>
                <option value="counter" <?= $workflow['estimate']['decision'] === 'counter' ? 'selected' : '' ?>>Propozycja zmiany ceny</option>
              </select>
            </label>
            <label>Informacja zwrotna
              <textarea name="feedback" rows="2"><?= htmlspecialchars((string) $workflow['estimate']['feedback'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </label>
            <label>Nowa kwota (jeśli dotyczy)
              <input type="text" name="counter_amount" value="<?= htmlspecialchars((string) $workflow['estimate']['counter_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </label>
            <button type="submit" class="btn btn--ghost">Zapisz decyzję</button>
          </form>
        </div>
      </div>

      <div class="workflow-step">
        <div class="workflow-step__header">
          <p class="eyebrow">Krok 3</p>
          <h3>Naprawa i zakończenie</h3>
        </div>
        <p>Po akceptacji partner przechodzi do naprawy i oznacza zakończenie.</p>
        <div class="workflow-step__actions">
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="start-repair">
            <button type="submit" class="btn btn--ghost" <?= $workflow['status'] === 'awaiting_acceptance' ? 'disabled' : '' ?>>Rozpocznij naprawę</button>
          </form>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="complete-case">
            <button type="submit" class="btn" <?= $workflow['status'] === 'archived' ? 'disabled' : '' ?>>Zakończ zlecenie</button>
          </form>
        </div>
        <?php if ($workflow['completed_at']): ?>
          <p class="workflow-step__meta">Zakończono: <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $workflow['completed_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($workflow['archive_reason']): ?>
          <p class="muted">Powód archiwizacji: <?= htmlspecialchars((string) $workflow['archive_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
      </div>

      <div class="workflow-step">
        <div class="workflow-step__header">
          <p class="eyebrow">Odrzucenie</p>
          <h3>Nie przyjmuj zlecenia</h3>
        </div>
        <p>Partner może podać powód odrzucenia. Zlecenie trafi do archiwum.</p>
        <form method="post" class="workflow-step__form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(Csrf::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="decline-case">
          <label>Powód odrzucenia
            <textarea name="reason" rows="3"></textarea>
          </label>
          <button type="submit" class="btn btn--danger">Odrzuć zlecenie</button>
        </form>
      </div>
    </div>
  </section>
</main>
</body>
</html>