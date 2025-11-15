<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title><?= $documentTitel ?> - <?= $bedrijfsNaam ?></title>
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
    <h1><?= $bedrijfsNaam ?></h1>
    <p>De Ganskuijl 103B, 3817 EZ Amersfoort | +31 (0)33 785 4284 | www.digivriend.nl</p>
  </div>

  <div class="content">
    <div class="title-block">
      <h2><?= $documentTitel ?></h2>
      <p>Gegenereerd op: <?= $huidigeDatum ?> · Referentie: <?= $generatedReference ?></p>
    </div>

    <div class="section">
      <h3>Klantinformatie (In te vullen door de klant)</h3>
       <p><strong>Volledige naam:</strong> <?= $fullname ?></p>
      <p><strong>Adres:</strong> <?= $address ?></p>
      <p><strong>Postcode:</strong> <?= $postcode ?></p>
      <p><strong>Telefoonnummer:</strong> <?= $phone ?></p>
      <p><strong>E-mailadres:</strong> <?= $email ?></p>
    </div>

    <div class="section">
      <h3>Apparaatgegevens</h3>
      <?php if ($deviceBrand !== ''): ?><p><strong>Merk:</strong> <?= $deviceBrand ?></p><?php endif; ?>
      <?php if ($deviceModel !== ''): ?><p><strong>Model:</strong> <?= $deviceModel ?></p><?php endif; ?>
      <?php if ($deviceSerial !== ''): ?><p><strong>Serienummer:</strong> <?= $deviceSerial ?></p><?php endif; ?>
    </div>

    <div class="section">
      <h3>Verklaring van Toestemming voor Data Recovery</h3>
      <p>
       Ondergetekende, <strong><?= $fullname ?></strong>, verleent hierbij uitdrukkelijk toestemming aan Digivriend om de volgende handelingen uit te voeren met betrekking tot het herstel van gegevens van het apparaat dat door de klant wordt aangeboden voor data recovery.
      </p>
    </div>

    <div class="section">
      <h3>1. Toestemming voor Data Recovery</h3>
      <p><strong>1.1. Uitvoering van Gegevensherstel</strong><br>
       De klant verleent toestemming aan Digivriend om alle noodzakelijke stappen te ondernemen voor het herstel van gegevens van de betreffende hardware. Dit kan betrekking hebben op zowel softwarematige als fysieke interventies. De klant begrijpt dat Digivriend werkt volgens de geldende wet- en regelgeving met betrekking tot gegevensbescherming en privacy, en dat alle herstelde gegevens vertrouwelijk en veilig worden behandeld.
      </p>
      <p><strong>1.2. Opslag en Beveiliging van Herstelde Gegevens</strong><br>
        De klant stemt ermee in dat alle herstelde gegevens op een veilige manier worden opgeslagen en enkel toegankelijk zijn voor bevoegde medewerkers van Digivriend. De klant begrijpt dat de gegevens uitsluitend voor hersteldoeleinden worden verwerkt en dat geen enkele data zonder expliciete toestemming van de klant aan derden wordt verstrekt.
      </p>
    </div>

    <div class="section">
      <h3>2. Toestemming voor het Openen van de Behuizing</h3>
      <p><strong>2.1. Fysieke Ingrepen</strong><br>
        Indien de behuizing van de harde schijf of een ander opslagapparaat niet kan worden geopend zonder gebruik van fysieke kracht, verleent de klant toestemming aan Digivriend om de behuizing permanent te openen. Dit omvat onder andere het doorboren, breken of verwijderen van eventuele afdichtingen of bevestigingsmiddelen, indien nodig, om toegang te krijgen tot de interne componenten voor het herstel van gegevens.
      </p>
      <p><strong>2.2. Onomkeerbare Schade aan Behuizing</strong><br>
        De klant begrijpt en accepteert dat het openen van de behuizing op deze manier kan leiden tot permanente schade aan de behuizing en mogelijk aan andere componenten van het apparaat. Digivriend is niet verantwoordelijk voor enige schade aan de behuizing die optreedt als gevolg van het herstelproces.
      </p>
    </div>

    <div class="section">
      <h3>3. Kosten en Procedure</h3>
      <p><strong>3.1. Data Recovery via Software</strong><br>
        Indien het mogelijk is om de gegevens te herstellen met behulp van softwarematige methoden, stemt de klant in met een tarief van €349. Deze kosten zijn van toepassing ongeacht de hoeveelheid of waarde van de herstelde gegevens.
      </p>
      <p><strong>3.2. Data Recovery in het Lab</strong><br>
        Indien de schijf naar een gespecialiseerd laboratorium moet worden gestuurd voor verdere herstelpogingen, stemt de klant in met een tarief van €1299, inclusief een geschatte wachttijd van vier (4) weken. Deze kosten zijn exclusief verzend- en administratiekosten, indien van toepassing.
      </p>
      <p><strong>3.3. Betalingsvoorwaarden</strong><br>
        Alle kosten dienen volledig te worden voldaan bij het succesvol herstellen van gegevens. Indien geen gegevens kunnen worden hersteld, wordt er geen bedrag in rekening gebracht, tenzij vooraf anders is overeengekomen.
      </p>
    </div>

    <div class="section">
      <h3>4. Aansprakelijkheid en Vrijwaring</h3>
      <p>
        Digivriend zal alle redelijke maatregelen nemen om schade aan het apparaat te voorkomen tijdens het herstelproces. De klant begrijpt dat Digivriend niet aansprakelijk is voor eventuele bijkomende schade, tenzij sprake is van grove nalatigheid.
      </p>
    </div>

    <div class="section">
      <h3>5. Ondertekening</h3>
      <p><strong>Naam:</strong> <?= $fullname ?></p>
      <p><strong>Datum akkoord:</strong> <?= $signatureDate ?></p>
      <?php if ($signature !== ''): ?><p><strong>Handtekening:</strong> <?= $signature ?></p><?php endif; ?>
    </div>
    <?php if ($notes !== ''): ?>
      <div class="section">
        <h3>Opmerkingen</h3>
        <p><?= nl2br($notes) ?></p>
      </div>
    <?php endif; ?>
  </div>

  <div class="footer">
    <p>&copy; <?= date('Y') ?> <?= $bedrijfsNaam ?>. Alle rechten voorbehouden.</p>
  </div>
</body>
</html>