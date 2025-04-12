<?php
require __DIR__ . '/vendor/autoload.php';
require 'database.php'; // Jouw PDO-connectie

use Dompdf\Dompdf;
use Dompdf\Options;

// 1. Controleer of de benodigde POST-variabelen aanwezig zijn
if (
    !isset($_POST['fullname'], $_POST['address'], $_POST['phone'], $_POST['email'], 
           $_POST['signatureName'], $_POST['signaturePlace'], $_POST['signatureDate'])
) {
    die("Niet alle verplichte velden zijn ingevuld.");
}

// 2. Haal de waarden op
$fullname         = htmlspecialchars($_POST['fullname']);
$address          = htmlspecialchars($_POST['address']);
$phone            = htmlspecialchars($_POST['phone']);
$email            = htmlspecialchars($_POST['email']);

$repairConsent100   = isset($_POST['repairConsent100']) ? 1 : 0;
$repairConsentNotify = isset($_POST['repairConsentNotify']) ? 1 : 0;
$repairConsentCustom = isset($_POST['repairConsentCustom']) ? 1 : 0;
$customAmount        = isset($_POST['customAmount']) ? htmlspecialchars($_POST['customAmount']) : "";

$signatureName  = htmlspecialchars($_POST['signatureName']);
$signaturePlace = htmlspecialchars($_POST['signaturePlace']);
$signatureDate  = htmlspecialchars($_POST['signatureDate']);
$signature      = isset($_POST['signature']) ? htmlspecialchars($_POST['signature']) : "";

