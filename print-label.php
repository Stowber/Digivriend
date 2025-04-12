<?php
// print-label.php
// Zorg dat je server Windows is en bPAC is geïnstalleerd.

if (!isset($_POST['idCode'])) {
    die("Geen ID-code ontvangen.");
}

$idCode = escapeshellarg($_POST['idCode']);

// Pad naar je VBScript
$scriptPath = "C:\\xampp\\htdocs\\Digivriend3\\label-print.vbs";

// Bouw de command
$cmd = "cscript //nologo \"{$scriptPath}\" {$idCode}";

// Uitvoeren
exec($cmd, $output, $returnVar);

if ($returnVar === 0) {
    echo "Label is geprint!";
} else {
    echo "Fout bij printen. Code: $returnVar <br>";
    echo "Output: <pre>" . print_r($output, true) . "</pre>";
}
