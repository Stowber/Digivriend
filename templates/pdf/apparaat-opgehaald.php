<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title><?= $documentTitel ?> - <?= $bedrijfsNaam ?></title>
    <style>
        :root {
            --accent: #F05A28;
            --accent-dark: #c74a1f;
            --text-main: #1f2533;
            --text-muted: #4c5464;
            --border-color: #e3e7ee;
            --card-bg: #f7f9fc;
        }
        * {
            box-sizing: border-box;
        }
        @page {
            margin: 35px;
        }
        body {
            font-family: 'Helvetica Neue', Arial, sans-serif;
            font-size: 13.5px;
            margin: 0;
            padding: 0;
             line-height: 1.6;
            color: var(--text-main);
            background-color: #f3f5f9;
        }
        .document {
            background-color: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(31, 37, 51, 0.08);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, var(--accent), var(--accent-dark));
            color: #fff;
            padding: 28px 32px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
        }
        .header__identity h1 {
            margin: 0;
            font-size: 24px;
            letter-spacing: 0.04em;
        }
        .header__identity p {
            margin: 8px 0 0;
            font-size: 12.5px;
            color: rgba(255, 255, 255, 0.85);
        }
        .header__meta {
            min-width: 220px;
            text-align: right;
        }
        .header__meta-title {
            margin: 0 0 12px;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.12em;
            color: rgba(255, 255, 255, 0.65);
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        .meta-table td {
            padding: 4px 0;
            color: rgba(255, 255, 255, 0.92);
        }
        .meta-table td:first-child {
            font-weight: 600;
            padding-right: 12px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.7);
        }
        .main {
            padding: 32px;
        }
        .document-title {
             margin: 0 0 8px;
            font-size: 22px;
            color: var(--accent);
            letter-spacing: -0.01em;
        }
        .subtitle {
            margin: 0 0 26px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .intro {
            margin: 0 0 24px;
            color: var(--text-muted);
        }
        .section {
            margin-bottom: 28px;
        }
        .section__title {
            margin: 0 0 12px;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.1em;
            color: var(--accent);
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 18px 22px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .info-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6b758b;
        }
        .info-value {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-main);
        }
        .signature {
            margin-top: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background-color: #fff;
        }
        .signature img {
            max-width: 320px;
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 8px;
        }
        .closing {
            margin-top: 28px;
            color: var(--text-muted);
        }
        .closing strong {
            color: var(--text-main);
        }
        .footer {
            padding: 22px 32px;
            border-top: 1px solid var(--border-color);
            background-color: #fafbfc;
            text-align: center;
            font-size: 12px;
            color: #6b758b;
        }
    </style>
</head>
<body>
<div class="document">
    <header class="header">
        <div class="header__identity">
            <h1><?= $bedrijfsNaam ?></h1>
            <p>
                De Ganskuijl 103B, 3817EZ Amersfoort<br>
                033 785 4284 · contact@digivriend.nl
            </p>
        </div>
    <div class="header__meta">
            <p class="header__meta-title">Documentinformatie</p>
            <table class="meta-table">
                <tr>
                    <td>Document</td>
                    <td><?= $documentTitel ?></td>
                </tr>
                <tr>
                    <td>Datum</td>
                    <td><?= $huidigeDatum ?></td>
                </tr>
            </table>
        </div>
    </header>
    <main class="main">
        <h2 class="document-title">Ophaalbevestiging Gereed</h2>
        <p class="subtitle">Bevestiging dat het apparaat succesvol is afgehaald.</p>
        <p class="intro">Beste <strong><?= $klantnaam ?></strong>,<br>
            Hierbij bevestigen wij dat het onderstaande apparaat is opgehaald uit onze winkel.</p>

        <section class="section">
            <h3 class="section__title">Samenvatting ophalen</h3>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Unieke ophaalcode</span>
                    <span class="info-value"><?= $ophaalcode ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Apparaat</span>
                    <span class="info-value"><?= $merkmodel ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Datum gereed</span>
                    <span class="info-value"><?= $datumgereed ?></span>
                </div>
            </div>
        </section>

        <?php if ($pickupSignature !== ''): ?>
            <section class="section">
                <h3 class="section__title">Handtekening bij ophalen</h3>
                <div class="signature">
                    <img src="<?= $pickupSignature ?>" alt="Handtekening">
                </div>
            </section>
        <?php endif; ?>

        <p class="closing">
            Bedankt voor het vertrouwen in <strong><?= $bedrijfsNaam ?></strong>.<br>
            Met vriendelijke groet,<br>
            <em>Het <?= $bedrijfsNaam ?> team</em>
        </p>
    </main>
    <footer class="footer">
        Dit document is automatisch gegenereerd. Heeft u vragen? Neem contact op via contact@digivriend.nl of bel 033 785 4284.
    </footer>
</div>
</body>
</html>