// 3. Database (optioneel)
try {
    $stmt = $pdo->prepare("
        INSERT INTO reparatie_onderzoek (
            fullname, address, phone, email,
            repair_consent_100, repair_consent_notify, repair_consent_custom, custom_amount,
            signature_name, signature_place, signature_date, signature
        ) VALUES (
            :fullname, :address, :phone, :email,
            :c100, :cNotify, :cCustom, :cAmount,
            :sigName, :sigPlace, :sigDate, :sig
        )
    ");
    $stmt->execute([
        'fullname'  => $fullname,
        'address'   => $address,
        'phone'     => $phone,
        'email'     => $email,
        'c100'      => $repairConsent100,
        'cNotify'   => $repairConsentNotify,
        'cCustom'   => $repairConsentCustom,
        'cAmount'   => $customAmount,
        'sigName'   => $signatureName,
        'sigPlace'  => $signaturePlace,
        'sigDate'   => $signatureDate,
        'sig'       => $signature
    ]);
    $insertId = $pdo->lastInsertId();
} catch (PDOException $e) {
    die("Fout bij opslaan in de database: " . $e->getMessage());
}

// 4. Stel dynamische velden in voor PDF
$documentTitel = "Toestemmingsformulier Reparatie & Onderzoek";
$bedrijfsNaam  = "Digivriend";
$huidigeDatum  = date("d-m-Y");

// 5. HTML-sjabloon (Dompdf)
$html = '
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title>'.$documentTitel.'</title>
    <style>
        body {
            font-family: "Helvetica", sans-serif;
            color: #333;
            margin: 0; 
            padding: 0; 
            line-height: 1.4;
        }
        @page {
            margin: 40px;
        }
        .header {
            background-color: #0d6efd; /* Bootstrap primary */
            color: #fff;
            padding: 20px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 1.6em;
        }
        .header p {
            margin: 5px 0 0 0;
            font-size: 0.9em;
        }
        .content {
            margin: 0 20px;
        }
        .title-block {
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .title-block h2 {
            margin: 0;
            font-size: 1.3em;
            color: #0d6efd;
        }
        .info-section {
            background: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-section h3 {
            margin-top: 0;
            font-size: 1.1em;
            color: #0d6efd;
        }
        .checkmark {
            color: #198754; /* Bootstrap success */
            font-weight: bold;
        }
        .crossmark {
            color: #dc3545; /* Bootstrap danger */
            font-weight: bold;
        }
        .footer {
            margin: 30px 20px;
            padding-top: 10px;
            border-top: 1px solid #ccc;
            font-size: 0.85em;
            color: #666;
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>'.$bedrijfsNaam.'</h1>
        <p>De Ganskuijl 103B, 3817 EZ Amersfoort | +31 (0)33 785 4284 | www.digivriend.nl</p>
    </div>

    <div class="content">
        <div class="title-block">
            <h2>'.$documentTitel.'</h2>
            <p>Gegenereerd op: '.date("d-m-Y").'</p>
        </div>

        <!-- Klantinformatie -->
        <div class="info-section">
            <h3>Klantinformatie</h3>
            <p><strong>Naam:</strong> '.$fullname.'</p>
            <p><strong>Adres + Postcode:</strong> '.$address.'</p>
            <p><strong>Telefoonnummer:</strong> '.$phone.'</p>
            <p><strong>E-mail:</strong> '.$email.'</p>
        </div>

        <!-- Onderzoekstoestemming -->
        <div class="info-section">
            <h3>Onderzoekstoestemming</h3>
            <p>
                Hierbij geef ik, ondergetekende, toestemming aan Digivriend om onderzoek uit te voeren 
                om de aard en omvang van de schade vast te stellen. 
                <br><br>
                <strong>Onderzoekskosten:</strong> €49,95, deze kosten zijn verschuldigd ongeacht 
                de beslissing over verdere reparatie.
            </p>
        </div>

        <!-- Reparatietoestemming -->
        <div class="info-section">
            <h3>Reparatietoestemming</h3>
            <p>Aanvinken indien van toepassing:</p>
            <ul>
                <li>'.
                    ($repairConsent100 
                      ? '<span class="checkmark">&#10003;</span>' 
                      : '<span class="crossmark">&#10060;</span>'
                    ).' 
                    Reparaties tot €100 zonder kennisgeving.
                </li>
                <li>'.
                    ($repairConsentNotify
                      ? '<span class="checkmark">&#10003;</span>' 
                      : '<span class="crossmark">&#10060;</span>'
                    ).' 
                    Eerst op de hoogte gebracht worden van alle reparatiekosten.
                </li>
                <li>'.
                    ($repairConsentCustom
                      ? '<span class="checkmark">&#10003;</span>' 
                      : '<span class="crossmark">&#10060;</span>'
                    ).'
                    Reparaties tot €'.($customAmount ?: '___').' zonder verdere kennisgeving.
                </li>
            </ul>
        </div>

        <!-- Verklaring van Akkoord -->
        <div class="info-section">
            <h3>Verklaring van Akkoord</h3>
            <p>
                Door het ondertekenen van dit formulier bevestig ik dat ik akkoord ga met de 
                volledige algemene voorwaarden van Digivriend met betrekking tot onderzoek 
                en reparatie, zoals deze ter plaatse zijn in te zien. Tevens ben ik ervan 
                op de hoogte dat deze algemene voorwaarden op verzoek digitaal of fysiek 
                ter beschikking kunnen worden gesteld.
            </p>
            <br>
            <p><strong>Naam (ondertekenaar):</strong> '.$signatureName.'</p>
            <p><strong>Plaats:</strong> '.$signaturePlace.'</p>
            <p><strong>Datum:</strong> '.$signatureDate.'</p>
            <p><strong>Handtekening:</strong> '.$signature.'</p>
        </div>

        <p>Bedankt voor het kiezen van <strong>'.$bedrijfsNaam.'</strong>!</p>
    </div>

    <div class="footer">
        <p>&copy; '.$bedrijfsNaam.' - Alle rechten voorbehouden.</p>
    </div>
</body>
</html>
';

// 6. Dompdf
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Bestandsnaam: "Reparatie-Onderzoek[DD-MM-YYYY][ID].pdf"
$filename = "Reparatie-Onderzoek[{$huidigeDatum}][{$insertId}].pdf";
$dompdf->stream($filename, ["Attachment" => true]);
