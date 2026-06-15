<?php
require __DIR__ . '/lib/bootstrap.php';
require_auth();
require_once CW_LIB . '/llm.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (input_str('action') === 'save') {
        $cfg = $GLOBALS['cw_config'];
        $form = input_str('form'); // quale form è stato inviato

        // Chiavi API / generale (form="keys")
        if ($form === 'keys') {
            foreach (['claude', 'openai', 'gemini'] as $m) {
                $k = input_str("key_{$m}", 'POST', 300);
                if ($k !== '') $cfg['llm']['keys'][$m] = $k;
            }
            $apify = input_str('apify_token', 'POST', 300);
            if ($apify !== '') $cfg['apify']['token'] = $apify;
            $budget = input_int('budget');
            if ($budget > 0 && $budget <= 1000) $cfg['llm']['budget_eur_monthly'] = $budget;
            $primary = input_str('llm_primary');
            if (in_array($primary, ['claude', 'openai', 'gemini'], true)) {
                $cfg['llm']['primary'] = $primary;
                $cfg['llm']['fallback'] = array_values(array_diff(['claude', 'openai', 'gemini'], [$primary]));
            }
            $retention = input_int('retention');
            if ($retention >= 1 && $retention <= 24) $cfg['retention_months'] = $retention;
            $maxItems = input_int('max_items');
            if ($maxItems >= 3 && $maxItems <= 50) $cfg['apify']['max_items_per_fetch'] = $maxItems;
        }

        // Pianificazione (form="schedule") — i checkbox valgono solo qui
        if ($form === 'schedule') {
            $ff = input_str('fetch_frequency');
            if (in_array($ff, ['daily','weekly'], true)) $cfg['schedule']['fetch_frequency'] = $ff;
            $fh = input_int('fetch_hour');
            if ($fh >= 0 && $fh <= 23) $cfg['schedule']['fetch_hour'] = $fh;
            $cfg['schedule']['report_monthly'] = (input_str('report_monthly') === '1');
            $cfg['schedule']['report_weekly']  = (input_str('report_weekly') === '1');
        }

        // Soglie alert (form="alerts") — il checkbox "enabled" vale solo qui
        if ($form === 'alerts') {
            $cfg['alerts']['enabled'] = (input_str('alerts_enabled') === '1');
            $es = (float)input_str('alert_engagement');
            if ($es >= 1.5 && $es <= 20) $cfg['alerts']['engagement_spike_x'] = $es;
            $vs = input_int('alert_volume');
            if ($vs >= 10 && $vs <= 500) $cfg['alerts']['volume_spike_pct'] = $vs;
            $fj = (float)input_str('alert_follower');
            if ($fj >= 1 && $fj <= 50) $cfg['alerts']['follower_jump_pct'] = $fj;
            $sd = input_int('alert_silence');
            if ($sd >= 3 && $sd <= 60) $cfg['alerts']['silence_days'] = $sd;
        }

        // Report (form="report")
        if ($form === 'report') {
            $tpl = input_str('report_template');
            if (in_array($tpl, ['strategic','operational','numbers'], true)) $cfg['report']['template'] = $tpl;
            $lang = input_str('report_language', 'POST', 30);
            if ($lang !== '') $cfg['report']['language'] = $lang;
            $cfg['report']['custom_focus'] = input_str('custom_focus', 'POST', 1000);
        }

        // Write config atomically with var_export + lint di sicurezza
        $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
        $tmp = CW_CONFIG . '/config.php.tmp';
        $written = file_put_contents($tmp, $php, LOCK_EX) !== false;
        // Verifica che il file generato sia PHP valido prima di sostituirlo (evita white-screen)
        $valid = $written && (static function($f){ $o=[]; $r=0; exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $r); return $r===0; })($tmp);
        if (!$written || !$valid) {
            if (is_file($tmp)) @unlink($tmp);
            flash_set('error', 'Impossibile salvare: configurazione non valida o permessi mancanti.');
        } elseif (!rename($tmp, CW_CONFIG . '/config.php')) {
            @unlink($tmp);
            flash_set('error', 'Impossibile scrivere config.php — verifica permessi.');
        } else {
            audit_log('settings_save', "configurazione aggiornata ({$form})");
            flash_set('success', 'Impostazioni salvate.');
        }
    }

    if (input_str('action') === 'test_llm') {
        $results = llm_health_check();
        $parts = [];
        foreach ($results as $model => $r) {
            $parts[] = $model . ': ' . ($r['ok'] ? 'OK ✓' : ('ERRORE — ' . $r['error']));
        }
        flash_set('info', implode(' | ', $parts));
    }

    if (input_str('action') === 'preview_prompt') {
        require_once CW_LIB . '/jobs.php';
        $ym  = input_str('preview_month', 'POST', 7) ?: date('Y-m', strtotime('first day of last month'));
        $cid = input_int('preview_competitor');
        $_SESSION['prompt_preview'] = report_preview_prompt($ym, $cid);
        $_SESSION['prompt_preview']['month'] = $ym;
        redirect('settings.php#preview');
    }

    redirect('settings.php');
}

