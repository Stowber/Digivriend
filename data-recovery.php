<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Data Recovery - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/data-recovery.css">
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
          <li><a href="data-recovery.php" aria-current="page">Data Recovery</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main>
    <div class="container">
      <div class="page-header">
        <h1>Data Recovery</h1>
        <p>Start een nieuwe datarecovery-aanvraag via ons partnerformulier. Vul alle velden in, zodat onze specialisten direct met je case aan de slag kunnen.</p>
      </div>

  <div class="embed-shell">
        <div id="zf_div_NjvRlJCeWk-6kWpDnNOiviDvjs0WoTznjCUgcSCEIgw"></div>
      </div>

    <div class="contact-card">
        <p>Vragen over data recovery? Mail naar <a href="mailto:contact@digivriend.nl">contact@digivriend.nl</a> of bel <strong>+31 (0)33 785 4284</strong>.</p>
      </div>
    </div>
  </main>

    <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>

   <script type="text/javascript">
  (function() {
      try {
          var f = document.createElement("iframe");
          f.src = "https://forms.zohopublic.eu/ahogye/form/Diagnosis/formperma/NjvRlJCeWk-6kWpDnNOiviDvjs0WoTznjCUgcSCEIgw?zf_rszfm=1&con1=Netherlands&con2=Netherlands&con3=Netherlands&ML=NL%2FDutch&pa=true&pc=PARTNERCODE&pn=BEDRIJFSNAAM";
          f.style.border = "none";
          f.style.height = "2503px";
          f.style.width = "100%";
          f.style.transition = "all 0.5s ease";
          var d = document.getElementById("zf_div_NjvRlJCeWk-6kWpDnNOiviDvjs0WoTznjCUgcSCEIgw");
          d.appendChild(f);
          window.addEventListener('message', function(event) {
              var zf_ifrm_data = event.data.split("|");
              var zf_perma = zf_ifrm_data[0];
              var zf_ifrm_ht_nw = (parseInt(zf_ifrm_data[1], 10) + 15) + "px";
              var iframe = document.getElementById("zf_div_NjvRlJCeWk-6kWpDnNOiviDvjs0WoTznjCUgcSCEIgw").getElementsByTagName("iframe")[0];
              if ((iframe.src).indexOf('formperma') > 0 && (iframe.src).indexOf(zf_perma) > 0) {
                  var prevIframeHeight = iframe.style.height;
                  if (prevIframeHeight != zf_ifrm_ht_nw) {
                      iframe.style.height = zf_ifrm_ht_nw;
                  }
              }
          }, false);
      } catch (e) {}
  })();
  </script>
</body>
</html>
