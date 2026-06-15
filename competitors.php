<?php
require __DIR__ . '/lib/bootstrap.php';
require_auth();
require_once CW_LIB . '/providers.php';
require_once CW_LIB . '/jobs.php';

const MAX_COMPETITORS = 10;

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input_str('action');

    if ($action === 'add_competitor') {
        $isOwn = (input_str('is_own') === '1');
        // Il brand proprio non occupa uno slot competitor
        $competitorCount = count(array_filter(
            db_find('competitors', ['archived' => false]),
            fn($c) => empty($c['is_own'])
        ));
        if (!$isOwn && $competitorCount >= MAX_COMPETITORS) {
            flash_set('error', 'Limite di ' . MAX_COMPETITORS . ' concorrenti raggiunto.');
        } elseif ($isOwn && db_exists('competitors', ['is_own' => true, 'archived' => false])) {
            flash_set('error', 'Hai già definito il tuo brand. Archivia quello esistente per cambiarlo.');
        } else {
            $name = input_str('name', 'POST', 100);
            if ($name === '') {
                flash_set('error', 'Nome obbligatorio.');
            } else {
                $id = db_insert('competitors', [
                    'name'     => $name,
                    'sector'   => input_str('sector', 'POST', 100),
                    'notes'    => input_str('notes', 'POST', 500),
                    'is_own'   => $isOwn,
                    'archived' => false,
                ]);
                audit_log('competitor_add', $name);
                flash_set('success', "Concorrente \"{$name}\" aggiunto.");
                redirect('competitors.php?id=' . $id);
            }
        }
        redirect('competitors.php');
    }

    if ($action === 'archive_competitor') {
        $id = input_int('id');
        $c  = db_find_by_id('competitors', $id);
        if ($c) {
            db_update('competitors', $id, ['archived' => true]);
            // Deactivate handles
            foreach (db_find('handles', ['competitor_id' => $id]) as $h) {
                db_update('handles', (int)$h['_id'], ['active' => false]);
            }
            audit_log('competitor_archive', (string)$c['name']);
            flash_set('success', 'Concorrente archiviato.');
        }
        redirect('competitors.php');
    }

    if ($action === 'add_handle') {
        $cid      = input_int('competitor_id');
        $platform = input_str('platform');
        $handle   = validate_handle(input_str('handle', 'POST', 100));

        if (!db_find_by_id('competitors', $cid)) {
            flash_set('error', 'Concorrente non trovato.');
            redirect('competitors.php');
        }
        if (!in_array($platform, PLATFORMS, true)) {
            flash_set('error', 'Piattaforma non valida.');
            redirect('competitors.php?id=' . $cid);
        }
        if ($handle === '') {
            flash_set('error', 'Handle non valido. Usa solo lettere, numeri, punti, trattini.');
            redirect('competitors.php?id=' . $cid);
        }
        if (db_exists('handles', ['competitor_id' => $cid, 'platform' => $platform, 'handle' => $handle])) {
            flash_set('error', 'Handle già presente.');
            redirect('competitors.php?id=' . $cid);
        }

        db_insert('handles', [
            'competitor_id' => $cid,
            'platform'      => $platform,
            'handle'        => $handle,
            'active'        => true,
        ]);
        audit_log('handle_add', "{$platform}/@{$handle}");
        flash_set('success', "Handle @{$handle} aggiunto.");
        redirect('competitors.php?id=' . $cid);
    }

    if ($action === 'delete_handle') {
        $hid = input_int('handle_id');
        $h   = db_find_by_id('handles', $hid);
        if (!$h) {
            flash_set('error', 'Handle non trovato.');
            redirect('competitors.php');
        }
        // Sicurezza: si elimina definitivamente solo un handle già disattivato
        if (!empty($h['active'])) {
            flash_set('error', 'Disattiva l\'handle prima di rimuoverlo definitivamente.');
            redirect('competitors.php?id=' . (int)$h['competitor_id']);
        }
        $cid = (int)$h['competitor_id'];
        $r = provider_purge_handle($hid);
        audit_log('handle_delete', "{$h['platform']}/@{$h['handle']} — rimossi {$r['posts']} post, {$r['metrics']} metriche");
        flash_set('success', "Handle @{$h['handle']} rimosso definitivamente con {$r['posts']} post e {$r['metrics']} metriche.");
        redirect('competitors.php?id=' . $cid);
    }

    if ($action === 'toggle_handle') {
        $hid = input_int('handle_id');
        $h   = db_find_by_id('handles', $hid);
        if ($h) {
            db_update('handles', $hid, ['active' => empty($h['active'])]);
            flash_set('success', 'Handle aggiornato.');
            redirect('competitors.php?id=' . (int)$h['competitor_id']);
        }
        flash_set('error', 'Handle non trovato.');
        redirect('competitors.php');
    }

    if ($action === 'fetch_now') {
        $hid = input_int('handle_id');
        $h   = db_find_by_id('handles', $hid);
        if ($h) {
            $queued = jobs_queue_fetch($hid);
            audit_log('fetch_manual', "{$h['platform']}/@{$h['handle']}");
            flash_set('success', $queued
                ? 'Fetch di @' . $h['handle'] . ' accodato — verrà eseguito ' . next_cron_estimate() . '.'
                : 'Fetch di @' . $h['handle'] . ' già in coda, in attesa di esecuzione.');
            redirect('competitors.php?id=' . (int)$h['competitor_id']);
        }
        flash_set('error', 'Handle non trovato.');
        redirect('competitors.php');
    }

    if ($action === 'manual_metrics') {
        $hid = input_int('handle_id');
        $h   = db_find_by_id('handles', $hid);
        if ($h) {
            $followers = input_int('followers');
            if ($followers >= 0) {
                provider_manual_metrics($h, $followers, null);
                flash_set('success', 'Metriche salvate.');
            } else {
                flash_set('error', 'Valore follower non valido.');
            }
            redirect('competitors.php?id=' . (int)$h['competitor_id']);
        }
        flash_set('error', 'Handle non trovato.');
        redirect('competitors.php');
    }

    redirect('competitors.php');
}

