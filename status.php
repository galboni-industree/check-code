<?php
require __DIR__ . '/lib/bootstrap.php';
require_auth();
require_once CW_LIB . '/jobs.php';

// Backup download + control panel actions (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input_str('action');

    if ($action === 'trigger_fetch') {
        $n = jobs_trigger_full_fetch();
        flash_set('success', "{$n} fetch accodati — verranno eseguiti " . next_cron_estimate() . ".");
        redirect('status.php');
    }
    if ($action === 'trigger_reports') {
        $ym = date('Y-m', strtotime('first day of last month'));
        $n = jobs_trigger_reports($ym);
        if (!config('llm.enabled', true)) {
            flash_set('info', "{$n} report ({$ym}) accodati, ma le chiamate LLM sono sospese: resteranno in attesa finché non riattivi l'LLM.");
        } else {
            flash_set('success', "{$n} report ({$ym}) accodati — verranno generati " . next_cron_estimate() . ".");
        }
        redirect('status.php');
    }
    if ($action === 'clear_queue') {
        $n = jobs_clear_pending();
        flash_set('success', "{$n} job rimossi dalla coda.");
        redirect('status.php');
    }
    if ($action === 'clear_failed') {
        $n = jobs_clear_failed();
        flash_set('success', "{$n} job falliti rimossi.");
        redirect('status.php');
    }
    if ($action === 'toggle_llm') {
        $cfg = $GLOBALS['cw_config'];
        $cfg['llm']['enabled'] = !config('llm.enabled', true);
        $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
        $tmp = CW_CONFIG . '/config.php.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) !== false && rename($tmp, CW_CONFIG . '/config.php')) {
            $stato = $cfg['llm']['enabled'] ? 'riattivate' : 'sospese';
            audit_log('toggle_llm', "Chiamate LLM {$stato}");
            flash_set('success', "Chiamate LLM {$stato}.");
        } else {
            flash_set('error', 'Impossibile aggiornare config.');
        }
        redirect('status.php');
    }
    if ($action === 'run_now') {
        $done = jobs_process(5, 1);
        flash_set('info', "{$done} job processati ora.");
        redirect('status.php');
    }

    if ($action === 'backup') {
        if (!class_exists('ZipArchive')) {
            flash_set('error', 'Estensione ZipArchive non disponibile su questo hosting.');
            redirect('status.php');
        }
        $tmpZip = sys_get_temp_dir() . '/cw_backup_' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE) !== true) {
            flash_set('error', 'Impossibile creare il backup.');
            redirect('status.php');
        }
        $root = CW_DATA . '/db';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = substr($file->getPathname(), strlen($root) + 1);
            $zip->addFile($file->getPathname(), 'db/' . $rel);
        }
        $zip->close();

        audit_log('backup_download', '');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="competitorwatch-backup-' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        unlink($tmpZip);   // never persisted server-side
        exit;
    }
    redirect('status.php');
}

$pageTitle = 'Stato sistema';

// Jobs overview
$jobsPending = db_find('jobs', ['status' => 'pending'], 10, 0, 'run_after', 'asc');
$jobsFailed  = db_find('jobs', ['status' => 'failed'], 10, 0, '_created_at', 'desc');
$jobsDone    = db_find('jobs', ['status' => 'done'], 5, 0, 'completed_at', 'desc');

// Cronologia esiti fetch (trasparenza)
$fetchHistory = db_find('fetch_log', [], 10, 0, 'finished_at', 'desc');

// Last cron run (from log marker)
$cronMarker = CW_DATA . '/logs/last_cron.txt';
$lastCron   = file_exists($cronMarker) ? trim((string)file_get_contents($cronMarker)) : null;
$cronStale  = $lastCron ? ((time() - strtotime($lastCron)) > 3600 * 2) : true;

// Storage health
$dbIssues = db_health_check();
$dbSize   = dir_size(CW_DATA . '/db');
$snapSize = dir_size(CW_DATA . '/snapshots');

// Audit (latest)
$auditEntries = audit_read(date('Y-m'), 15);

include CW_VIEWS . '/layout-header.php';
?>

<h1>Stato sistema</h1>

<section class="card">
  <h2>🎛️ Pannello di controllo</h2>
  <p class="muted">I trigger accodano i job: vengono eseguiti dal cron (o con "Esegui ora" per test). Niente timeout.</p>

  <?php $llmOn = config('llm.enabled', true); ?>
  <div class="llm-switch <?= $llmOn ? 'on' : 'off' ?>">
    <span>
      <strong>Chiamate LLM (report):</strong>
      <?= $llmOn ? '🟢 attive' : '🔴 sospese' ?>
      <?php if (!$llmOn): ?><br><span class="muted">I fetch e gli alert continuano gratis. I report restano in attesa finché non riattivi.</span><?php endif; ?>
    </span>
    <form method="post" onsubmit="return confirm('<?= $llmOn ? 'Sospendere tutte le chiamate LLM? I report non verranno generati finché non riattivi.' : 'Riattivare le chiamate LLM? I report in attesa verranno generati (costo LLM).' ?>');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_llm">
      <button type="submit" class="btn <?= $llmOn ? 'btn-danger' : '' ?>"><?= $llmOn ? 'Sospendi LLM' : 'Riattiva LLM' ?></button>
    </form>
  </div>

  <div class="control-grid">
    <form method="post" onsubmit="return confirm('Accodare il fetch di tutti gli handle attivi? Consuma credito Apify.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="trigger_fetch">
      <button type="submit" class="btn">▶ Fetch completo ora</button>
    </form>
    <form method="post" onsubmit="return confirm('Accodare i report del mese scorso? Consuma credito LLM.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="trigger_reports">
      <button type="submit" class="btn">📄 Genera report mese scorso</button>
    </form>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="run_now">
      <button type="submit" class="btn-sm">⚙ Esegui ora (test)</button>
    </form>
    <form method="post" onsubmit="return confirm('Svuotare la coda dei job pendenti?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_queue">
      <button type="submit" class="btn-sm btn-danger">🗑 Svuota coda</button>
    </form>
    <form method="post" onsubmit="return confirm('Rimuovere tutti i job falliti dallo storico?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="clear_failed">
      <button type="submit" class="btn-sm btn-danger">🧹 Pulisci job falliti</button>
    </form>
  </div>
