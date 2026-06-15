<?php
declare(strict_types=1);

/**
 * CompetitorWatch — Installer (one page, self-disabling)
 *
 * Security: refuses to run if config/config.php already exists.
 * Attempts self-delete after successful install.
 */

define('CW_ROOT',   __DIR__);
define('CW_CONFIG', __DIR__ . '/config');
define('CW_DATA',   __DIR__ . '/data');

// ── Inert if already installed ────────────────────────────────────────────────
if (file_exists(CW_CONFIG . '/config.php')) {
    http_response_code(403);
    exit('<h1>Già installato</h1><p>CompetitorWatch è già configurato. '
       . 'Per reinstallare, elimina <code>config/config.php</code> via FTP '
       . '(perderai la configurazione, non i dati).</p>'
       . '<p><a href="index.php">Vai alla dashboard →</a></p>');
}

// ── Pre-flight checks ─────────────────────────────────────────────────────────
function check_row(string $label, bool $ok, bool $critical, string $hint = ''): array
{
    return ['label' => $label, 'ok' => $ok, 'critical' => $critical, 'hint' => $hint];
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$server  = strtolower($_SERVER['SERVER_SOFTWARE'] ?? '');
$isApache = str_contains($server, 'apache') || str_contains($server, 'litespeed');

$checks = [
    check_row('PHP ≥ 8.2 (attuale: ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80200, true,
        'Cambia versione PHP dal pannello hosting.'),
    check_row('Estensione cURL', extension_loaded('curl'), true,
        'Chiedi al supporto hosting di attivare curl.'),
    check_row('Estensione JSON', extension_loaded('json'), true, ''),
    check_row('Estensione mbstring', extension_loaded('mbstring'), false,
        'Non bloccante: l\'app include un polyfill. Attivarla migliora le prestazioni.'),
    check_row('Funzione random_bytes', function_exists('random_bytes'), true, ''),
    check_row('Cartella data/ scrivibile', is_dir(CW_DATA) && is_writable(CW_DATA), true,
        'Via FTP: click destro su data/ → Permessi → 755 (o 775).'),
    check_row('Cartella config/ scrivibile', is_dir(CW_CONFIG) && is_writable(CW_CONFIG), true,
        'Via FTP: click destro su config/ → Permessi → 755 (o 775).'),
    check_row('HTTPS attivo', $isHttps, false,
        'Fortemente consigliato: Basic Auth senza HTTPS espone la password.'),
    check_row('Web server Apache/LiteSpeed (rilevato: ' . ($server ?: 'sconosciuto') . ')', $isApache, false,
        'Su nginx i file .htaccess NON funzionano: le cartelle data/ e config/ vanno protette con regole nginx (vedi README).'),
    check_row('Estensione ZipArchive (per i backup)', class_exists('ZipArchive'), false,
        'Senza ZipArchive il pulsante backup non funzionerà.'),
];

$allCriticalOk = true;
foreach ($checks as $c) {
    if ($c['critical'] && !$c['ok']) { $allCriticalOk = false; break; }
}

// ── Handle POST: create config ────────────────────────────────────────────────
$installed = false;
$installError = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $allCriticalOk) {
    $tzInput  = trim((string)($_POST['timezone'] ?? 'Europe/Rome'));
    $timezone = in_array($tzInput, timezone_identifiers_list(), true) ? $tzInput : 'Europe/Rome';

    $budget = (int)($_POST['budget'] ?? 30);
    if ($budget < 1 || $budget > 1000) $budget = 30;

    $config = [
        'app_name'        => 'CompetitorWatch',
        'timezone'        => $timezone,
        'retention_months'=> 3,
        'llm' => [
            'primary'  => 'claude',
            'fallback' => ['openai', 'gemini'],
            'budget_eur_monthly' => $budget,
            'keys' => [
                'claude' => 'YOUR_KEY_HERE',
                'openai' => 'YOUR_KEY_HERE',
                'gemini' => 'YOUR_KEY_HERE',
            ],
        ],
        'apify' => [
            'token' => 'YOUR_APIFY_TOKEN',
            'max_items_per_fetch' => 12,
            'actors' => [
                'instagram' => 'apify~instagram-profile-scraper',
                'facebook'  => 'apify~facebook-pages-scraper',
                'youtube'   => 'streamers~youtube-scraper',
            ],
        ],
        'security' => [
            'session_lifetime'    => 3600,
            'csrf_token_lifetime' => 1800,
        ],
    ];

    $php = "<?php\nreturn " . var_export($config, true) . ";\n";
    $tmp = CW_CONFIG . '/config.php.tmp';

    if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, CW_CONFIG . '/config.php')) {
        $installError = 'Impossibile scrivere config/config.php — verifica i permessi.';
    } else {
        // Create data subdirectories
        foreach (['db', 'snapshots', 'logs'] as $d) {
            $path = CW_DATA . '/' . $d;
            if (!is_dir($path)) mkdir($path, 0750, true);
        }
        $installed = true;
        // Attempt self-delete (best effort; file is inert anyway once config exists)
        @unlink(__FILE__);
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Installazione — CompetitorWatch</title>
<style>
body{font-family:system-ui,sans-serif;max-width:680px;margin:40px auto;padding:0 16px;color:#1a1a1a}
h1{font-size:1.5rem}
table{width:100%;border-collapse:collapse;margin:16px 0}
td,th{padding:8px;border-bottom:1px solid #e5e5e5;text-align:left;font-size:.9rem}
.ok{color:#15803d}.ko{color:#b91c1c}.warn{color:#b45309}
.hint{font-size:.8rem;color:#666}
.btn{background:#1d4ed8;color:#fff;border:0;padding:10px 20px;border-radius:6px;font-size:1rem;cursor:pointer}
label{display:block;margin:12px 0}
input,select{padding:8px;border:1px solid #ccc;border-radius:4px;width:100%;max-width:300px;display:block;margin-top:4px}
.box{background:#f0fdf4;border:1px solid #bbf7d0;padding:16px;border-radius:8px}
.box-err{background:#fef2f2;border-color:#fecaca}
code{background:#f5f5f5;padding:2px 5px;border-radius:3px}
</style>
</head>
<body>
<h1>📊 CompetitorWatch — Installazione</h1>

<?php if ($installed): ?>
  <div class="box">
    <h2>✅ Installazione completata</h2>
    <p>Prossimi passi:</p>
    <ol>
      <li>Vai in <strong>Impostazioni</strong> e inserisci il token Apify e almeno una chiave LLM.</li>
      <li>Aggiungi i concorrenti e i loro handle.</li>
      <li>Configura il cron (vedi README).</li>
      <li>Verifica che la Basic Auth sia attiva (se vedi questa pagina senza che il browser ti abbia chiesto utente e password, NON lo è → README).</li>
    </ol>
    <p><a href="index.php">Vai alla dashboard →</a></p>
  </div>

<?php else: ?>

  <h2>Pre-flight check</h2>
  <p>Verifica che l'ambiente sia compatibile.</p>

  <table>
    <thead><tr><th>Controllo</th><th>Esito</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td>
          <?= htmlspecialchars($c['label']) ?>
          <?php if (!$c['ok'] && $c['hint']): ?>
            <div class="hint"><?= htmlspecialchars($c['hint']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($c['ok']): ?><span class="ok">✅ OK</span>
          <?php elseif ($c['critical']): ?><span class="ko">❌ Bloccante</span>
          <?php else: ?><span class="warn">⚠️ Avviso</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($installError): ?>
    <div class="box box-err"><?= htmlspecialchars($installError) ?></div>
  <?php endif; ?>

  <?php if ($allCriticalOk): ?>
    <h2>Configurazione iniziale</h2>
    <form method="post">
      <label>Fuso orario
        <select name="timezone">
          <option value="Europe/Rome" selected>Europe/Rome</option>
          <option value="UTC">UTC</option>
          <option value="Europe/London">Europe/London</option>
          <option value="Europe/Madrid">Europe/Madrid</option>
        </select>
      </label>
      <label>Budget LLM mensile (€)
        <input type="number" name="budget" value="30" min="1" max="1000">
      </label>
      <p class="hint">Le chiavi API si inseriscono dopo, dalla pagina Impostazioni.</p>
      <button type="submit" class="btn">Procedi all'installazione</button>
    </form>
  <?php else: ?>
    <div class="box box-err">
      <strong>Risolvi i controlli bloccanti (❌) per procedere.</strong>
      Dopo le correzioni, ricarica questa pagina.
    </div>
  <?php endif; ?>

<?php endif; ?>
</body>
</html>
