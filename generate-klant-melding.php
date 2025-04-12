<?php
require __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Controleer of de verplichte POST-velden aanwezig zijn
if (
    !isset($_POST['klantnaam'], $_POST['klantadres'], $_POST['klanttelefoon'], $_POST['klantemail'], 
           $_POST['apparaatmerk'], $_POST['apparaatmodel'], $_POST['meldingonderwerp'], 
           $_POST['meldingomschrijving'], $_POST['meldingdatum'], 
           $_POST['reparatiespoed'], $_POST['magcontact'], $_POST['kostenoptie'])
) {
    die("Niet alle verplichte velden zijn ingevuld.");
}

// Haal de waarden op en escape ze
$klantnaam           = htmlspecialchars($_POST['klantnaam']);
$klantadres          = htmlspecialchars($_POST['klantadres']);
$klanttelefoon       = htmlspecialchars($_POST['klanttelefoon']);
$klantemail          = htmlspecialchars($_POST['klantemail']);

$apparaatmerk        = htmlspecialchars($_POST['apparaatmerk']);
$apparaatmodel       = htmlspecialchars($_POST['apparaatmodel']);
$apparaatserienummer = isset($_POST['apparaatserienummer']) ? htmlspecialchars($_POST['apparaatserienummer']) : "";

$meldingonderwerp    = htmlspecialchars($_POST['meldingonderwerp']);
$meldingomschrijving = nl2br(htmlspecialchars($_POST['meldingomschrijving']));
$meldingdatum        = htmlspecialchars($_POST['meldingdatum']);
$opmerkingen         = isset($_POST['opmerkingen']) ? nl2br(htmlspecialchars($_POST['opmerkingen'])) : "";

// Nieuwe velden
$reparatiespoed      = htmlspecialchars($_POST['reparatiespoed']);  // standard, snel, spoed
$magcontact          = htmlspecialchars($_POST['magcontact']);      // ja, nee
$kostenoptie         = htmlspecialchars($_POST['kostenoptie']);     // allekosten, tot100, zelfbedrag
$bedragzelf          = ""; // Als kostenoptie == 'zelfbedrag'
if (isset($_POST['bedragzelf'])) {
    $bedragzelf = htmlspecialchars($_POST['bedragzelf']);
}

// Dynamische velden
$documentTitel = "Klant Melding";
$bedrijfsNaam  = "Digivriend";
$huidigeDatum  = date("d-m-Y");
$jaartal       = date("Y");

// Spoed-tekst bepalen
switch ($reparatiespoed) {
    case 'standard':
        $spoedText = "Standaard (3–5 werkdagen, geen kosten)";
        break;
    case 'snel':
        $spoedText = "Snel (2–3 werkdagen, +15€)";
        break;
    case 'spoed':
        $spoedText = "Spoed (24 uur, +50€)";
        break;
    default:
        $spoedText = "Onbekend";
}

// Mag contact?
$contactText = ($magcontact === 'ja') 
    ? "Ja. Telefonisch contact met de klant is toegestaan. De partner mag de klant rechtstreeks benaderen om prijsafspraken of werkzaamheden te bespreken." 
    : "Nee. De klant wil niet door andere partijen benaderd worden. Alleen Digivriend is bevoegd om contact op te nemen met de klant.";

// Kostenoptie
$kostenText = "";
switch ($kostenoptie) {
    case 'allekosten':
        $kostenText = "Alle kosten laten weten.";
        break;
    case 'tot100':
        $kostenText = "Toestemming tot 100€ zonder kennisgeving.";
        break;
    case 'zelfbedrag':
        $kostenText = "Toestemming tot ".$bedragzelf." zonder kennisgeving.";
        break;
    default:
        $kostenText = "Onbekende optie.";
}

