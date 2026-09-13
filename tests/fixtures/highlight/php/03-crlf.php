<?php
/** @var array<int, array{name: string, team: string, absences: int}> $members */
$title = 'Absence overview';
$members ??= [];
$dark = ($_COOKIE['theme'] ?? '') === 'dark';
$role = $_SESSION['role'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title) ?> &ndash; Example</title>
  <style>
    .warning { color: #b00020; font-weight: 600; }
    tr:nth-child(even) { background: rgb(0 0 0 / 4%); }
  </style>
</head>
<body class="<?php echo $dark ? 'dark' : 'light'; ?>">
  <h1><?= $title ?></h1>
  <?php if (count($members) === 0): ?>
    <p>No entries yet.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Name</th><th>Team</th><th>Days</th></tr></thead>
      <tbody>
      <?php foreach ($members as $i => $m): ?>
        <tr data-index="<?= $i ?>" class="<?= $m['absences'] > 10 ? 'warning' : '' ?>">
          <td><?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= "{$m['team']}" ?></td>
          <td><?= number_format($m['absences'], 0, '.', ',') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <button type="button" onclick="printPage()">Print</button>
  <script>
    const count = <?= json_encode(count($members)) ?>;
    function printPage() {
      if (count > 0 && confirm(`Print ${count} entries?`)) window.print();
    }
  </script>
<?php
// Footer only for admins — café hours
if ($role === 'admin') {
    include __DIR__ . '/footer.php';
} elseif ($role === "editor") {
    require_once __DIR__ . "/editor/footer.php";
}
?>
</body>
</html>