</section>

<div class="kpi-row">
  <div class="kpi <?= $cronStale ? 'kpi-bad' : 'kpi-good' ?>">
    <span class="kpi-value"><?= $lastCron ? e(time_ago($lastCron)) : 'mai' ?></span>
    <span class="kpi-label">Ultimo cron</span>
  </div>
  <div class="kpi"><span class="kpi-value"><?= e(count($jobsPending)) ?></span><span class="kpi-label">Job in coda</span></div>
  <div class="kpi <?= $jobsFailed ? 'kpi-bad' : '' ?>"><span class="kpi-value"><?= e(count($jobsFailed)) ?></span><span class="kpi-label">Job falliti</span></div>
  <div class="kpi"><span class="kpi-value"><?= e(fmt_bytes($dbSize + $snapSize)) ?></span><span class="kpi-label">Spazio dati</span></div>
</div>

<?php if ($dbIssues): ?>
<section class="card flash-error">
  <h2>⚠️ Problemi storage</h2>
  <ul><?php foreach ($dbIssues as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>

<?php if ($cronStale): ?>
<section class="card flash-error">
  <h2>⚠️ Cron non attivo</h2>
  <p>Il cron non gira da più di 2 ore (o non è mai partito). Verifica il crontab:</p>
  <pre>*/10 * * * * /usr/bin/php /percorso/competitorwatch/cron.php</pre>
</section>
<?php endif; ?>

<section class="card">
  <h2>Cronologia aggiornamenti</h2>
  <?php if (!$fetchHistory): ?>
    <p class="muted">Nessun fetch ancora completato. Parte al prossimo giro programmato o con "Fetch completo ora".</p>
  <?php else: ?>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Quando</th><th>Piattaforma</th><th class="num">Profili</th><th class="num">Nuovi post</th><th>Senza dati</th></tr></thead>
      <tbody>
      <?php foreach ($fetchHistory as $f): ?>
        <tr>
          <td><?= e(time_ago((string)$f['finished_at'])) ?></td>
          <td><?= e(platform_icon((string)$f['platform'])) ?> <?= e(platform_label((string)$f['platform'])) ?></td>
          <td class="num"><?= e((int)$f['handled']) ?></td>
          <td class="num"><strong><?= e((int)$f['posts']) ?></strong></td>
          <td><?= !empty($f['missing']) ? '<span class="muted">' . e(implode(', ', (array)$f['missing'])) . '</span>' : '<span class="fresh-dot fresh"></span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<div class="grid-2">
  <section class="card">
    <h2>Job in coda</h2>
    <?php if (!$jobsPending): ?><p class="muted">Nessun job pendente.</p><?php else: ?>
    <table><thead><tr><th>Tipo</th><th>Quando</th><th class="num">Tent.</th></tr></thead><tbody>
    <?php $nowStr = date('Y-m-d\TH:i:s'); foreach ($jobsPending as $j):
      $ra = (string)($j['run_after'] ?? '');
      if ($ra <= $nowStr) { $when = '<span class="tag tag-on">pronto</span>'; }
      else { $when = '<span class="muted">' . e(fmt_datetime($ra)) . '</span>'; }
    ?>
      <tr><td><?= e(job_type_label((string)$j['type'])) ?></td><td><?= $when ?></td><td class="num"><?= e((int)($j['attempts'] ?? 0)) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <p class="muted" style="margin-top:10px">"pronto" = verrà eseguito al prossimo giro di cron. Una data = fetch programmato per quell'orario.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Job falliti</h2>
    <?php if (!$jobsFailed): ?><p class="muted">Nessun fallimento. 🎉</p><?php else: ?>
    <table><thead><tr><th>Tipo</th><th>Errore</th></tr></thead><tbody>
    <?php foreach ($jobsFailed as $j): ?>
      <tr><td><?= e(job_type_label((string)$j['type'])) ?></td><td class="trunc"><?= e($j['last_error'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table><?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>Audit log recente</h2>
  <?php if (!$auditEntries): ?><p class="muted">Nessuna attività registrata questo mese.</p><?php else: ?>
  <table><thead><tr><th>Quando</th><th>Utente</th><th>Azione</th><th>Dettaglio</th></tr></thead><tbody>
  <?php foreach ($auditEntries as $a): ?>
    <tr><td><?= e(fmt_datetime((string)$a['ts'])) ?></td><td><?= e($a['user']) ?></td><td><?= e($a['action']) ?></td><td class="trunc"><?= e($a['detail']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>

<section class="card">
  <h2>Backup</h2>
  <p class="muted">Genera e scarica uno zip della cartella dati. Il file non viene mai salvato sul server.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="backup">
    <button type="submit" class="btn">Scarica backup</button>
  </form>
</section>

<?php include CW_VIEWS . '/layout-footer.php'; ?>
