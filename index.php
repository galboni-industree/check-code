<?php
require __DIR__ . '/lib/bootstrap.php';
require_auth();
require_once CW_LIB . '/providers.php';
require_once CW_LIB . '/llm.php';

$pageTitle = 'Dashboard';

$competitors = db_find('competitors', ['archived' => false], 0, 0, 'name', 'asc');
$compOnly    = array_filter($competitors, fn($c) => empty($c['is_own']));
$handles     = db_find('handles', ['active' => true]);
$month       = date('Y-m');

$compNames = [];
foreach (db_find('competitors') as $c) $compNames[(int)$c['_id']] = (string)$c['name'];

$recentAlerts = db_find('alerts', ['read' => false], 6, 0, '_created_at', 'desc');

$board = [];
foreach ($competitors as $c) {
    $m = comparative_metrics((int)$c['_id'], 60, null);
    if (!$m['has_data']) continue;
    $board[] = ['name' => (string)$c['name'], 'is_own' => !empty($c['is_own']),
                'er' => $m['avg_er'], 'posts' => $m['posts'], 'delta' => $m['follower_delta']];
}
usort($board, fn($a, $b) => $b['er'] <=> $a['er']);
$board = array_slice($board, 0, 6);

// Top post: prova il mese corrente, se vuoto ricade sul mese precedente
$recentPosts = db_find_partitioned('posts', [], 6, 0, 'likes', 'desc', $month, $month);
if (!$recentPosts) {
    $prevM = date('Y-m', strtotime('first day of last month'));
    $recentPosts = db_find_partitioned('posts', [], 6, 0, 'likes', 'desc', $prevM, $prevM);
    $topPostMonth = $prevM;
} else {
    $topPostMonth = $month;
}
$recentReports = db_find('monthly_reports', [], 4, 0, 'generated_at', 'desc');

$cronMarker = CW_DATA . '/logs/last_cron.txt';
$lastCron   = file_exists($cronMarker) ? trim((string)file_get_contents($cronMarker)) : null;
$cronStale  = $lastCron ? ((time() - strtotime($lastCron)) > 3600 * 2) : true;

// Trasparenza fetch: ultimo esito + nuovi post recenti
$lastFetch    = fetch_log_most_recent();
$newPosts7d   = posts_collected_since(7);
$jobsFailed = db_count('jobs', ['status' => 'failed']);
$llmOn = config('llm.enabled', true);

$spentLlm = 0.0;
foreach (db_find('llm_calls', ['month' => $month]) as $c) $spentLlm += (float)($c['cost_eur'] ?? 0);
$spentApify = apify_monthly_spent();

// Periodo di spesa: dal 1° del mese al reset (1° del mese prossimo)
$periodStart = date('j/n');                                  // oggi è solo riferimento; mostriamo l'inizio mese
$monthStart  = date('1/n');                                  // es. "1/6"
$nextReset   = strtotime('first day of next month midnight');
$daysToReset = (int)ceil(($nextReset - time()) / 86400);
$spendNote   = "dal {$monthStart} · reset tra {$daysToReset} " . ($daysToReset === 1 ? 'giorno' : 'giorni');

$chartData = [];
$cutoff = date('Y-m-d', strtotime('-30 days'));
foreach ($handles as $h) {
    $rows = db_find('metrics_daily', ['handle_id' => (int)$h['_id']]);
    $rows = array_filter($rows, fn($r) => ($r['date'] ?? '') >= $cutoff);
    if (count($rows) < 2) continue;
    usort($rows, fn($a, $b) => strcmp((string)$a['date'], (string)$b['date']));
    $chartData[] = [
        'label' => ($compNames[(int)$h['competitor_id']] ?? '?') . ' / ' . platform_label((string)$h['platform']),
        'data'  => array_map(fn($r) => ['x' => $r['date'], 'y' => (int)$r['followers']], array_values($rows)),
    ];
}

include CW_VIEWS . '/layout-header.php';
?>

<h1>Dashboard</h1>

