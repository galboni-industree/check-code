<?php
require __DIR__ . '/lib/bootstrap.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (input_str('action') === 'backfill') {
        $n = monthly_stats_backfill();
        flash_set('success', "Storico ricostruito: {$n} snapshot mensili aggiornati.");
    }
    redirect('compare.php');
}

$pageTitle = 'Confronto';

$platform = input_str('platform', 'GET');
if (!in_array($platform, PLATFORMS, true)) $platform = '';

// Piattaforme effettivamente presenti tra gli handle attivi
$activePlatforms = [];
foreach (db_find('handles', ['active' => true]) as $hh) {
    $activePlatforms[(string)$hh['platform']] = true;
}
$activePlatforms = array_values(array_intersect(PLATFORMS, array_keys($activePlatforms)));

// Confronto intra-piattaforma: se l'utente non ha scelto, usa la prima con dati.
// Evita di mischiare metriche non comparabili tra piattaforme diverse.
if ($platform === '' && $activePlatforms) {
    $platform = $activePlatforms[0];
}
$plat = $platform ?: null;
$platKey = $platform ?: 'all';

// Competitor attivi: brand proprio per primo
$all = db_find('competitors', ['archived' => false], 0, 0, 'name', 'asc');
usort($all, fn($a, $b) => (int)!empty($b['is_own']) <=> (int)!empty($a['is_own']));
$ownId = 0;
foreach ($all as $c) if (!empty($c['is_own'])) $ownId = (int)$c['_id'];

// ── Raccogli serie storiche mensili per ogni competitor ──
$histories = [];           // cid => [ ym => stats ]
$allMonths = [];
foreach ($all as $c) {
    $cid = (int)$c['_id'];
    $series = monthly_stats_series($cid, $plat);
    $byMonth = [];
    foreach ($series as $s) { $byMonth[(string)$s['year_month']] = $s; $allMonths[(string)$s['year_month']] = true; }
    $histories[$cid] = $byMonth;
}
ksort($allMonths);
$months = array_keys($allMonths);              // tutti i mesi con dati, cronologici

// Mese corrente per la leaderboard = ultimo mese che ha dati reali (non vuoto)
$monthsWithData = [];
foreach ($months as $ym) {
    foreach ($all as $c) {
        if (!empty($histories[(int)$c['_id']][$ym]['has_data'])) { $monthsWithData[] = $ym; break; }
    }
}
$lastMonths = array_slice($monthsWithData, -12);  // ultimi 12 mesi CON dati, per i grafici
$curMonth  = end($monthsWithData) ?: (end($months) ?: date('Y-m'));
$prevIdx   = array_search($curMonth, $monthsWithData, true);
$prevMonth = ($prevIdx !== false && $prevIdx > 0) ? $monthsWithData[$prevIdx - 1] : null;

// Modalità grafici: con meno di 3 mesi di storico, il grafico mensile mostrerebbe
// 1-2 punti (inutile). In quel caso mostriamo l'andamento GIORNALIERO dei follower,
// dai dati raccolti finora. Il mensile (ER, share of voice) appare con più storico.
// Grafico follower: SEMPRE denso (ogni rilevazione giornaliera), così anche con
// pochi giorni di dati si vede l'andamento reale invece di 1-2 punti mensili.
// ER e Share of voice restano mensili (metriche per-periodo) e appaiono con ≥2 mesi.
$showMonthlyMetrics = (count($monthsWithData) >= 2);
$platComps = array_filter($all, function($c) use ($plat) {
    $hf = ['competitor_id' => (int)$c['_id'], 'active' => true];
    if ($plat) $hf['platform'] = $plat;
    return !empty(db_find('handles', $hf));
});
$dailySeries = comparative_timeseries(array_values($platComps), 120, $plat);

// Variazione follower nel periodo rilevato, per ogni serie (così è leggibile
// anche quando il grafico appare piatto per via della scala)
$dailyDeltas = [];
foreach ($dailySeries as $s) {
    $pts = $s['data'];
    if (count($pts) < 1) continue;
    $first = (int)$pts[0]['y'];
    $last  = (int)end($pts)['y'];
    $diff  = $last - $first;
    $days  = count($pts);
    $dailyDeltas[] = [
        'label' => $s['label'], 'is_own' => $s['is_own'],
        'last' => $last, 'diff' => $diff, 'days' => $days,
        'first_date' => $pts[0]['x'] ?? '', 'last_date' => end($pts)['x'] ?? '',
    ];
}

