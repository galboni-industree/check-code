<?php
require __DIR__ . '/lib/bootstrap.php';
require_auth();

$pageTitle = 'Report';
$id     = input_int('id', 'GET');
$report = $id ? db_find_by_id('monthly_reports', $id) : null;

if (!$report) {
    flash_set('error', 'Report non trovato.');
    redirect('reports.php');
}

$compNames = [0 => 'Tutti i concorrenti'];
foreach (db_find('competitors') as $c) $compNames[(int)$c['_id']] = (string)$c['name'];
$scope = $compNames[(int)$report['competitor_id']] ?? '?';

/**
 * Minimal safe markdown renderer: headings, bold, lists, paragraphs.
 * Input is LLM output — escape EVERYTHING first, then add formatting.
 */
function md_render(string $md): string
{
    $out   = [];
    $inList = false;
    foreach (explode("\n", $md) as $line) {
        $line = e($line); // escape first — security boundary
        $trimmed = trim($line);

        if (preg_match('/^(#{1,4})\s+(.*)$/', $trimmed, $m)) {
            if ($inList) { $out[] = '</ul>'; $inList = false; }
            $level = min(4, strlen($m[1]) + 1); // h2..h5
            $out[] = "<h{$level}>{$m[2]}</h{$level}>";
            continue;
        }
        if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $m)) {
            if (!$inList) { $out[] = '<ul>'; $inList = true; }
            $out[] = '<li>' . $m[1] . '</li>';
            continue;
        }
        if ($inList) { $out[] = '</ul>'; $inList = false; }
        if ($trimmed === '') { continue; }
        $out[] = '<p>' . $trimmed . '</p>';
    }
    if ($inList) $out[] = '</ul>';

    $html = implode("\n", $out);
    // Bold (after escaping, ** are literal)
    $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html) ?? $html;
    return $html;
}

include CW_VIEWS . '/layout-header.php';
?>

<p><a href="reports.php">← Tutti i report</a></p>

<h1>Report <?= e($report['year_month']) ?> — <?= e($scope) ?></h1>
<p class="muted">
  Modello: <?= e($report['llm_model'] ?? '—') ?> ·
  Costo: <?= isset($report['cost_eur']) ? e(fmt_eur((float)$report['cost_eur'])) : '—' ?> ·
  Generato: <?= e(fmt_datetime((string)($report['generated_at'] ?? ''))) ?>
</p>

<section class="card report-body">
  <?= md_render((string)($report['summary_md'] ?? '')) ?>
</section>

<?php if (!empty($report['kpis']['follower_delta'])): ?>
<section class="card">
  <h2>KPI del mese</h2>
  <table>
    <thead><tr><th>Piattaforma</th><th>Follower inizio</th><th>Follower fine</th><th>Delta</th></tr></thead>
    <tbody>
    <?php foreach ($report['kpis']['follower_delta'] as $fd): ?>
      <tr>
        <td><?= e(platform_label((string)($fd['platform'] ?? ''))) ?></td>
        <td><?= e(fmt_number($fd['start'] ?? 0)) ?></td>
        <td><?= e(fmt_number($fd['end'] ?? 0)) ?></td>
        <td><?= ((int)($fd['delta'] ?? 0) >= 0 ? '+' : '') . e(fmt_number($fd['delta'] ?? 0)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php include CW_VIEWS . '/layout-footer.php'; ?>