$pageTitle = 'Impostazioni';

function key_status(string $key): string
{
    if (!$key || $key === 'YOUR_KEY_HERE' || $key === 'YOUR_APIFY_TOKEN') return 'assente';
    return 'presente (•••' . e(substr($key, -4)) . ')';
}

include CW_VIEWS . '/layout-header.php';
?>

<h1>Impostazioni</h1>

<section class="card">
  <h2>Chiavi API</h2>
  <p class="muted">Le chiavi sono salvate in <code>config/config.php</code> (protetto da .htaccess).
     Lascia vuoto un campo per mantenere la chiave esistente.</p>
  <form method="post" class="form-grid" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="form" value="keys">

    <label>Apify token <span class="muted">[<?= key_status((string)config('apify.token', '')) ?>]</span>
      <input type="password" name="apify_token" placeholder="apify_api_..." autocomplete="new-password"></label>

    <label>Claude API key <span class="muted">[<?= key_status((string)config('llm.keys.claude', '')) ?>]</span>
      <input type="password" name="key_claude" placeholder="sk-ant-..." autocomplete="new-password"></label>

    <label>OpenAI API key <span class="muted">[<?= key_status((string)config('llm.keys.openai', '')) ?>]</span>
      <input type="password" name="key_openai" placeholder="sk-..." autocomplete="new-password"></label>

    <label>Gemini API key <span class="muted">[<?= key_status((string)config('llm.keys.gemini', '')) ?>]</span>
      <input type="password" name="key_gemini" placeholder="AIza..." autocomplete="new-password"></label>

    <label>Modello primario
      <select name="llm_primary">
        <?php foreach (['claude', 'openai', 'gemini'] as $m): ?>
        <option value="<?= e($m) ?>" <?= config('llm.primary') === $m ? 'selected' : '' ?>><?= e($m) ?></option>
        <?php endforeach; ?>
      </select></label>

    <label>Budget LLM mensile (€)
      <input type="number" name="budget" min="1" max="1000" value="<?= e((int)config('llm.budget_eur_monthly', 30)) ?>"></label>

    <label>Retention dati grezzi (mesi)
      <input type="number" name="retention" min="1" max="24" value="<?= e((int)config('retention_months', 3)) ?>"></label>

    <label>Max item per fetch Apify
      <input type="number" name="max_items" min="3" max="50" value="<?= e((int)config('apify.max_items_per_fetch', 12)) ?>"></label>

    <button type="submit" class="btn">Salva impostazioni</button>
  </form>
</section>

<section class="card">
  <h2>Test connessione LLM</h2>
  <p class="muted">Invia una micro-richiesta a ogni provider configurato (costo &lt; €0,001).</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_llm">
    <button type="submit" class="btn">Testa LLM</button>
  </form>
</section>

<section class="card">
  <h2>⏱️ Pianificazione</h2>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="form" value="schedule">
    <label>Frequenza fetch
      <select name="fetch_frequency">
        <option value="daily" <?= config('schedule.fetch_frequency')==='daily'?'selected':'' ?>>Giornaliera</option>
        <option value="weekly" <?= config('schedule.fetch_frequency')==='weekly'?'selected':'' ?>>Settimanale (lunedì)</option>
      </select></label>
    <label>Ora fetch (0-23)
      <input type="number" name="fetch_hour" min="0" max="23" value="<?= e((int)config('schedule.fetch_hour',3)) ?>"></label>
    <label><input type="checkbox" name="report_monthly" value="1" <?= config('schedule.report_monthly')?'checked':'' ?>> Report mensile automatico (giorno 1)</label>
    <label><input type="checkbox" name="report_weekly" value="1" <?= config('schedule.report_weekly')?'checked':'' ?>> Report settimanale automatico (lunedì)</label>
    <button type="submit" class="btn">Salva pianificazione</button>
  </form>
