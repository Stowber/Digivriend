<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Data Recovery - Digivriend</title>
  <!-- Optioneel Bootstrap voor een moderne stijl (verwijder als je het niet nodig hebt) -->
  <link 
    rel="stylesheet" 
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
  >
</head>
<body class="bg-light">

  <!-- Optioneel: een simpele navbar -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container">
      <a class="navbar-brand" href="index.php">Digivriend</a>
    </div>
  </nav>

  <div class="container mb-5">
    <h1 class="mb-3 text-primary">Data Recovery</h1>
    <p class="text-muted">
      Welkom bij het Data Recovery-formulier van onze partner. Vul onderstaande velden in om 
      je data recovery-aanvraag te starten.
    </p>

    <!-- Zoho Form Container -->
    <div id="zf_div_NjvRlJCeWk-6kWpDnNOiviDvjs0WoTznjCUgcSCEIgw"></div>
    <script type="text/javascript">
    (function() {
        try {
            var f = document.createElement("iframe");
            f.src = "https://forms.zohopublic.eu/ahogye/form/Diagnosis/formperma/NjvRlJCeWk-6kWpDnNOiviDvjs0WoTznjCUgcSCEIgw?zf_rszfm=1&con1=Netherlands&con2=Netherlands&con3=Netherlands&ML=NL%2FDutch&pa=true&pc=PARTNERCODE&pn=BEDRIJFSNAAM";
            f.style.border = "none";
            f.style.height = "2503px";
            f.style.width = "99%";
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

    <!-- Eventueel extra tekst of informatie onder het formulier -->
    <p class="mt-4">
      Heb je vragen over data recovery? Neem dan gerust contact met ons op via 
      <a href="mailto:info@digivriend.nl">info@digivriend.nl</a> of 
      bel ons op <strong>+31 (0)33 785 4284</strong>.
    </p>
  </div>

  <!-- Optioneel Bootstrap JS -->
  <script 
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
