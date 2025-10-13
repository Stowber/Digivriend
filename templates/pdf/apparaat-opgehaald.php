<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title><?= $documentTitel ?> - <?= $bedrijfsNaam ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 14px;
            margin: 0;
            padding: 0;
            line-height: 1.5;
            color: #333;
        }
        @page { margin: 40px; }
        .content-wrapper { margin: 0 20px; }
        .header {
            background-color: #F05A28;
            color: #fff;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header .company-info { text-align: right; }
        .header .company-info h1 { margin: 0; font-size: 1.5em; }
        .header .company-info p { margin: 5px 0 0 0; font-size: 0.9em; }
        .document-title {
            margin-top: 30px;
            margin-bottom: 20px;
            border-bottom: 2px solid #F05A28;
            padding-bottom: 10px;
        }
        .document-title h2 { margin: 0; font-size: 1.4em; color: #F05A28; }
        .document-title p { margin: 5px 0 0 0; font-size: 0.9em; color: #666; }
        .info-block {
            background-color: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-block h3 { margin-top: 0; font-size: 1.1em; color: #F05A28; }
        .info-block p { margin: 5px 0; }
        .signature { margin-top: 20px; }
        .signature img { border: 1px solid #ccc; max-width: 300px; height: auto; }
        .footer {
            margin: 30px 20px;
            padding-top: 10px;
            border-top: 1px solid #ccc;
            font-size: 0.9em;
            color: #666;
        }
    </style>
</head>
<body>
<div class="header">
    <div class="company-info">
        <h1><?= $bedrijfsNaam ?></h1>
        <p>
            Adres: De Ganskuijl 103B, 3817EZ Amersfoort<br>
            Telefoon: 033 785 4284<br>
            E-mail: contact@digivriend.nl
        </p>
    </div>
</div>
<div class="content-wrapper">
    <div class="document-title">
        <h2><?= $documentTitel ?></h2>
        <p>Gegenereerd op: <?= $huidigeDatum ?></p>
    </div>
    <p>Beste <strong><?= $klantnaam ?></strong>,</p>
    <p>Hierbij bevestigen wij dat het apparaat met onderstaande gegevens is opgehaald:</p>
    <div class="info-block">
        <h3>Ophaalgegevens</h3>
        <p><strong>Unieke Ophaalcode:</strong> <?= $ophaalcode ?></p>
        <p><strong>Apparaat:</strong> <?= $merkmodel ?></p>
        <p><strong>Datum gereed:</strong> <?= $datumgereed ?></p>
    </div>
    <?php if ($pickupSignature !== ''): ?>
        <div class="signature">
            <p><strong>Handtekening bij ophalen:</strong></p>
            <img src="<?= $pickupSignature ?>" alt="Handtekening">
        </div>
    <?php endif; ?>
    <p style="margin-top: 20px;">
        Bedankt voor het kiezen van <strong><?= $bedrijfsNaam ?></strong>!<br>
        Met vriendelijke groet,<br>
        <em>Het <?= $bedrijfsNaam ?> Team</em>
    </p>
</div>
<div class="footer">
    <p>Dit document is automatisch gegenereerd. Voor vragen kunt u contact opnemen via contact@digivriend.nl of 033 785 4284.</p>
</div>
</body>
</html>