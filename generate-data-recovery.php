<?php
require __DIR__ . '/vendor/autoload.php';
require 'database.php'; // Jouw PDO-connectie

use Dompdf\Dompdf;
use Dompdf\Options;

// 1. Controleer POST-velden
if (
    !isset($_POST['fullname'], $_POST['address'], $_POST['postcode'], $_POST['phone'], 
           $_POST['email'], $_POST['signatureDate'])
) {
    die("Niet alle verplichte velden zijn ingevuld.");
}

// 2. Haal waarden op
$fullname      = htmlspecialchars($_POST['fullname']);
$address       = htmlspecialchars($_POST['address']);
$postcode      = htmlspecialchars($_POST['postcode']);
$phone         = htmlspecialchars($_POST['phone']);
$email         = htmlspecialchars($_POST['email']);
$signatureDate = htmlspecialchars($_POST['signatureDate']);
$signature     = isset($_POST['signature']) ? htmlspecialchars($_POST['signature']) : "";

// 3. Database-opslag (optioneel)
try {
    // Voorbeeld: tabel 'data_recovery' met kolommen die overeenkomen
    $stmt = $pdo->prepare("
        INSERT INTO data_recovery (
          fullname, address, postcode, phone, email,
          signature_date, signature
        ) VALUES (
          :fullname, :address, :postcode, :phone, :email,
          :sigDate, :sig
        )
    ");
    $stmt->execute([
        'fullname'  => $fullname,
        'address'   => $address,
        'postcode'  => $postcode,
        'phone'     => $phone,
        'email'     => $email,
        'sigDate'   => $signatureDate,
        'sig'       => $signature
    ]);
    $insertId = $pdo->lastInsertId();
} catch (PDOException $e) {
    die("Fout bij opslaan in de database: " . $e->getMessage());
}

// 4. Stel dynamische velden in
$documentTitel = "Toestemmingsverklaring Data Recovery";
$bedrijfsNaam  = "Digivriend";
$huidigeDatum  = date("d-m-Y");

// 5. Bouw HTML voor Dompdf
// Hier plakken we de volledige tekst uit je .docx, ingedeeld in HTML-secties.
$html = '
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>'.$documentTitel.' - '.$bedrijfsNaam.'</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      color: #333;
      margin: 0; 
      padding: 0; 
      line-height: 1.5;
    }
    @page {
      margin: 40px;
    }
    .header {
      background-color: #F05A28;
      color: #fff;
      padding: 20px;
      margin-bottom: 20px;
    }
    .header h1 {
      margin: 0;
      font-size: 1.5em;
    }
    .content {
      margin: 0 20px;
    }
    .title-block {
      border-bottom: 2px solid #F05A28;
      padding-bottom: 10px;
      margin-bottom: 20px;
    }
    .title-block h2 {
      margin: 0;
      font-size: 1.2em;
      color: #F05A28;
    }
    .section {
      margin-bottom: 20px;
    }
    .section h3 {
      margin-top: 0;
      color: #F05A28;
      font-size: 1.1em;
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
      <p>Gegenereerd op: '.$huidigeDatum.'</p>
    </div>

    <!-- Klantinformatie -->
    <div class="section">
      <h3>Klantinformatie (In te vullen door de klant)</h3>
      <p><strong>Volledige naam:</strong> '.$fullname.'</p>
      <p><strong>Adres:</strong> '.$address.'</p>
      <p><strong>Postcode:</strong> '.$postcode.'</p>
      <p><strong>Telefoonnummer:</strong> '.$phone.'</p>
      <p><strong>E-mailadres:</strong> '.$email.'</p>
    </div>

    <!-- Verklaring van Toestemming voor Data Recovery -->
    <div class="section">
      <h3>Verklaring van Toestemming voor Data Recovery</h3>
      <p>
        Ondergetekende, <strong>'.$fullname.'</strong>, verleent hierbij uitdrukkelijk toestemming aan Digivriend 
        om de volgende handelingen uit te voeren met betrekking tot het herstel van gegevens van het apparaat 
        dat door de klant wordt aangeboden voor data recovery.
      </p>
    </div>

    <!-- 1. Toestemming voor Data Recovery -->
    <div class="section">
      <h3>1. Toestemming voor Data Recovery</h3>
      <p><strong>1.1. Uitvoering van Gegevensherstel</strong><br>
        De klant verleent toestemming aan Digivriend om alle noodzakelijke stappen te ondernemen voor het 
        herstel van gegevens van de betreffende hardware. Dit kan betrekking hebben op zowel softwarematige 
        als fysieke interventies. De klant begrijpt dat Digivriend werkt volgens de geldende wet- en regelgeving 
        met betrekking tot gegevensbescherming en privacy, en dat alle herstelde gegevens vertrouwelijk en 
        veilig worden behandeld.
      </p>
      <p><strong>1.2. Opslag en Beveiliging van Herstelde Gegevens</strong><br>
        De klant stemt ermee in dat alle herstelde gegevens op een veilige manier worden opgeslagen en enkel 
        toegankelijk zijn voor bevoegde medewerkers van Digivriend. De klant begrijpt dat de gegevens uitsluitend 
        voor hersteldoeleinden worden verwerkt en dat geen enkele data zonder expliciete toestemming van de klant 
        aan derden wordt verstrekt.
      </p>
    </div>

    <!-- 2. Toestemming voor het Openen van de Behuizing -->
    <div class="section">
      <h3>2. Toestemming voor het Openen van de Behuizing</h3>
      <p><strong>2.1. Fysieke Ingrepen</strong><br>
        Indien de behuizing van de harde schijf of een ander opslagapparaat niet kan worden geopend zonder gebruik 
        van fysieke kracht, verleent de klant toestemming aan Digivriend om de behuizing permanent te openen. 
        Dit omvat onder andere het doorboren, breken of verwijderen van eventuele afdichtingen of bevestigingsmiddelen, 
        indien nodig, om toegang te krijgen tot de interne componenten voor het herstel van gegevens.
      </p>
      <p><strong>2.2. Onomkeerbare Schade aan Behuizing</strong><br>
        De klant begrijpt en accepteert dat het openen van de behuizing op deze manier kan leiden tot permanente schade 
        aan de behuizing en mogelijk aan andere componenten van het apparaat. Digivriend is niet verantwoordelijk voor 
        enige schade aan de behuizing die optreedt als gevolg van het herstelproces.
      </p>
    </div>

    <!-- 3. Kosten en Procedure -->
    <div class="section">
      <h3>3. Kosten en Procedure</h3>
      <p><strong>3.1. Data Recovery via Software</strong><br>
        Indien het mogelijk is om de gegevens te herstellen met behulp van softwarematige methoden, stemt de klant in 
        met een tarief van €349. Deze kosten zijn van toepassing ongeacht de hoeveelheid of waarde van de herstelde gegevens.
      </p>
      <p><strong>3.2. Data Recovery in het Lab</strong><br>
        Indien de schijf naar een gespecialiseerd laboratorium moet worden gestuurd voor verdere herstelpogingen, 
        stemt de klant in met een tarief van €1299, inclusief een geschatte wachttijd van vier (4) weken. Deze kosten 
        zijn exclusief verzend- en administratiekosten, indien van toepassing.
      </p>
      <p><strong>3.3. Betalingsvoorwaarden</strong><br>
        Alle kosten dienen volledig te worden voldaan bij het succesvol herstellen van gegevens. Indien geen gegevens 
        kunnen worden hersteld, wordt er geen bedrag in rekening gebracht, tenzij vooraf anders is overeengekomen.
      </p>
    </div>

    <!-- 4. Aansprakelijkheid en Vrijwaring -->
    <div class="section">
      <h3>4. Aansprakelijkheid en Vrijwaring</h3>
      <p><strong>4.1. Beperking van Aansprakelijkheid</strong><br>
        De klant erkent en aanvaardt dat ondanks alle redelijke inspanningen van Digivriend, succes bij data recovery 
        niet gegarandeerd kan worden. In sommige gevallen kunnen de gegevens onherstelbaar beschadigd zijn of verloren 
        gaan, en Digivriend kan niet aansprakelijk worden gesteld voor enig verlies van gegevens of voor eventuele schade 
        aan de hardware, inclusief maar niet beperkt tot de harde schijf, computer, of andere apparaten.
      </p>
      <p><strong>4.2. Vrijwaring</strong><br>
        De klant vrijwaart hierbij Digivriend, haar medewerkers, agenten en onderaannemers van enige en alle claims, 
        aansprakelijkheden, schade, of kosten die voortvloeien uit of verband houden met het verlies van gegevens, 
        de conditie van de hardware of enige andere problemen die zich voordoen tijdens het proces van gegevensherstel.
      </p>
    </div>

    <!-- Ondertekening -->
    <div class="section">
      <h3>Ondertekening</h3>
      <p>
        Door ondertekening van dit document verklaart de klant dat hij/zij alle bepalingen heeft gelezen en begrepen, 
        en akkoord gaat met de hierin beschreven voorwaarden voor data recovery door Digivriend.
      </p>
      <br>
      <p><strong>Handtekening klant:</strong> '.($signature ?: '______________________').'</p>
      <p><strong>Datum:</strong> '.$signatureDate.'</p>
    </div>
  </div>

  <div class="footer">
    <p>Digivriend | Adres: De Ganskuijl 103B, 3817 EZ Amersfoort | Telefoon: +31 (0)33 785 4284 | www.digivriend.nl</p>
  </div>
</body>
</html>
';

// 6. Dompdf genereren
$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Dynamische bestandsnaam: "Data-Recovery[DD-MM-YYYY][ID].pdf"
$filename = "Data-Recovery[{$huidigeDatum}][{$insertId}].pdf";
$dompdf->stream($filename, ["Attachment" => true]);