<div class="kpi-row">
  <div class="kpi"><span class="kpi-value"><?= e(count($compOnly)) ?></span><span class="kpi-label">Concorrenti monitorati</span></div>
  <div class="kpi"><span class="kpi-value"><?= e(count($handles)) ?></span><span class="kpi-label">Profili attivi</span></div>
  <div class="kpi <?= count($recentAlerts) ? 'kpi-good' : '' ?>"><span class="kpi-value"><?= e(count($recentAlerts)) ?></span><span class="kpi-label">Alert da leggere</span></div>
  <div class="kpi"><span class="kpi-value"><?= e(fmt_eur($spentApify)) ?></span><span class="kpi-label">Spesa Apify mese</span><span class="kpi-sub"><?= e($spendNote) ?></span></div>
  <div class="kpi"><span class="kpi-value"><?= e(fmt_eur($spentLlm)) ?></span><span class="kpi-label">Spesa LLM mese</span><span class="kpi-sub"><?= e($spendNote) ?></span></div>
</div>

<?php if ($cronStale || $jobsFailed || !$llmOn): ?>
<div class="flash flash-<?= $cronStale ? 'error' : 'info' ?>">
  <?php
    $notes = [];
    if ($cronStale)   $notes[] = '⚠️ Il cron non gira da oltre 2 ore';
    if ($jobsFailed)  $notes[] = "{$jobsFailed} job falliti (vedi Stato)";
    if (!$llmOn)      $notes[] = '🔴 Chiamate LLM sospese: i report non verranno generati';
    echo e(implode(' · ', $notes));
  ?>
</div>
<?php endif; ?>

<section class="card fetch-status">
  <h2>Stato aggiornamenti</h2>
  <?php if ($lastFetch): ?>
    <p class="fetch-line">
      <span class="dot dot-ok"></span>
      Ultimo aggiornamento riuscito: <strong><?= e(platform_label((string)$lastFetch['platform'])) ?></strong>
      <?= e(time_ago((string)$lastFetch['finished_at'])) ?>
      — <?= e((int)$lastFetch['handled']) ?> profili, <?= e((int)$lastFetch['posts']) ?> nuovi post<?php
      if (!empty($lastFetch['missing'])): ?> <span class="muted">(senza dati: <?= e(implode(', ', (array)$lastFetch['missing'])) ?>)</span><?php endif; ?>
    </p>
  <?php else: ?>
    <p class="muted"><span class="dot dot-wait"></span> Nessun aggiornamento ancora registrato. Il primo fetch partirà al prossimo giro programmato (<?= e(next_cron_estimate()) ?>) o puoi avviarlo da Sistema.</p>
  <?php endif; ?>
  <p class="fetch-sub"><?= $newPosts7d > 0
    ? '📥 <strong>' . e($newPosts7d) . '</strong> nuovi post raccolti negli ultimi 7 giorni'
    : '<span class="muted">Nessun nuovo post negli ultimi 7 giorni.</span>' ?></p>
</section>