// Nome competitor
$cname = [];
foreach ($all as $c) $cname[(int)$c['_id']] = (string)$c['name'];

/** variazione % tra due valori */
function pct_change(float $now, float $prev): ?float {
    if ($prev == 0.0) return null;
    return round((($now - $prev) / $prev) * 100, 1);
}
/** badge trend freccia */
function trend_badge(?float $pct): string {
    if ($pct === null) return '<span class="muted">—</span>';
    if ($pct > 0)  return '<span class="vsown pos">▲ ' . number_format($pct,1,',','.') . '%</span>';
    if ($pct < 0)  return '<span class="vsown neg">▼ ' . number_format(abs($pct),1,',','.') . '%</span>';
    return '<span class="muted">=</span>';
}
/** badge confronto col brand proprio: "+40% vs te" / "-15% vs te" */
function vsown_badge(?float $pct): string {
    if ($pct === null) return '';
    $cls = $pct >= 0 ? 'pos' : 'neg';
    $sign = $pct >= 0 ? '+' : '−';
    return '<span class="vs-ref ' . $cls . '">' . $sign . number_format(abs($pct),0,',','.') . '% vs te</span>';
}

// ── Leaderboard mese corrente con Δ vs mese precedente ──
// Valori del brand di riferimento nel mese corrente (per il confronto relativo)
$ownCur = $ownId ? ($histories[$ownId][$curMonth] ?? null) : null;
$ownFollowers = $ownCur ? (int)$ownCur['followers'] : 0;
$ownPosts     = $ownCur ? (int)$ownCur['posts'] : 0;
$ownEr        = $ownCur ? (float)$ownCur['avg_er'] : 0.0;

$board = [];
foreach ($all as $c) {
    $cid = (int)$c['_id'];
    $cur = $histories[$cid][$curMonth] ?? null;
    if (!$cur || empty($cur['has_data'])) continue;
    $prev = $prevMonth ? ($histories[$cid][$prevMonth] ?? null) : null;
    $isOwn = ((int)$cid === $ownId);
    $board[] = [
        'id' => $cid, 'name' => $cname[$cid], 'is_own' => $isOwn,
        'followers' => (int)$cur['followers'],
        'foll_growth' => (int)$cur['follower_growth'],
        'posts' => (int)$cur['posts'],
        'engagement' => (int)$cur['engagement'],
        'avg_er' => (float)$cur['avg_er'],
        'd_followers' => $prev ? pct_change((float)$cur['followers'], (float)$prev['followers']) : null,
        'd_posts'     => $prev ? pct_change((float)$cur['posts'], (float)$prev['posts']) : null,
        'd_er'        => $prev ? pct_change((float)$cur['avg_er'], (float)$prev['avg_er']) : null,
        // Confronto relativo col brand proprio (vuoto per il brand stesso)
        'vs_followers' => (!$isOwn && $ownFollowers) ? pct_change((float)$cur['followers'], (float)$ownFollowers) : null,
        'vs_posts'     => (!$isOwn && $ownPosts)     ? pct_change((float)$cur['posts'], (float)$ownPosts) : null,
        'vs_er'        => (!$isOwn && $ownEr)        ? pct_change((float)$cur['avg_er'], (float)$ownEr) : null,
    ];
}
$sortBy = input_str('sort', 'GET') ?: 'avg_er';
$valid = ['name','followers','posts','engagement','avg_er'];
if (!in_array($sortBy, $valid, true)) $sortBy = 'avg_er';

// Il brand proprio è assente da questa piattaforma? (per avviso esplicito)
$ownHasHandle = false;
if ($ownId) {
    foreach (db_find('handles', ['competitor_id' => $ownId, 'active' => true]) as $oh) {
        if (!$plat || (string)$oh['platform'] === $platform) { $ownHasHandle = true; break; }
    }
}
$ownInBoard = false;
foreach ($board as $b) if (!empty($b['is_own'])) { $ownInBoard = true; break; }
usort($board, function ($a, $b) use ($sortBy) {
    if ($a['is_own'] !== $b['is_own']) return $a['is_own'] ? -1 : 1;
    if ($sortBy === 'name') return strcmp($a['name'], $b['name']);
    return ($b[$sortBy] ?? 0) <=> ($a[$sortBy] ?? 0);
});

