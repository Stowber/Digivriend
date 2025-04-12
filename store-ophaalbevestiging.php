<?php
// store-ophaalbevestiging.php
require 'database.php';

/**
 * Genereer een unieke 6-cijferige code en check in de DB of hij al bestaat.
 */
function generateUniqueCode($pdo) {
    while (true) {
        // 000001 tot 999999 (met voorloopnullen)
        $code = str_pad(rand(0, 999999), 6, "0", STR_PAD_LEFT);

        // Check of deze code al bestaat
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ophaalbevestigingen WHERE ophaalcode = :code");
        $stmt->execute(['code' => $code]);
        $count = $stmt->fetchColumn();

        if ($count == 0) {
            return $code;
        }
    }
}

// Controleer of de benodigde POST-velden aanwezig zijn
if (!isset($_POST['klantnaam'], $_POST['merkmodel'], $_POST['datumgereed'])) {
    die("Niet alle velden zijn ingevuld.");
}

$klantnaam   = $_POST['klantnaam'];
$merkmodel   = $_POST['merkmodel'];
$datumgereed = $_POST['datumgereed'];

// Genereer unieke 6-cijferige code
$uniekeCode = generateUniqueCode($pdo);

// Sla de gegevens op in de database
$stmt = $pdo->prepare("
    INSERT INTO ophaalbevestigingen (klantnaam, merkmodel, ophaalcode, datumgereed)
    VALUES (:klantnaam, :merkmodel, :code, :datum)
");
$stmt->execute([
    'klantnaam' => $klantnaam,
    'merkmodel' => $merkmodel,
    'code'      => $uniekeCode,
    'datum'     => $datumgereed
]);

// Direct doorverwijzen naar de PDF-generator
// We geven de code door in de URL, bijv. ?code=123456
header("Location: generate-ophaalbevestiging.php?code=$uniekeCode");
exit;
