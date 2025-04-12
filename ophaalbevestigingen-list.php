<?php
// ophaalbevestigingen-list.php
require 'database.php';

// Als er een zoekopdracht is
$searchCode = $_GET['search'] ?? '';

// Bouw de query dynamisch
$sql = "SELECT * FROM ophaalbevestigingen";
$params = [];
if ($searchCode !== '') {
    $sql .= " WHERE ophaalcode LIKE :search";
    $params['search'] = "%$searchCode%";
}
$sql .= " ORDER BY created_at DESC"; // sorteren op aanmaakdatum

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bevestigingen = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Ophaalbevestigingen - Overzicht</title>
  <link rel="stylesheet" href="css/ophaalbevestiging-list.css">
</head>
<body>
  <h1>Ophaalbevestigingen</h1>

  <!-- Zoek op code -->
  <form method="GET" class="search-bar">
    <label for="search">Zoek op ophaalcode:</label>
    <input type="text" name="search" id="search" value="<?= htmlspecialchars($searchCode) ?>">
    <button type="submit">Zoeken</button>
  </form>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Klantnaam</th>
        <th>Merk/Model</th>
        <th>Ophaalcode</th>
        <th>Datum gereed</th>
        <th>Status</th>
        <th>Actie</th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($bevestigingen) === 0): ?>
        <tr>
          <td colspan="7">Geen resultaten gevonden.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($bevestigingen as $bev): ?>
          <tr>
            <td><?= $bev['id'] ?></td>
            <td><?= htmlspecialchars($bev['klantnaam']) ?></td>
            <td><?= htmlspecialchars($bev['merkmodel']) ?></td>
            <td><?= htmlspecialchars($bev['ophaalcode']) ?></td>
            <td><?= htmlspecialchars($bev['datumgereed']) ?></td>
            <td>
              <?php if ($bev['status'] === 'opgehaald'): ?>
                <span class="status-opgehaald">Opgehaald</span>
              <?php else: ?>
                <span class="status-klaar">Klaar</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($bev['status'] === 'klaar'): ?>
                <!-- Link naar handtekenpagina voor ophalen -->
                <a href="pickup-sign.php?id=<?= $bev['id'] ?>">Ophalen</a>
              <?php else: ?>
                <!-- Eventueel link om "Apparaat Opgehaald"-PDF te bekijken -->
                <a href="generate-apparaat-opgehaald.php?id=<?= $bev['id'] ?>" target="_blank">Bekijk PDF</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
