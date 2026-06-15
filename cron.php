<?php
declare(strict_types=1);

/**
 * CompetitorWatch — Cron entrypoint (CLI ONLY)
 *
 * Crontab (every 10 minutes — the asterisk-slash-10 pattern):
 *   *SLASH*10 * * * * /usr/bin/php /percorso/competitorwatch/cron.php
 *   (sostituisci *SLASH*10 con asterisco barra 10 — vedi README)
 *
 * The CLI invocation bypasses Apache and therefore Basic Auth. By design.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cron.php è eseguibile solo da linea di comando.');
}

require __DIR__ . '/lib/bootstrap.php';
require_once CW_LIB . '/jobs.php';

// ── Lock: prevent overlapping runs ───────────────────────────────────────────
$lockFile = CW_DATA . '/cron.lock';
$lock = @fopen($lockFile, 'c');
if (!$lock) {
    // fopen fallito = problema di permessi/percorso su data/, non overlap
    cw_log('error', "CRON: impossibile aprire il lock {$lockFile}. Verifica che data/ sia scrivibile.");
    fwrite(STDERR, "Impossibile aprire il file di lock. data/ scrivibile?\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // Lock occupato = esecuzione precedente ancora attiva
    cw_log('warning', 'CRON SKIP: esecuzione precedente ancora attiva (overlap). Il giro precedente dura troppo.');
    echo "Cron già in esecuzione, esco.\n";
    fclose($lock);
    exit(0);
}

// ── Heartbeat marker for status page ─────────────────────────────────────────
$logsDir = CW_DATA . '/logs';
if (!is_dir($logsDir)) mkdir($logsDir, 0750, true);
file_put_contents($logsDir . '/last_cron.txt', date('c'), LOCK_EX);

// ── Run ───────────────────────────────────────────────────────────────────────
$start = microtime(true);
cw_log('info', '── CRON START ──');

try {
    jobs_schedule();
    $processed = jobs_process(10, 2);
    $elapsed = round(microtime(true) - $start, 1);

    // Stato coda dopo il giro, per capire se resta arretrato
    $q = jobs_queue_status();
    $msg = "CRON END — {$processed} job in {$elapsed}s — coda: {$q['pending']} pending, {$q['running']} running, {$q['failed']} failed";
    cw_log('info', $msg);
    echo date('c') . " — {$msg}\n";

    // Allarme se il giro si avvicina al limite di sovrapposizione (cron ogni 10 min = 600s)
    if ($elapsed > 480) {
        cw_log('warning', "CRON LENTO: il giro è durato {$elapsed}s, vicino all'intervallo di 600s. Rischio sovrapposizione.");
    }
} catch (\Throwable $e) {
    cw_log('error', 'CRON EXCEPTION: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo "ERRORE: " . $e->getMessage() . "\n";
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
