<?php
require __DIR__ . '/vendor/autoload.php';

// 1. Haal de database-connectie binnen
require 'database.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Controleer of de benodigde POST-variabelen aanwezig zijn
if (!isset($_POST['klantnaam'], $_POST['merkmodel'], $_POST['ophaalcode'], $_POST['datumgereed'])) {
    die("Niet alle velden zijn ingevuld.");
}

// Haal de waarden op uit het formulier
$klantnaam   = htmlspecialchars($_POST['klantnaam']);
$merkmodel   = htmlspecialchars($_POST['merkmodel']);
$ophaalcode  = htmlspecialchars($_POST['ophaalcode']);
$datumgereed = htmlspecialchars($_POST['datumgereed']);

// 2. Sla de gegevens op in de database
try {
    // Voorbeeldquery: pas aan naar jouw tabelnaam/kolomnamen
    $stmt = $pdo->prepare("
        INSERT INTO ophaalbevestigingen (klantnaam, merkmodel, ophaalcode, datumgereed)
        VALUES (:klantnaam, :merkmodel, :ophaalcode, :datumgereed)
    ");
    $stmt->execute([
        'klantnaam'   => $klantnaam,
        'merkmodel'   => $merkmodel,
        'ophaalcode'  => $ophaalcode,
        'datumgereed' => $datumgereed
    ]);
    
    // Haal het ID op van de zojuist ingevoerde record
    $insertId = $pdo->lastInsertId();
} catch (PDOException $e) {
    die("Fout bij opslaan in de database: " . $e->getMessage());
}

// Stel wat dynamische velden in
$documentTitel = "Ophaalbevestiging";
$bedrijfsNaam  = "Digivriend";
// Gebruik een datum zonder slash voor bestandsnaam (d-m-Y)
$huidigeDatum  = date("d-m-Y"); 

// HTML-sjabloon voor de PDF met nieuw design
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
        .header .logo {
            height: 50px; /* pas dit aan op basis van je logo */
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

        /* Instructies */
        .instructions {
            margin-top: 20px;
        }
        .instructions h3 {
            font-size: 1.1em;
            color: #F05A28;
        }
        .instructions ol {
            margin: 5px 0 0 20px;
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

    <!-- Header met gekleurde achtergrond -->
    <div class="header">
        <!-- Eventueel een logo -->
        <!-- <img class="logo" src="path/to/logo.png" alt="Bedrijfslogo"> -->

        <div class="company-info">
            <h1>'.$bedrijfsNaam.'</h1>
            <p>
                Adres: De Ganskuijl 103B, 3817EZ Amersfoort<br>
                Telefoon: 033 785 4284<br>
                E-mail: contact@digivriend.nl
            </p>
        </div>
    </div>

    <!-- Hoofdcontent -->
    <div class="content-wrapper">
        <!-- Titelblok voor het document -->
        <div class="document-title">
            <h2>'.$documentTitel.'</h2>
            <p>Gegenereerd op: '.$huidigeDatum.'</p>
        </div>

        <!-- Aanhef -->
        <p>Beste <strong>'.$klantnaam.'</strong>,</p>
        <p>Uw apparaat is gerepareerd en klaar om opgehaald te worden. 
           Gebruik onderstaande unieke code om uw apparaat op te halen in onze winkel.</p>

        <!-- Informatie over het apparaat in een blok -->
        <div class="info-block">
            <h3>Details Ophaalbevestiging</h3>
            <p><strong>Unieke Ophaalcode:</strong> '.$ophaalcode.'</p>
            <p><strong>Apparaat:</strong> '.$merkmodel.'</p>
            <p><strong>Datum gereed:</strong> '.$datumgereed.'</p>
        </div>

        <!-- Instructies in een apart blok -->
        <div class="instructions">
            <h3>Instructies</h3>
            <ol>
                <li>Print deze ophaalbevestiging uit of noteer de unieke code op papier.</li>
                <li>Neem deze mee naar onze winkel om uw apparaat op te halen.</li>
            </ol>
        </div>

        <!-- Afsluiting -->
        <p style="margin-top: 20px;">
            Bedankt voor het kiezen van <strong>'.$bedrijfsNaam.'</strong>!<br>
            Met vriendelijke groet,<br>
            <em>Het '.$bedrijfsNaam.' Team</em>
        </p>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>Dit document is automatisch gegenereerd. Voor vragen kunt u contact opnemen via contact@digivriend.nl of 033 785 4284.</p>
    </div>

</body>
</html>
';

// 3. PDF genereren met Dompdf
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

// HTML inladen
$dompdf->loadHtml($html);

// Instellen papierformaat en -orientatie (A4, staand)
$dompdf->setPaper('A4', 'portrait');

// Renderen
$dompdf->render();

// 4. PDF naar de browser sturen (download)
// Bestandsnaam: "Ophaalbevestiging[DD-MM-YYYY][ID].pdf"
$filename = "Ophaalbevestiging[{$huidigeDatum}][{$insertId}].pdf";

// Stream de PDF met de dynamische naam
$dompdf->stream($filename, ["Attachment" => true]);
