<?php
require __DIR__ . '/lib/bootstrap.php';
require_auth();

$pageTitle = 'Log';

$logsDir = CW_DATA . '/logs';
$files = glob($logsDir . '/*.log') ?: [];
rsort($files); // più recente primo

$current = input_str('file', 'GET');
// Difesa in profondità: rifiuta a monte qualsiasi sequenza di path traversal
if ($current !== '' && (str_contains($current, '..') || str_contains($current, '/') || str_contains($current, '\\'))) {
    $current = '';
}
// Sicurezza: accetta solo nomi file dentro logs/, niente path traversal
$selected = null;
foreach ($files as $f) {
    if (basename($f) === $current) { $selected = $f; break; }
}
if (!$selected && $files) $selected = $files[0];

$filter = input_str('q', 'GET'); // filtro testo opzionale

$lines = [];
if ($selected && file_exists($selected)) {
    $raw = file($selected, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $raw = array_reverse($raw); // più recente in cima
    foreach ($raw as $l) {
        if ($filter !== '' && stripos($l, $filter) === false) continue;
        $lines[] = $l;
    }
    $lines = array_slice($lines, 0, 500); // cap
}

// Heartbeat cron
$cronMarker = $logsDir . '/last_cron.txt';
$lastCron = file_exists($cronMarker) ? trim((string)file_get_contents($cronMarker)) : null;

include CW_VIEWS . '/layout-header.php';
?>

<h1>Log di sistema</h1>

<section class="card">
  <p class="muted">
    Ultimo cron: <strong><?= $lastCron ? e(time_ago($lastCron)) . ' (' . e(fmt_datetime($lastCron)) . ')' : 'mai' ?></strong>.
    I log mostrano inizio/fine di ogni job con durata, e avvisi su giri lenti o sovrapposti.
  </p>

  <form method="get" class="filter-bar">
    <label>File:
      <select name="file" onchange="this.form.submit()">
        <?php foreach ($files as $f): ?>
          <option value="<?= e(basename($f)) ?>" <?= $selected===$f?'selected':'' ?>><?= e(basename($f)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Filtro:
      <input type="text" name="q" value="<?= e($filter) ?>" placeholder="es. FAIL, CRON, fetch">
    </label>
    <button type="submit" class="btn-sm">Filtra</button>
    <?php foreach (['CRON','JOB START','FAIL','LENTO','overlap'] as $quick): ?>
      <a href="?file=<?= e(basename((string)$selected)) ?>&q=<?= e($quick) ?>" class="btn-sm"><?= e($quick) ?></a>
    <?php endforeach; ?>
  </form>
</section>

<section class="card">
  <?php if (!$files): ?>
    <p class="muted">Nessun file di log ancora. Comparirà dopo il primo giro di cron o job.</p>
  <?php elseif (!$lines): ?>
    <p class="muted">Nessuna riga<?= $filter ? ' per il filtro "' . e($filter) . '"' : '' ?>.</p>
  <?php else: ?>
    <p class="muted"><?= e(count($lines)) ?> righe (max 500, più recenti in cima)</p>
    <pre class="logview"><?php foreach ($lines as $l) {
        $cls = '';
        if (stripos($l, 'FAIL') !== false || stripos($l, 'ERROR') !== false) $cls = 'log-err';
        elseif (stripos($l, 'WARNING') !== false || stripos($l, 'LENTO') !== false || stripos($l, 'overlap') !== false) $cls = 'log-warn';
        elseif (stripos($l, 'CRON') !== false) $cls = 'log-cron';
        echo '<span class="' . $cls . '">' . e($l) . "</span>\n";
    } ?></pre>
  <?php endif; ?>
</section>

<?php include CW_VIEWS . '/layout-footer.php'; ?>