// ── Share of voice: % post sul totale settore, per mese ──
$sovSeries = []; // cid => [ym => pct]
foreach ($lastMonths as $ym) {
    $totalPosts = 0;
    foreach ($all as $c) $totalPosts += (int)($histories[(int)$c['_id']][$ym]['posts'] ?? 0);
    foreach ($all as $c) {
        $cid = (int)$c['_id'];
        $p = (int)($histories[$cid][$ym]['posts'] ?? 0);
        $sovSeries[$cid][$ym] = $totalPosts > 0 ? round($p / $totalPosts * 100, 1) : 0;
    }
}

$palette = ['#5b8def','#3fb98c','#e0a458','#e5687a','#9b7fe0','#4cc4d6','#d98ec0','#8fb3f5','#7fdcb6','#e0c07a','#d9a441'];

include CW_VIEWS . '/layout-header.php';
?>

<h1>Confronto concorrenti</h1>

<?php if (count($activePlatforms) > 1): ?>
<nav class="platform-tabs">
  <?php foreach ($activePlatforms as $p): ?>
    <a href="compare.php?platform=<?= e($p) ?>" class="ptab <?= $platform===$p?'active':'' ?>">
      <?= e(platform_icon($p)) ?> <?= e(platform_label($p)) ?>
    </a>
  <?php endforeach; ?>
</nav>
<p class="muted">Confronto per piattaforma: le metriche (es. condivisioni, views) variano tra social, quindi si confronta una piattaforma alla volta.</p>
<?php endif; ?>

<?php if (!$months): ?>
  <section class="card"><p class="muted">Ancora nessun dato storico. Le statistiche mensili si popolano automaticamente a ogni giro di cron e cresceranno mese dopo mese. Torna dopo i primi fetch, oppure usa "Ricostruisci storico" qui sotto.</p>
  <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="backfill"><button class="btn-sm">Ricostruisci storico</button></form>
  </section>
<?php else: ?>

