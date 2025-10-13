<?php


declare(strict_types=1);

use App\Http\Response;
use App\Security\Csrf;

require __DIR__ . '/bootstrap.php';

$searchCode = trim((string) ($_GET['search'] ?? ''));

try {
    $sql = 'SELECT * FROM ophaalbevestigingen';
    $parameters = [];

    if ($searchCode !== '') {
        $sql .= ' WHERE ophaalcode LIKE :search';
        $parameters['search'] = sprintf('%%%s%%', $searchCode);
    }

    $sql .= ' ORDER BY created_at DESC';

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $bevestigingen = $statement->fetchAll();
} catch (\PDOException $exception) {
    Response::error('Kon de lijst met ophaalbevestigingen niet ophalen.', 500);
}
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

  <form method="GET" class="search-bar">
    <label for="search">Zoek op ophaalcode:</label>
    <input type="text" name="search" id="search" value="<?= htmlspecialchars($searchCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
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
      <?php if (empty($bevestigingen)): ?>
        <tr>
          <td colspan="7">Geen resultaten gevonden.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($bevestigingen as $bev): ?>
          <tr>
            <td><?= (int) $bev['id'] ?></td>
            <td><?= htmlspecialchars((string) $bev['klantnaam'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $bev['merkmodel'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $bev['ophaalcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string) $bev['datumgereed'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
            <td>
              <?php if (($bev['status'] ?? '') === 'opgehaald'): ?>
                <span class="status-opgehaald">Opgehaald</span>
              <?php else: ?>
                <span class="status-klaar">Klaar</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (($bev['status'] ?? '') === 'klaar'): ?>
                <form action="pickup-sign.php" method="GET">
                  <input type="hidden" name="id" value="<?= (int) $bev['id'] ?>">
                  <button type="submit">Ophalen</button>
                </form>
              <?php else: ?>
                 <a href="generate-apparaat-opgehaald.php?id=<?= (int) $bev['id'] ?>" target="_blank">Bekijk PDF</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
