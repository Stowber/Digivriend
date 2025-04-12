<?php
/**
 * generate-apparaat-opgehaald.php
 * 
 * Dit script maakt een PDF "Apparaat Opgehaald" met dezelfde layout als generate-ophaalbevestiging.php.
 * We halen de data uit de DB op basis van ?id=... in de URL.
 */

require __DIR__ . '/vendor/autoload.php';
require 'database.php'; // Jouw PDO-connectie

use Dompdf\Dompdf;
use Dompdf\Options;

// 1. Controleer of we een ID hebben in de URL
if (!isset($_GET['id'])) {
    die("Geen ID opgegeven.");
}
$id = (int) $_GET['id'];

// 2. Zoek in de DB naar de record
$stmt = $pdo->prepare("SELECT * FROM ophaalbevestigingen WHERE id = :id");
$stmt->execute(['id' => $id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    die("Geen record gevonden voor ID $id.");
}

// 3. Haal de velden op uit de DB
$klantnaam       = htmlspecialchars($record['klantnaam']);
$merkmodel       = htmlspecialchars($record['merkmodel']);
$ophaalcode      = htmlspecialchars($record['ophaalcode']);
$datumgereed     = htmlspecialchars($record['datumgereed']);
$pickupSignature = $record['pickup_signature']; // Base64 van handtekening (of NULL)

// (Optioneel) Je zou hier ook de status in de DB op 'opgehaald' kunnen zetten:
// $pdo->prepare("UPDATE ophaalbevestigingen SET status='opgehaald' WHERE id=?")->execute([$id]);

// 4. Stel dynamische velden in voor de PDF
$documentTitel = "Apparaat Opgehaald";
$bedrijfsNaam  = "Digivriend";
$huidigeDatum  = date("d-m-Y"); // bv. "15-04-2025"

// 5. HTML-sjabloon, zelfde CSS als generate-ophaalbevestiging.php
$html = '
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>'.$documentTitel.' - '.$bedrijfsNaam.'</title>
    <style>
        /* Algemeen */
        body {
            font-family: Arial, sans-serif;
            font-size: 14px;
            margin: 0;
            padding: 0;
            line-height: 1.5;
            color: #333;
        }

        /* Pagina-margin in Dompdf */
        @page {
            margin: 40px;
        }
        
        /* Container voor de hoofdcontent (buiten de header/voettekst) */
        .content-wrapper {
            margin: 0 20px; /* extra marge binnen de PDF */
        }

        /* Header */
        .header {
            background-color: #F05A28; /* Primaire kleur (oranje) */
            color: #fff;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header .company-info {
            text-align: right;
        }
        .header .company-info h1 {
            margin: 0;
            font-size: 1.5em;
        }
        .header .company-info p {
            margin: 5px 0 0 0;
            font-size: 0.9em;
        }

        /* Document-titelblok */
        .document-title {
            margin-top: 30px;
            margin-bottom: 20px;
            border-bottom: 2px solid #F05A28;
            padding-bottom: 10px;
        }
        .document-title h2 {
            margin: 0;
            font-size: 1.4em;
            color: #F05A28;
        }
        .document-title p {
            margin: 5px 0 0 0;
            font-size: 0.9em;
            color: #666;
        }

        /* Informatie-secties */
        .info-block {
            background-color: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-block h3 {
            margin-top: 0;
            font-size: 1.1em;
            color: #F05A28;
        }
        .info-block p {
            margin: 5px 0;
        }

        /* Handtekening */
        .signature {
            margin-top: 20px;
        }
        .signature img {
            border: 1px solid #ccc;
            max-width: 300px;
            height: auto;
        }

        /* Footer */
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

    <!-- Header -->
    <div class="header">
        <div class="company-info">
            <h1>'.$bedrijfsNaam.'</h1>
            <p>
                Adres: De Ganskuijl 103B, 3817EZ Amersfoort<br>
                Telefoon: 033 785 4284<br>
                E-mail: contact@digivriend.nl
            </p>
        </div>
    </div>

    <!-- Content -->
    <div class="content-wrapper">
        <!-- Titelblok -->
        <div class="document-title">
            <h2>'.$documentTitel.'</h2>
            <p>Gegenereerd op: '.$huidigeDatum.'</p>
        </div>

        <!-- Aanhef -->
        <p>Beste <strong>'.$klantnaam.'</strong>,</p>
        <p>Hierbij bevestigen wij dat het apparaat met onderstaande gegevens is opgehaald:</p>

        <!-- Informatie in een blok -->
        <div class="info-block">
            <h3>Ophaalgegevens</h3>
            <p><strong>Unieke Ophaalcode:</strong> '.$ophaalcode.'</p>
            <p><strong>Apparaat:</strong> '.$merkmodel.'</p>
            <p><strong>Datum gereed:</strong> '.$datumgereed.'</p>
        </div>';

if (!empty($pickupSignature)) {
    $html .= '
        <div class="signature">
            <p><strong>Handtekening bij ophalen:</strong></p>
            <img src="'.$pickupSignature.'" alt="Handtekening">
        </div>';
}

$html .= '
        <p style="margin-top: 20px;">
            Bedankt voor het kiezen van <strong>'.$bedrijfsNaam.'</strong>!<br>
            Met vriendelijke groet,<br>
            <em>Het '.$bedrijfsNaam.' Team</em>
        </p>
    </div>

    <div class="footer">
        <p>Dit document is automatisch gegenereerd. Voor vragen kunt u contact opnemen via contact@digivriend.nl of 033 785 4284.</p>
    </div>
</body>
</html>
';

// 6. Dompdf instellen en PDF downloaden
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Bestandsnaam: "apparaat-opgehaald[DD-MM-YYYY][ID].pdf"
$filename = "apparaat-opgehaald[{$huidigeDatum}][{$id}].pdf";

// Stream de PDF met de dynamische naam
$dompdf->stream($filename, ["Attachment" => true]);
