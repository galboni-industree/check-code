<?php
require __DIR__ . '/lib/bootstrap.php';
require_auth();
require_once CW_LIB . '/jobs.php';

// POST: generate report on demand
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (input_str('action') === 'generate') {
        $cid       = input_int('competitor_id');
        $yearMonth = input_str('year_month', 'POST', 7);
        if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            flash_set('error', 'Mese non valido (formato AAAA-MM).');
            redirect('reports.php');
        }
        // Accoda il job invece di eseguirlo in diretta (la chiamata LLM è lenta)
        $dup = db_exists('jobs', ['type' => 'monthly_report', 'competitor_id' => $cid,
                                  'year_month' => $yearMonth, 'status' => 'pending']);
        if ($dup) {
            flash_set('info', "Report {$yearMonth} già in coda, in attesa di generazione.");
        } elseif (!config('llm.enabled', true)) {
            db_insert('jobs', ['type' => 'monthly_report', 'competitor_id' => $cid,
                'year_month' => $yearMonth, 'status' => 'pending',
                'run_after' => date('Y-m-d\TH:i:s'), 'attempts' => 0]);
            flash_set('info', "Report {$yearMonth} accodato, ma le chiamate LLM sono sospese: resterà in attesa finché non le riattivi (in Stato).");
        } else {
            db_insert('jobs', ['type' => 'monthly_report', 'competitor_id' => $cid,
                'year_month' => $yearMonth, 'status' => 'pending',
                'run_after' => date('Y-m-d\TH:i:s'), 'attempts' => 0]);
            flash_set('success', "Report {$yearMonth} accodato — verrà generato " . next_cron_estimate() . ".");
        }
    }
    redirect('reports.php');
}

$pageTitle = 'Report';
$reports   = db_find('monthly_reports', [], 0, 0, 'year_month', 'desc');
$pag       = paginate($reports, 20);

$compNames = [0 => 'Tutti i concorrenti'];
foreach (db_find('competitors') as $c) $compNames[(int)$c['_id']] = (string)$c['name'];

include CW_VIEWS . '/layout-header.php';
?>

<h1>Report mensili</h1>

<section class="card">
  <h2>Genera report on-demand</h2>
  <form method="post" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="generate">
    <select name="competitor_id">
      <?php foreach ($compNames as $id => $name): ?>
        <option value="<?= e($id) ?>"><?= e($name) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="month" name="year_month" value="<?= e(date('Y-m', strtotime('first day of last month'))) ?>" required>
    <button type="submit" class="btn">Genera</button>
  </form>
  <p class="muted">La generazione usa le API LLM (costo stimato &lt; €0,05 per report). Il report viene accodato ed elaborato dal cron, senza attese nel browser.</p>
</section>

<section class="card">
  <?php if (!$pag['items']): ?>
    <p class="muted">Nessun report. Generane uno qui sopra o attendi il primo del mese.</p>
  <?php else: ?>
  <div class="table-scroll">
  <table>
    <thead><tr><th>Mese</th><th>Ambito</th><th>Stato</th><th class="hide-sm">Modello</th><th class="hide-sm num">Costo</th><th class="hide-sm">Generato</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($pag['items'] as $r): ?>
      <?php $st = $r['status'] ?? ''; ?>
      <tr>
        <td><?= e($r['year_month']) ?></td>
        <td><?= (int)$r['competitor_id'] === 0 ? 'Tutti i concorrenti' : e($compNames[(int)$r['competitor_id']] ?? '?') ?></td>
        <td><span class="tag <?= $st === 'done' ? 'tag-on' : 'tag-off' ?>"><?= $st === 'done' ? 'pronto' : ($st === 'no_data' ? 'nessun dato' : e($st)) ?></span></td>
        <td class="hide-sm"><?= $st === 'no_data' ? '<span class="muted">—</span>' : e($r['llm_model'] ?? '—') ?></td>
        <td class="hide-sm num"><?= isset($r['cost_eur']) && (float)$r['cost_eur'] > 0 ? e(fmt_eur((float)$r['cost_eur'])) : '<span class="muted">—</span>' ?></td>
        <td class="hide-sm"><?= e(fmt_datetime((string)($r['generated_at'] ?? ''))) ?></td>
        <td><a href="report-view.php?id=<?= e((int)$r['_id']) ?>" class="btn-sm">Apri →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($pag['pages'] > 1): ?>
  <nav class="pagination">
    <?php for ($i = 1; $i <= $pag['pages']; $i++): ?>
      <a href="?page=<?= e($i) ?>" class="<?= $i === $pag['page'] ? 'active' : '' ?>"><?= e($i) ?></a>
    <?php endfor; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>

<?php include CW_VIEWS . '/layout-footer.php'; ?>