// ── View ──────────────────────────────────────────────────────────────────────
$pageTitle = 'Concorrenti';
$detailId  = input_int('id', 'GET');
$detail    = $detailId ? db_find_by_id('competitors', $detailId) : null;

$competitors = db_find('competitors', ['archived' => false], 0, 0, 'name', 'asc');

include CW_VIEWS . '/layout-header.php';
?>

<?php
  $ownBrand = db_find('competitors', ['is_own' => true, 'archived' => false], 1);
  $ownBrand = $ownBrand[0] ?? null;
  $compOnly = array_filter($competitors, fn($c) => empty($c['is_own']));
?>
<h1>Concorrenti <span class="muted">(<?= e(count($compOnly)) ?>/<?= e(MAX_COMPETITORS) ?>)</span></h1>

<?php if ($detail): ?>
  <?php $handles = db_find('handles', ['competitor_id' => (int)$detail['_id']], 0, 0, 'platform', 'asc'); ?>
  <p><a href="competitors.php">← Tutti i concorrenti</a></p>

  <section class="card <?= !empty($detail['is_own']) ? 'own-brand' : '' ?>">
    <h2><?= e($detail['name']) ?> <?php if (!empty($detail['is_own'])): ?><span class="tag tag-own">⭐ Il tuo brand</span><?php endif; ?></h2>
    <?php if (!empty($detail['sector'])): ?><p class="muted">Settore: <?= e($detail['sector']) ?></p><?php endif; ?>
    <?php if (!empty($detail['notes'])): ?><p><?= e($detail['notes']) ?></p><?php endif; ?>

    <h3>Handle</h3>
    <?php if (!$handles): ?><p class="muted">Nessun handle. Aggiungine uno qui sotto.</p><?php endif; ?>
    <table>
      <thead><tr><th>Piattaforma</th><th>Handle</th><th>Stato</th><th>Ultimo dato</th><th>Azioni</th></tr></thead>
      <tbody>
      <?php foreach ($handles as $h):
          $lastMetric = db_find('metrics_daily', ['handle_id' => (int)$h['_id']], 1, 0, 'date', 'desc');
          $last = $lastMetric[0] ?? null;
      ?>
        <tr>
          <td><?= e(platform_icon((string)$h['platform'])) ?> <?= e(platform_label((string)$h['platform'])) ?></td>
          <td>@<?= e($h['handle']) ?></td>
          <td><?= empty($h['active']) ? '<span class="tag tag-off">disattivo</span>' : '<span class="tag tag-on">attivo</span>' ?></td>
          <td><?php if ($last):
              $fb = freshness_badge((string)$last['date']);
            ?><span class="fresh-dot <?= e($fb['class']) ?>" title="<?= e($fb['label']) ?>"></span><?= e(fmt_number($last['followers'])) ?> follower <span class="muted">(<?= e($fb['label']) ?>)</span><?php
            else: ?><span class="fresh-dot stale"></span><span class="muted">nessun dato</span><?php endif; ?></td>
          <td class="actions">
            <form method="post" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="fetch_now">
              <input type="hidden" name="handle_id" value="<?= e((int)$h['_id']) ?>">
              <button type="submit" class="btn-sm">Accoda fetch</button>
            </form>
            <form method="post" class="inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_handle">
              <input type="hidden" name="handle_id" value="<?= e((int)$h['_id']) ?>">
              <button type="submit" class="btn-sm"><?= empty($h['active']) ? 'Attiva' : 'Disattiva' ?></button>
            </form>
            <?php if (empty($h['active'])): ?>
            <form method="post" class="inline" onsubmit="return confirm('Rimuovere DEFINITIVAMENTE @<?= e($h['handle']) ?> e tutti i suoi dati (post e metriche)? Operazione irreversibile.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_handle">
              <input type="hidden" name="handle_id" value="<?= e((int)$h['_id']) ?>">
              <button type="submit" class="btn-sm btn-danger">Rimuovi</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <tr class="subrow">
          <td colspan="5">
            <form method="post" class="inline-form">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="manual_metrics">
              <input type="hidden" name="handle_id" value="<?= e((int)$h['_id']) ?>">
              <label>Inserimento manuale follower:
                <input type="number" name="followers" min="0" required>
              </label>
              <button type="submit" class="btn-sm">Salva</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <h3>Aggiungi handle</h3>
    <form method="post" class="inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_handle">
      <input type="hidden" name="competitor_id" value="<?= e((int)$detail['_id']) ?>">
      <select name="platform" required>
        <?php foreach (PLATFORMS as $p): ?>
          <option value="<?= e($p) ?>"><?= e(platform_icon($p)) ?> <?= e(platform_label($p)) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="handle" placeholder="handle senza @" required maxlength="100">
      <button type="submit" class="btn">Aggiungi</button>
    </form>

    <h3 class="danger-zone">Zona pericolosa</h3>
    <form method="post" onsubmit="return confirm('Archiviare questo concorrente? Gli handle verranno disattivati.');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="archive_competitor">
      <input type="hidden" name="id" value="<?= e((int)$detail['_id']) ?>">
      <button type="submit" class="btn btn-danger">Archivia concorrente</button>
    </form>
  </section>

