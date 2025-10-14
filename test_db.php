<?php
$dsn  = "pgsql:host=ep-young-rice-agyirx4x-pooler.c-2.eu-central-1.aws.neon.tech;port=5432;dbname=neondb;sslmode=require;options=endpoint=ep-young-rice-agyirx4x-pooler";
$user = "neondb_owner";
$pass = "npg_CUHgXy8tz0iT";

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "✅ Połączenie z bazą działa!";
} catch (Throwable $e) {
    echo "❌ Błąd połączenia: " . $e->getMessage();
}