<!-- ════ LIVELLO 1+2: Leaderboard mese corrente con variazioni MoM ════ -->
<section class="card">
  <h2>Classifica <?= e($curMonth) ?>
    <?php if ($prevMonth): ?><span class="muted">· variazione vs <?= e($prevMonth) ?></span><?php endif; ?>
  </h2>
  <?php if ($ownId): ?><p class="muted">Il tuo brand è evidenziato in oro. Sotto ogni valore, il confronto relativo col tuo brand ("vs te"). Le frecce mostrano la variazione rispetto al mese precedente.</p><?php endif; ?>
  <?php if ($ownId && !$ownInBoard): ?>
    <p class="flash flash-info" style="margin:8px 0">
      <?php if (!$ownHasHandle): ?>
        ⓘ Il tuo brand non ha un profilo <strong><?= e(platform_label($platform)) ?></strong>: aggiungilo in Concorrenti per vederlo qui e attivare i confronti "vs te".
      <?php else: ?>
        ⓘ Il tuo brand non ha ancora dati su <strong><?= e(platform_label($platform)) ?></strong> per <?= e($curMonth) ?>. Compare dopo il primo fetch, oppure usa "Ricostruisci storico".
      <?php endif; ?>
    </p>
  <?php endif; ?>
  <div class="table-scroll">
  <table class="leaderboard">
    <thead><tr>
      <th>Brand</th>
      <th class="num">Follower</th><th class="hide-sm">Δ mese</th>
      <th class="num">Post</th><th class="hide-sm">Δ mese</th>
      <th class="num">ER medio</th><th class="hide-sm">Δ mese</th>
    </tr></thead>
    <tbody>
    <?php foreach ($board as $r): ?>
      <tr class="<?= $r['is_own'] ? 'row-own' : '' ?>">
        <td><?= $r['is_own'] ? '⭐ ' : '' ?><a href="competitors.php?id=<?= e($r['id']) ?>"><strong><?= e($r['name']) ?></strong></a></td>
        <td class="num"><?= e(fmt_number($r['followers'])) ?><?php if ($r['vs_followers'] !== null): ?><br><?= vsown_badge($r['vs_followers']) ?><?php endif; ?></td>
        <td class="hide-sm"><?= trend_badge($r['d_followers']) ?></td>
        <td class="num"><?= e($r['posts']) ?><?php if ($r['vs_posts'] !== null): ?><br><?= vsown_badge($r['vs_posts']) ?><?php endif; ?></td>
        <td class="hide-sm"><?= trend_badge($r['d_posts']) ?></td>
        <td class="num"><strong><?= e(number_format($r['avg_er'],3,',','.')) ?>%</strong><?php if ($r['vs_er'] !== null): ?><br><?= vsown_badge($r['vs_er']) ?><?php endif; ?></td>
        <td class="hide-sm"><?= trend_badge($r['d_er']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted">ER medio = engagement per post / follower: rende confrontabili account di dimensioni diverse.</p>
</section>

<!-- Andamento follower: sempre denso (ogni rilevazione) -->
<section class="card">
  <h2>Andamento follower <span class="muted">· ogni rilevazione</span></h2>
  <div style="height:320px"><canvas id="dailyFollowers"></canvas></div>
  <?php if ($dailyDeltas): ?>
    <p class="muted" style="margin-top:10px">Variazione nel periodo rilevato (gli account B2B crescono lentamente, quindi sul grafico le linee possono sembrare piatte):</p>
    <div class="table-scroll">
    <table>
      <thead><tr><th>Brand</th><th class="num">Follower attuali</th><th class="num">Variazione</th><th class="num">Giorni</th></tr></thead>
      <tbody>
      <?php foreach ($dailyDeltas as $d): ?>
        <tr class="<?= $d['is_own'] ? 'row-own' : '' ?>">
          <td><?= e($d['label']) ?></td>
          <td class="num"><?= e(fmt_number($d['last'])) ?></td>
          <td class="num <?= $d['diff'] > 0 ? 'pos' : ($d['diff'] < 0 ? 'neg' : '') ?>"><?= ($d['diff'] >= 0 ? '+' : '') . e(fmt_number($d['diff'])) ?></td>
          <td class="num"><?= e($d['days']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>
<?php if ($showMonthlyMetrics): ?>
<section class="card">
  <h2>Engagement rate — storico mensile</h2>
  <div style="height:300px"><canvas id="histEr"></canvas></div>
</section>
<section class="card">
  <h2>Share of voice <span class="muted">· quota di post sul totale settore</span></h2>
  <p class="muted">Quanto pesa ogni brand sul volume di pubblicazioni del settore, mese per mese.</p>
  <div style="height:300px"><canvas id="histSov"></canvas></div>
</section>
<?php else: ?>
<p class="muted" style="margin:4px 2px">Engagement rate e share of voice mensili appariranno con almeno 2 mesi di dati.</p>
<?php endif; ?>

<!-- ════ LIVELLO 1: Tabella storica completa (espandibile) ════ -->
<details class="accordion">
  <summary>Tabella storica completa (tutti i mesi)</summary>
  <div class="accordion-body">
    <div class="table-scroll">
    <table>
      <thead><tr><th>Brand</th><th>Mese</th><th class="num">Follower</th><th class="num">Δ Foll.</th><th class="num">Post</th><th class="num">Interazioni</th><th class="num">ER</th></tr></thead>
      <tbody>
      <?php foreach ($all as $c): $cid=(int)$c['_id']; foreach (array_reverse($histories[$cid]) as $ym => $s):
        if (empty($s['has_data'])) continue; ?>
        <tr class="<?= ((int)$cid===$ownId)?'row-own':'' ?>">
          <td><?= ((int)$cid===$ownId)?'⭐ ':'' ?><?= e($cname[$cid]) ?></td>
          <td><?= e($ym) ?></td>
          <td class="num"><?= e(fmt_number($s['followers'])) ?></td>
          <td class="num <?= ($s['follower_growth']>=0)?'pos':'neg' ?>"><?= ($s['follower_growth']>=0?'+':'').e(fmt_number($s['follower_growth'])) ?></td>
          <td class="num"><?= e($s['posts']) ?></td>
          <td class="num"><?= e(fmt_number($s['interactions'] ?? $s['engagement'] ?? 0)) ?></td>
          <td class="num"><?= e(number_format((float)$s['avg_er'],3,',','.')) ?>%</td>
        </tr>
      <?php endforeach; endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="muted"><strong>Interazioni</strong> = Mi piace + commenti + condivisioni + visualizzazioni. Le condivisioni sono disponibili su TikTok e Facebook, non su Instagram (che non le rende pubbliche). Confronta sempre dentro la stessa piattaforma.</p>
  </div>
</details>

<form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="backfill"><button class="btn-sm">Ricostruisci storico</button></form>

<script src="assets/chart.min.js"></script>
<script>
const PAL = <?= json_for_js($palette) ?>;

// ── Andamento follower: denso, ogni rilevazione ──
const dailyData = <?= json_for_js(array_map(function($s) {
    return ['label' => $s['label'], 'is_own' => $s['is_own'], 'points' => $s['data']];
}, $dailySeries)) ?>;

(function(){
  const el = document.getElementById('dailyFollowers');
  if (!el) return;
  if (!dailyData.length) { el.parentElement.innerHTML='<p class="muted">Ancora nessun dato follower raccolto su questa piattaforma.</p>'; return; }
  const allDates = [...new Set(dailyData.flatMap(d => d.points.map(p => p.x)))].sort();
  const fmt = s => { const [y,m,gg]=s.split('-'); return gg+'/'+m; };
  new Chart(el, {
    type: 'line',
    data: {
      labels: allDates,
      datasets: dailyData.map((d,i) => {
        const col = d.is_own ? '#d9a441' : PAL[i % PAL.length];
        const map = Object.fromEntries(d.points.map(p => [p.x, p.y]));
        return {
          label: d.label,
          data: allDates.map(dt => map[dt] ?? null),
          borderColor: col, backgroundColor: col + '22', fill: false,
          borderWidth: d.is_own ? 3 : 2,
          tension: .2, pointRadius: 4, pointHoverRadius: 6,
          pointBackgroundColor: col, spanGaps: true,
        };
      })
    },
    options: {
      maintainAspectRatio:false,
      scales:{
        x:{grid:{color:'#23272f'},ticks:{color:'#6b7280',maxRotation:0,autoSkip:allDates.length>12,callback:function(v){return fmt(this.getLabelForValue(v));}}},
        y:{grid:{color:'#23272f'},ticks:{color:'#6b7280'},beginAtZero:false}
      },
      plugins:{legend:{position:'bottom',labels:{color:'#9aa3b2',boxWidth:12,padding:12}}},
      interaction:{mode:'index',intersect:false}
    }
  });
})();

<?php if ($showMonthlyMetrics): ?>
// ── ER e Share of voice: mensili (≥2 mesi) ──
const MONTHS = <?= json_for_js($lastMonths) ?>;
const histData = <?= json_for_js(array_values(array_map(function($c) use ($histories, $lastMonths, $sovSeries, $cname, $ownId) {
    $cid = (int)$c['_id'];
    return [
        'name' => $cname[$cid] . ((int)$cid===$ownId ? ' ⭐' : ''),
        'is_own' => ((int)$cid===$ownId),
        'er'  => array_map(fn($ym) => (float)($histories[$cid][$ym]['avg_er'] ?? 0), $lastMonths),
        'sov' => array_map(fn($ym) => (float)($sovSeries[$cid][$ym] ?? 0), $lastMonths),
    ];
}, array_values(array_filter($all, function($c) use ($histories, $lastMonths) {
    $cid = (int)$c['_id'];
    foreach ($lastMonths as $ym) if (!empty($histories[$cid][$ym]['has_data'])) return true;
    return false;
}))))) ?>;

function lineChart(canvasId, key, opts={}) {
  const el = document.getElementById(canvasId);
  if (!el) return;
  new Chart(el, {
    type: 'line',
    data: { labels: MONTHS, datasets: histData.map((d,i) => ({
      label: d.name, data: d[key],
      borderColor: d.is_own ? '#d9a441' : PAL[i % PAL.length],
      borderWidth: d.is_own ? 3 : 1.8, tension: .3, pointRadius: 2, spanGaps: true,
    })) },
    options: {
      maintainAspectRatio:false,
      scales:{ x:{grid:{color:'#23272f'},ticks:{color:'#6b7280'}},
               y:{grid:{color:'#23272f'},ticks:{color:'#6b7280',callback:opts.pct?(v=>v+'%'):undefined},beginAtZero:opts.zero||false}},
      plugins:{legend:{position:'bottom',labels:{color:'#9aa3b2',boxWidth:12,padding:12}}},
      interaction:{mode:'index',intersect:false}
    }
  });
}
lineChart('histEr','er',{pct:true,zero:true});
lineChart('histSov','sov',{pct:true,zero:true});
<?php endif; ?>
</script>

<?php endif; ?>

<?php include CW_VIEWS . '/layout-footer.php'; ?>
