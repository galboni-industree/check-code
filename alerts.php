<?php
require __DIR__ . '/lib/bootstrap.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (input_str('action') === 'mark_read') {
        $id = input_int('id');
        if ($id && db_find_by_id('alerts', $id)) {
            db_update('alerts', $id, ['read' => true]);
        }
    }
    if (input_str('action') === 'mark_all_read') {
        foreach (db_find('alerts', ['read' => false]) as $a) {
            db_update('alerts', (int)$a['_id'], ['read' => true]);
        }
        flash_set('success', 'Tutti gli alert segnati come letti.');
    }
    redirect('alerts.php');
}

$pageTitle = 'Alert';
$alerts = db_find('alerts', [], 0, 0, '_created_at', 'desc');
$pag    = paginate($alerts, 30);

include CW_VIEWS . '/layout-header.php';
?>

<h1>Alert</h1>

<?php if (db_count('alerts', ['read' => false]) > 0): ?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="mark_all_read">
  <button type="submit" class="btn">Segna tutti come letti</button>
</form>
<?php endif; ?>

<section class="card">
  <?php if (!$pag['items']): ?>
    <p class="muted">Nessun alert. Tutto tranquillo.</p>
  <?php else: ?>
  <ul class="alert-list">
    <?php foreach ($pag['items'] as $a): ?>
      <li class="alert-item <?= empty($a['read']) ? 'unread' : '' ?>">
        <div class="alert-head">
          <span class="tag"><?= e(alert_label((string)($a['type'] ?? ''))) ?></span>
          <span class="muted alert-when"><?= e(time_ago((string)($a['_created_at'] ?? ''))) ?></span>
        </div>
        <p class="alert-msg"><?= e($a['message'] ?? '') ?></p>
        <?php if (empty($a['read'])): ?>
        <form method="post" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="mark_read">
          <input type="hidden" name="id" value="<?= e((int)$a['_id']) ?>">
          <button type="submit" class="btn-sm">Segna come letto</button>
        </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?php include CW_VIEWS . '/layout-footer.php'; ?>
