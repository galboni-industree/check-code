<?php
/**
 * fix-jobs.php — Pulizia una tantum dei job bloccati.
 *
 * USO: carica questo file nella cartella competitorwatch/ via FTP,
 *      aprilo nel browser UNA VOLTA (es. https://tuosito/competitorwatch/fix-jobs.php),
 *      poi CANCELLALO via FTP.
 *
 * Cosa fa:
 *  - Sblocca i job "running" rimasti zombie (li rimette pending)
 *  - Rimuove i monthly_report pending duplicati per lo stesso mese/competitor
 *  - Mostra un riepilogo
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_auth(); // protetto da Basic Auth come il resto

header('Content-Type: text/plain; charset=utf-8');

echo "== Pulizia job CompetitorWatch ==\n\n";

// 1. Sblocca tutti i "running" → pending
$running = db_find('jobs', ['status' => 'running']);
$unstuck = 0;
foreach ($running as $j) {
    db_update('jobs', (int)$j['_id'], [
        'status' => 'pending',
        'run_after' => date('Y-m-d\TH:i:s'),
        'last_error' => 'Sbloccato manualmente da fix-jobs',
    ]);
    $unstuck++;
}
echo "Job 'running' sbloccati: {$unstuck}\n";

// 2. Dedup monthly_report pending: tieni solo 1 per (year_month, competitor_id)
$seen = [];
$removed = 0;
foreach (db_find('jobs', ['type' => 'monthly_report']) as $j) {
    if (($j['status'] ?? '') !== 'pending') continue;
    $key = ($j['year_month'] ?? '') . ':' . ($j['competitor_id'] ?? '');
    if (isset($seen[$key])) {
        db_delete('jobs', (int)$j['_id']);
        $removed++;
    } else {
        $seen[$key] = true;
    }
}
echo "Report mensili duplicati rimossi: {$removed}\n";

// 3. Riepilogo stato coda
$status = ['pending'=>0,'running'=>0,'done'=>0,'failed'=>0];
foreach (db_find('jobs') as $j) {
    $s = $j['status'] ?? '?';
    if (isset($status[$s])) $status[$s]++;
}
echo "\nStato coda attuale:\n";
foreach ($status as $s => $n) echo "  {$s}: {$n}\n";

echo "\n== Fatto. ORA CANCELLA questo file (fix-jobs.php) via FTP. ==\n";
