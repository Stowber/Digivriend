<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\DatabaseResetService;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

if (!Auth::authorize(['admin'])) {
    http_response_code(403);
    echo '<h1>403</h1><p>Brak uprawnień do wykonania tej operacji.</p>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $messages = [
        'success' => [],
        'error' => [],
    ];

    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $messages['error'][] = 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        $confirmation = strtoupper(trim((string) ($_POST['confirmation'] ?? '')));

        if ($confirmation !== 'DUMPDATABASE') {
            $messages['error'][] = 'Aby potwierdzić, wpisz dokładnie "DUMPDATABASE".';
        } else {
            try {
                $resetService = new DatabaseResetService($pdo);
                $resetService->reset();
                $messages['success'][] = 'Baza danych została wyczyszczona. Możesz zalogować się używając danych administratora z pliku .env.';
            } catch (\Throwable $exception) {
                $messages['error'][] = 'Nie udało się wyczyścić bazy danych: ' . $exception->getMessage();
            }
        }
    }

    $_SESSION['dump_database_messages'] = $messages;
    Response::redirect('dumpdatabase.php');
}

$messages = [
    'success' => [],
    'error' => [],
];

if (isset($_SESSION['dump_database_messages'])) {
    $storedMessages = $_SESSION['dump_database_messages'];
    if (is_array($storedMessages)) {
        $messages = array_merge($messages, array_intersect_key($storedMessages, $messages));
    }
    unset($_SESSION['dump_database_messages']);
}

$csrfToken = Csrf::token();
$driverName = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>DUMPDATABASE - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <style>
    body.page--dumpdatabase {
      min-height: 100vh;
      margin: 0;
      display: flex;
      flex-direction: column;
      background: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%);
      font-family: 'Inter', 'Segoe UI', sans-serif;
      color: #0f172a;
    }

    .page--dumpdatabase main {
      flex: 1;
      padding: 2.5rem 0;
    }

    .danger-card {
      background: #fff5f5;
      border: 1px solid rgba(185, 28, 28, 0.35);
      border-radius: var(--radius-lg);
      padding: 2rem;
      box-shadow: var(--shadow-soft);
    }

    .danger-card h2 {
      margin-top: 0;
      color: #991b1b;
    }

    .alert {
      padding: 1rem 1.2rem;
      border-radius: var(--radius-md);
      margin-bottom: 1rem;
      font-weight: 600;
    }

    .alert--success {
      background: #ecfdf5;
      border: 1px solid rgba(16, 185, 129, 0.35);
      color: #047857;
    }

    .alert--error {
      background: #fef2f2;
      border: 1px solid rgba(248, 113, 113, 0.35);
      color: #b91c1c;
    }

    .confirm-input {
      margin-top: 1.2rem;
    }

    .confirm-input input {
      max-width: 320px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      text-align: center;
    }

    .confirm-input button {
      margin-top: 1.2rem;
      background: linear-gradient(90deg, #dc2626, #b91c1c);
      border: none;
      color: #fff;
      padding: 0.85rem 1.8rem;
      font-size: 1rem;
      border-radius: var(--radius-md);
      cursor: pointer;
      font-weight: 700;
      box-shadow: 0 15px 35px -20px rgba(220, 38, 38, 0.75);
    }

    .confirm-input button:hover,
    .confirm-input button:focus {
      transform: translateY(-1px);
      box-shadow: 0 18px 40px -18px rgba(220, 38, 38, 0.8);
    }

    .danger-list {
      margin: 1.5rem 0;
      padding-left: 1.2rem;
      color: #b91c1c;
      font-weight: 600;
    }

    .danger-list li {
      margin-bottom: 0.6rem;
    }

    footer.page-footer {
      background: #0f172a;
      color: rgba(255, 255, 255, 0.72);
      padding: 1.5rem 0;
      text-align: center;
      font-size: 0.9rem;
    }
  </style>
</head>
<body class="page--dumpdatabase">
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo">Digivriend</a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <?php render_main_nav('dump_database'); ?>
      </nav>
    </div>
  </header>
  <main>
    <div class="container" style="max-width: 720px;">
      <h1>DUMPDATABASE</h1>
      <p>To narzędzie tymczasowo usuwa wszystkie dane z bazy i ponownie inicjalizuje strukturę. Używaj go tylko w bezpiecznych warunkach testowych.</p>

      <?php foreach ($messages['success'] as $message): ?>
        <div class="alert alert--success"><?= htmlspecialchars((string) $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endforeach; ?>

      <?php foreach ($messages['error'] as $message): ?>
        <div class="alert alert--error"><?= htmlspecialchars((string) $message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endforeach; ?>

      <section class="danger-card">
        <h2>Strefa wysokiego ryzyka</h2>
        <p>Po potwierdzeniu baza danych zostanie <strong>wyczyszczona całkowicie</strong>, łącznie z kontami użytkowników i historią. Domyślne konto administratora zostanie utworzone ponownie zgodnie z konfiguracją z pliku <code>.env</code>.</p>
        <ul class="danger-list">
          <li>Operacja jest nieodwracalna.</li>
          <li>Wszystkie rekordy zostaną usunięte.</li>
          <li>Zostanie zresetowany stan magazynu, kalendarza i spraw.</li>
        </ul>
        <form method="post" class="confirm-input">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <label for="confirmation">Wpisz <strong>DUMPDATABASE</strong>, aby potwierdzić:</label>
          <input type="text" id="confirmation" name="confirmation" required>
          <p>Aktywny sterownik bazy danych: <strong><?= htmlspecialchars($driverName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
          <button type="submit">Wyczyść bazę danych</button>
        </form>
      </section>
    </div>
  </main>
  <footer class="page-footer">
    Tymczasowe narzędzie administracyjne DUMPDATABASE
  </footer>
</body>
</html>