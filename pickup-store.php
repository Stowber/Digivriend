<?php
// pickup-store.php
require 'database.php';

if (!isset($_POST['id'], $_POST['signatureData'])) {
    die("Onvoldoende gegevens ontvangen.");
}

$id = (int) $_POST['id'];
$signatureData = $_POST['signatureData'];

// Check of handtekening leeg is
if (empty($signatureData)) {
    die("Handtekening is leeg.");
}

// Update DB: zet pickup_signature en status op 'opgehaald'
$stmt = $pdo->prepare("
    UPDATE ophaalbevestigingen
    SET pickup_signature = :sig,
        status = 'opgehaald'
    WHERE id = :id
");
$stmt->execute([
    'sig' => $signatureData,
    'id'  => $id
]);

// Eventueel direct PDF tonen, of terug naar de lijst
header("Location: generate-apparaat-opgehaald.php?id=$id");
exit;