// HTML-opmaak: geen flex, geen gradient
$html = '
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>'.$documentTitel.' - '.$bedrijfsNaam.'</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: Arial, sans-serif;
      color: #333;
    }
    @page {
      margin: 20mm;
    }
    .header {
      background-color: #F05A28;
      color: #fff;
      padding: 15px;
      text-align: center;
    }
    .header h1 {
      margin: 0;
      font-size: 24px;
    }
    .header p {
      margin: 5px 0 0;
      font-size: 14px;
    }
    .container {
      margin: 20px;
    }
    .card {
      border: 1px solid #ccc;
      border-radius: 4px;
      padding: 15px;
      margin-bottom: 20px;
      background-color: #fafafa;
    }
    .card h2 {
      margin-top: 0;
      font-size: 18px;
      color: #F05A28;
      border-bottom: 1px solid #ddd;
      padding-bottom: 5px;
    }
    .row {
      margin: 5px 0;
    }
    .label {
      display: inline-block;
      width: 100px;
      font-weight: bold;
    }
    .two-column-table {
      width: 100%;
      border-spacing: 15px;
    }
    .two-column-table td {
      vertical-align: top;
      width: 50%;
    }
    .footer {
      text-align: center;
      font-size: 12px;
      color: #666;
      border-top: 1px solid #ccc;
      padding-top: 10px;
      margin-top: 20px;
    }
  </style>
</head>
<body>
  <div class="header">
    <h1>'.$bedrijfsNaam.'</h1>
    <p>'.$documentTitel.' - '.$huidigeDatum.'</p>
  </div>
  <div class="container">

    <!-- Klant en apparaatgegevens naast elkaar -->
    <table class="two-column-table">
      <tr>
        <td>
          <div class="card">
            <h2>Klantgegevens</h2>
            <div class="row"><span class="label">Naam:</span> '.$klantnaam.'</div>
            <div class="row"><span class="label">Adres:</span> '.$klantadres.'</div>
            <div class="row"><span class="label">Telefoon:</span> '.$klanttelefoon.'</div>
            <div class="row"><span class="label">E-mail:</span> '.$klantemail.'</div>
          </div>
        </td>
        <td>
          <div class="card">
            <h2>Apparaatgegevens</h2>
            <div class="row"><span class="label">Merk:</span> '.$apparaatmerk.'</div>
            <div class="row"><span class="label">Model:</span> '.$apparaatmodel.'</div>';

if (!empty($apparaatserienummer)) {
    $html .= '<div class="row"><span class="label">Serienr.:</span> '.$apparaatserienummer.'</div>';
}

$html .= '
          </div>
        </td>
      </tr>
    </table>

    <!-- Melding card -->
    <div class="card">
      <h2>Melding</h2>
      <div class="row"><span class="label">Onderwerp:</span> '.$meldingonderwerp.'</div>
      <div class="row" style="margin-top:8px;"><strong>Omschrijving:</strong><br>'.$meldingomschrijving.'</div>
      <div class="row" style="margin-top:8px;"><span class="label">Datum:</span> '.$meldingdatum.'</div>';

if (!empty($opmerkingen)) {
    $html .= '<div class="row" style="margin-top:8px;"><strong>Opmerkingen:</strong><br>'.$opmerkingen.'</div>';
}

$html .= '
    </div>

    <!-- Nieuwe opties -->
    <div class="card">
      <h2>Reparatie Opties</h2>
      <div class="row">
        <span class="label">Spoed:</span> '.$spoedText.'
      </div>
      <div class="row">
        <span class="label">Contact:</span> '.$contactText.'
      </div>
      <div class="row">
        <strong>Kosten:</strong> '.$kostenText.'
      </div>
    </div>

  </div>
  <div class="footer">
    &copy; '.$jaartal.' '.$bedrijfsNaam.'. Alle rechten voorbehouden.
  </div>
</body>
</html>
';

// Dompdf configureren
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Bestandsnaam genereren
$filename = "Klant-Melding[".$huidigeDatum."][".time()."].pdf";
$dompdf->stream($filename, ["Attachment" => true]);
