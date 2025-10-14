<?php

declare(strict_types=1);

$message = $databaseErrorMessage ?? 'Er is een fout opgetreden bij het verbinden met de database.';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Databasefout - Digivriend</title>
    <link rel="stylesheet" href="/css/theme.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fb;
            font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, "Helvetica Neue", Arial, sans-serif;
            color: #1f2933;
            margin: 0;
        }
        .error-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.1);
            padding: 2.5rem 3rem;
            max-width: 540px;
            text-align: center;
        }
        .error-card h1 {
            margin-top: 0;
            font-size: 1.8rem;
            color: #1f7aec;
        }
        .error-card p {
            line-height: 1.6;
            margin-bottom: 1.5rem;
        }
        .error-card__meta {
            font-size: 0.9rem;
            color: #52606d;
        }
        .error-card__actions {
            margin-top: 2rem;
        }
        .error-card__actions a {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            border-radius: 999px;
            background: #1f7aec;
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
<main class="error-card" role="alert" aria-live="assertive">
    <h1>Database niet beschikbaar</h1>
    <p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <p class="error-card__meta">
        Controleer of de database server actief is en de configuratie in het <code>.env</code>-bestand correct is.
    </p>
    <div class="error-card__actions">
        <a href="javascript:location.reload()">Opnieuw proberen</a>
    </div>
</main>
</body>
</html>