</section>

<section class="card">
  <h2>🔔 Soglie alert sui concorrenti</h2>
  <p class="muted">Calcolati gratis a ogni fetch, sui dati raccolti. Ti avvisano durante il mese.</p>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="form" value="alerts">
    <label><input type="checkbox" name="alerts_enabled" value="1" <?= config('alerts.enabled')?'checked':'' ?>> Alert attivi</label>
    <label>Spike engagement: un post supera N volte la media
      <input type="number" step="0.5" name="alert_engagement" min="1.5" max="20" value="<?= e((float)config('alerts.engagement_spike_x',3)) ?>"></label>
    <label>Picco volume: +% post settimanali vs media
      <input type="number" name="alert_volume" min="10" max="500" value="<?= e((int)config('alerts.volume_spike_pct',50)) ?>"></label>
    <label>Variazione follower: oltre % in un giorno
      <input type="number" step="0.5" name="alert_follower" min="1" max="50" value="<?= e((float)config('alerts.follower_jump_pct',5)) ?>"></label>
    <label>Silenzio: giorni senza post
      <input type="number" name="alert_silence" min="3" max="60" value="<?= e((int)config('alerts.silence_days',10)) ?>"></label>
    <button type="submit" class="btn">Salva soglie</button>
  </form>
</section>

<section class="card">
  <h2>🧠 Report intelligente</h2>
  <form method="post" class="form-grid">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="form" value="report">
    <label>Template report
      <select name="report_template">
        <option value="strategic" <?= config('report.template')==='strategic'?'selected':'' ?>>Strategico (sintesi alta)</option>
        <option value="operational" <?= config('report.template')==='operational'?'selected':'' ?>>Operativo (social media manager)</option>
        <option value="numbers" <?= config('report.template')==='numbers'?'selected':'' ?>>Solo numeri (no narrativa)</option>
      </select></label>
    <label>Lingua del report
      <input type="text" name="report_language" value="<?= e((string)config('report.language','italiano')) ?>" maxlength="30"></label>
    <label>Focus personalizzato (istruzioni extra per l'LLM)
      <textarea name="custom_focus" rows="3" maxlength="1000" placeholder="Es: Concentrati sul tono di voce e sulle call-to-action. Ignora YouTube."><?= e((string)config('report.custom_focus','')) ?></textarea></label>
    <button type="submit" class="btn">Salva report</button>
  </form>
</section>

<section class="card" id="preview">
  <h2>👁️ Anteprima prompt (nessun costo)</h2>
  <p class="muted">Vedi esattamente cosa verrebbe inviato all'LLM, con stima token e costo, senza spendere nulla.</p>
  <form method="post" class="inline-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="preview_prompt">
    <select name="preview_competitor">
      <option value="0">Tutti i concorrenti</option>
      <?php foreach (db_find('competitors', ['archived'=>false]) as $c): ?>
        <option value="<?= e((int)$c['_id']) ?>"><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="month" name="preview_month" value="<?= e(date('Y-m', strtotime('first day of last month'))) ?>">
    <button type="submit" class="btn">Genera anteprima</button>
  </form>
  <?php if (!empty($_SESSION['prompt_preview'])):
      $pv = $_SESSION['prompt_preview']; unset($_SESSION['prompt_preview']); ?>
    <?php if ($pv['ok']): ?>
      <p class="muted">Mese <?= e($pv['month']) ?> · ~<?= e($pv['tokens']) ?> token in · costo stimato <?= e(fmt_eur((float)$pv['est_cost'])) ?></p>
      <pre style="max-height:400px;overflow:auto;white-space:pre-wrap"><?= e($pv['prompt']) ?></pre>
    <?php else: ?>
      <p class="flash flash-error"><?= e($pv['msg']) ?></p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php include CW_VIEWS . '/layout-footer.php'; ?>
