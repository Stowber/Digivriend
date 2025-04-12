<?php
// database.php
$DB_HOST = 'localhost';
$DB_NAME = 'Digivriend_db';
$DB_USER = 'digivriend_user';
$DB_PASS = 'SuperVeiligWachtwoord123!';

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8", $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("Fout bij verbinden met de database: " . $e->getMessage());
}