<?php else: ?>

  <?php if ($ownBrand): ?>
  <section class="card own-brand">
    <h2>⭐ Il tuo brand</h2>
    <table>
      <tbody>
        <tr>
          <td><strong><?= e($ownBrand['name']) ?></strong></td>
          <td><?= e($ownBrand['sector'] ?? '') ?></td>
          <?php $no = db_count('handles', ['competitor_id' => (int)$ownBrand['_id'], 'active' => true]); ?>
          <td><?= e($no) ?> <?= $no === 1 ? 'profilo attivo' : 'profili attivi' ?></td>
          <td><a href="competitors.php?id=<?= e((int)$ownBrand['_id']) ?>" class="btn-sm">Gestisci →</a></td>
        </tr>
      </tbody>
    </table>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Concorrenti</h2>
    <?php if (!$compOnly): ?>
      <p class="muted">Nessun concorrente. Aggiungi il primo qui sotto.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Nome</th><th>Settore</th><th class="num">Profili</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($compOnly as $c): ?>
        <tr>
          <td><strong><?= e($c['name']) ?></strong></td>
          <td><?= e($c['sector'] ?? '') ?></td>
          <?php $n = db_count('handles', ['competitor_id' => (int)$c['_id'], 'active' => true]); ?>
          <td class="num"><?= e($n) ?></td>
          <td><a href="competitors.php?id=<?= e((int)$c['_id']) ?>" class="btn-sm">Gestisci →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Aggiungi <?= $ownBrand ? 'concorrente' : 'concorrente o brand' ?></h2>
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_competitor">
      <label>Nome *<input type="text" name="name" required maxlength="100"></label>
      <label>Settore<input type="text" name="sector" maxlength="100"></label>
      <label>Note<textarea name="notes" rows="2" maxlength="500"></textarea></label>
      <?php if (!$ownBrand): ?>
      <label class="checkbox-inline"><input type="checkbox" name="is_own" value="1"> Questo è il <strong>mio brand</strong> (baseline dei confronti, non occupa uno slot)</label>
      <?php endif; ?>
      <button type="submit" class="btn">Aggiungi</button>
      <?php if (count($compOnly) >= MAX_COMPETITORS): ?>
        <p class="muted">Hai raggiunto i <?= e(MAX_COMPETITORS) ?> concorrenti. Puoi ancora aggiungere il tuo brand se non l'hai fatto.</p>
      <?php endif; ?>
    </form>
  </section>

<?php endif; ?>

<?php include CW_VIEWS . '/layout-footer.php'; ?>
