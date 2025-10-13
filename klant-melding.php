<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Klantmelding - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/klant-melding.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
       <a href="index.php" class="logo">Digivriend</a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <ul>
          <li><a href="index.php">Start</a></li>
          <li><a href="ophaalbevestiging.php">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="klant-melding.php" aria-current="page">Klant Melding</a></li>
        </ul>
      </nav>
    </div>
  </header>
  
  <section class="hero-band">
    <div class="container">
      <div class="page-header">
        <h1>Klantmelding</h1>
        <p>Leg klantgegevens, apparaatinformatie en de melding vast in één overzichtelijke PDF. Vul de velden in en genereer direct een professioneel document.</p>
      </div>
    </div>
  </section>

  <main>
    <div class="container page-wrapper">
      <form action="generate-klant-melding.php" method="POST" class="form-shell">
        <div class="form-grid">
      <!-- Klantgegevens -->
      <section class="form-section">
        <h2>Klantgegevens</h2>
        <label for="klantnaam">Naam</label>
        <input type="text" id="klantnaam" name="klantnaam" required>
        
        <label for="klantadres">Adres</label>
        <input type="text" id="klantadres" name="klantadres" required>
        
        <label for="klanttelefoon">Telefoonnummer</label>
        <input type="text" id="klanttelefoon" name="klanttelefoon" required>
        
        <label for="klantemail">E-mailadres</label>
        <input type="email" id="klantemail" name="klantemail" required>
      </section>
      
      <!-- Apparaatgegevens -->
      <section class="form-section">
        <h2>Apparaatgegevens</h2>
        <label for="apparaatmerk">Merk</label>
        <input type="text" id="apparaatmerk" name="apparaatmerk" required>
        
        <label for="apparaatmodel">Model</label>
        <input type="text" id="apparaatmodel" name="apparaatmodel" required>
        
        <label for="apparaatserienummer">Serienummer (optioneel)</label>
        <input type="text" id="apparaatserienummer" name="apparaatserienummer">
      </section>
      
      <!-- Melding -->
      <section class="form-section">
        <h2>Melding</h2>
        <label for="meldingonderwerp">Onderwerp</label>
        <input type="text" id="meldingonderwerp" name="meldingonderwerp" required>
        
        <label for="meldingomschrijving">Omschrijving</label>
        <textarea id="meldingomschrijving" name="meldingomschrijving" rows="5" required></textarea>
        
        <label for="meldingdatum">Datum Melding</label>
        <input type="date" id="meldingdatum" name="meldingdatum" required>
        
        <label for="opmerkingen">Extra Opmerkingen (optioneel)</label>
        <textarea id="opmerkingen" name="opmerkingen" rows="3"></textarea>
      </section>
      
      <!-- Reparatie Spoed -->
      <section class="form-section">
        <h2>Reparatie Spoed</h2>
        <div class="radio-group">
          <label>
            <input type="radio" name="reparatiespoed" value="standard" checked>
            Standaard (3–5 werkdagen, geen kosten)
          </label>
          <label>
            <input type="radio" name="reparatiespoed" value="snel">
            Snel (2–3 werkdagen, +15€)
          </label>
          <label>
            <input type="radio" name="reparatiespoed" value="spoed">
            Spoed (24 uur, +50€)
          </label>
        </div>
      </section>
      
      <!-- Contact opnemen -->
      <section class="form-section">
        <h2>Contact met klant</h2>
        <div class="radio-group">
          <label>
            <input type="radio" name="magcontact" value="ja" checked>
            Ja, contact is toegestaan
          </label>
          <label>
            <input type="radio" name="magcontact" value="nee">
            Nee, geen contact opnemen
          </label>
        </div>
      </section>
      
      <!-- Informeren over kosten -->
      <section class="form-section">
        <h2>Informeren over Kosten</h2>
        <div class="radio-group">
          <label>
            <input type="radio" name="kostenoptie" value="allekosten" checked>
            Alle kosten laten weten
          </label>
          <label>
            <input type="radio" name="kostenoptie" value="tot100">
            Toestemming tot 100€ zonder kennisgeving
          </label>
          <label>
            <input type="radio" name="kostenoptie" value="zelfbedrag">
            Zelf bedrag bepalen zonder kennisgeving
          </label>
        </div>
        <label for="bedragzelf">Indien zelf bedrag, geef het bedrag:</label>
        <input type="text" id="bedragzelf" name="bedragzelf" placeholder="Bijv. 200€">
      </section>
      
       </div>

        <div class="form-actions">
          <button type="submit" class="btn">Genereer PDF</button>
        </div>
      </form>
    </div>
  </main>
   <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
</body>
</html>