<div class="grid-2">
  <section class="card">
    <h2>Segnali sui concorrenti</h2>
    <?php if (!$recentAlerts): ?>
      <p class="muted">Nessun nuovo segnale. Gli alert compaiono quando un concorrente fa qualcosa di notevole: spike di engagement, nuovo formato, silenzio prolungato.</p>
    <?php else: ?>
    <ul class="list">
      <?php foreach ($recentAlerts as $a): ?>
        <li>
          <span><span class="tag"><?= e(alert_label((string)($a['type'] ?? ''))) ?></span>
          <?= e($a['message'] ?? '') ?></span>
          <span class="muted"><?= e(time_ago((string)($a['_created_at'] ?? ''))) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <p style="margin-top:12px"><a href="alerts.php" class="btn-sm">Tutti gli alert →</a></p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Classifica engagement rate <span class="muted">· ultimi 60gg</span></h2>
    <?php if (!$board): ?>
      <p class="muted">Ancora nessun dato. Comparirà dopo i primi fetch.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Brand</th><th>ER medio</th><th>Post</th><th>Δ Foll.</th></tr></thead>
      <tbody>
      <?php foreach ($board as $b): ?>
        <tr class="<?= $b['is_own'] ? 'row-own' : '' ?>">
          <td><?= $b['is_own'] ? '⭐ ' : '' ?><strong><?= e($b['name']) ?></strong></td>
          <td><strong><?= e(number_format((float)$b['er'], 3, ',', '.')) ?>%</strong></td>
          <td><?= e($b['posts']) ?></td>
          <td class="<?= $b['delta']>=0?'pos':'neg' ?>"><?= ($b['delta']>=0?'+':'').e(fmt_number($b['delta'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin-top:12px"><a href="compare.php" class="btn-sm">Confronto completo →</a></p>
    <?php endif; ?>
  </section>
</div>

<div class="grid-2">
  <section class="card">
    <h2>Top post <span class="muted">· <?= e($topPostMonth) ?></span></h2>
    <?php if (!$recentPosts): ?>
      <p class="muted">Nessun post raccolto questo mese.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Brand</th><th>Post</th><th class="num">Like</th><th class="num">Commenti</th><th class="num">Interazioni</th></tr></thead>
      <tbody>
      <?php foreach ($recentPosts as $p): ?>
        <tr>
          <td><strong><?= e($compNames[(int)$p['competitor_id']] ?? '?') ?></strong></td>
          <td class="trunc"><?= e(mb_substr((string)$p['text'], 0, 70)) ?></td>
          <td class="num"><?= e(fmt_number($p['likes'])) ?></td>
          <td class="num"><?= e(fmt_number($p['comments'])) ?></td>
          <td class="num"><strong><?= e(fmt_number(post_interactions($p))) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted">Interazioni = Mi piace + commenti + condivisioni + visualizzazioni. Le condivisioni sono disponibili su TikTok e Facebook (non su Instagram); le views contano per i video.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Ultimi report</h2>
    <?php if (!$recentReports): ?>
      <p class="muted">Nessun report. Generane uno da <a href="reports.php">Report</a>.</p>
    <?php else: ?>
    <ul class="list">
      <?php foreach ($recentReports as $r): ?>
      <li>
        <a href="report-view.php?id=<?= e((int)$r['_id']) ?>"><?= e($r['year_month']) ?> —
          <?= (int)$r['competitor_id'] === 0 ? 'Tutti i concorrenti' : e($compNames[(int)$r['competitor_id']] ?? '?') ?></a>
        <span class="muted"><?= ($r['status'] ?? '') === 'no_data' ? '<span class="tag">vuoto</span> ' : '' ?><?= e(time_ago((string)($r['generated_at'] ?? ''))) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</div>

<details class="accordion">
  <summary>Andamento follower — ultimi 30 giorni</summary>
  <div class="accordion-body">
    <?php if (!$chartData): ?>
      <p class="muted">Il grafico si popolerà man mano che vengono raccolte le metriche giornaliere dei follower (servono almeno 2 giorni di dati per profilo).</p>
    <?php else: ?>
      <div style="height:280px"><canvas id="followerChart"></canvas></div>
    <?php endif; ?>
  </div>
</details>

<?php if ($chartData): ?>
<script src="assets/chart.min.js"></script>
<script>
const cwChartData = <?= json_for_js($chartData) ?>;
const palette = ['#5b8def','#3fb98c','#e0a458','#e5687a','#9b7fe0','#4cc4d6','#d98ec0'];
new Chart(document.getElementById('followerChart'), {
  type: 'line',
  data: { datasets: cwChartData.map((d,i)=>({label:d.label,data:d.data,borderColor:palette[i%palette.length],borderWidth:2,tension:.3,pointRadius:0,pointHoverRadius:4})) },
  options: {
    parsing:{xAxisKey:'x',yAxisKey:'y'},
    scales:{
      x:{type:'category',grid:{color:'#23272f'},ticks:{color:'#6b7280',maxTicksLimit:6}},
      y:{grid:{color:'#23272f'},ticks:{color:'#6b7280'},beginAtZero:false}
    },
    plugins:{legend:{position:'bottom',labels:{color:'#9aa3b2',boxWidth:12,padding:14}}},
    interaction:{mode:'index',intersect:false},
    maintainAspectRatio:false
  }
});
</script>
<?php endif; ?>

<?php include CW_VIEWS . '/layout-footer.php'; ?>
