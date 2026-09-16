<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['head']);

$pdo = db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
  $id = (int) ($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
  $startDate = $_POST['start_date'] ?? date('Y-m-d');
  $endDate = ($_POST['end_date'] ?? '') !== '' ? $_POST['end_date'] : null;
  $imageUrl = trim($_POST['image_url'] ?? '') ?: null;
  $status = ($_POST['status'] ?? 'Draft') === 'Published' ? 'Published' : 'Draft';
  $action = $_POST['delete'] ?? '';

  if ($action === 'delete' && $id) {
    $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
    audit('head', $me['id'], $me['name'], 'Deleted announcement', (string) $id);
    $flash = 'Announcement removed.';
  } elseif ($title !== '' && $body !== '' && $startDate !== '') {
    if ($id) {
      $pdo->prepare('UPDATE announcements SET title=?, body=?, start_date=?, end_date=?, image_url=?, status=? WHERE id=?')
        ->execute([$title, $body, $startDate, $endDate, $imageUrl, $status, $id]);
      audit('head', $me['id'], $me['name'], 'Updated announcement', $title);
      $flash = 'Announcement updated.';
    } else {
      $pdo->prepare('INSERT INTO announcements (title, body, author, start_date, end_date, image_url, status) VALUES (?,?,?,?,?,?,?)')
        ->execute([$title, $body, $me['name'], $startDate, $endDate, $imageUrl, $status]);
      audit('head', $me['id'], $me['name'], 'Created announcement', $title);
      $flash = 'Announcement saved.';
    }
    }
}

$announcements = $pdo->query('SELECT * FROM announcements ORDER BY created_at DESC')->fetchAll();
$editingId = (int) ($_GET['edit'] ?? 0);
$editingAnnouncement = null;
foreach ($announcements as $announcement) {
  if ((int) $announcement['id'] === $editingId) {
    $editingAnnouncement = $announcement;
    break;
  }
}

$pageTitle = 'Announcements';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Announcements</h1><p>Send office-wide notices to Admin and Assessor's Staff.</p></div></div>
  <div class="wrap" style="max-width:720px">
    <?php if ($flash): ?><div class="flash success"><?= esc($flash) ?></div><?php endif; ?>
    <form method="post" class="compose-form">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $editingAnnouncement ? (int) $editingAnnouncement['id'] : 0 ?>">
      <div class="field"><label>Title</label><input type="text" name="title" value="<?= esc($editingAnnouncement['title'] ?? '') ?>" placeholder="e.g. Office schedule update" required></div>
      <div class="field"><label>Description</label><textarea name="body" rows="4" placeholder="Write the announcement for clients and staff..." required><?= esc($editingAnnouncement['body'] ?? '') ?></textarea></div>
      <div class="field-grid">
        <div class="field"><label>Start Date</label><input type="date" name="start_date" value="<?= esc($editingAnnouncement['start_date'] ?? date('Y-m-d')) ?>" required></div>
        <div class="field"><label>End Date <span class="field-hint">(optional)</span></label><input type="date" name="end_date" value="<?= esc($editingAnnouncement['end_date'] ?? '') ?>"></div>
      </div>
      <div class="field"><label>Announcement Image URL <span class="field-hint">(optional)</span></label><input type="url" name="image_url" value="<?= esc($editingAnnouncement['image_url'] ?? '') ?>" placeholder="https://..."></div>
      <div class="field"><label>Status</label><select name="status"><option value="Draft"<?= (($editingAnnouncement['status'] ?? '') === 'Draft' ? ' selected' : '') ?>>Draft</option><option value="Published"<?= (($editingAnnouncement['status'] ?? '') === 'Published' ? ' selected' : '') ?>>Published</option></select></div>
      <div><button type="submit" class="btn btn-primary"><?= icon_span('send') ?> <?= $editingAnnouncement ? 'Update Announcement' : 'Save Announcement' ?></button><?php if ($editingAnnouncement): ?> <a class="btn btn-ghost" href="/department-head/announcements.php">Cancel Edit</a><?php endif; ?></div>
    </form>
    <?php if (!$announcements): ?><div class="empty-state">No announcements sent yet.</div>
    <?php else: foreach ($announcements as $a): ?>
      <div class="announcement-card">
        <h4><?= esc($a['title']) ?></h4>
        <div class="ac-meta"><?= esc($a['author']) ?> &middot; <?= fmt_datetime($a['created_at']) ?> &middot; <?= esc($a['status']) ?> &middot; <?= esc($a['start_date']) ?><?= $a['end_date'] ? ' – ' . esc($a['end_date']) : '' ?></div>
        <p><?= esc($a['body']) ?></p>
        <div class="action-row">
          <a class="action-btn" href="/department-head/announcements.php?edit=<?= (int) $a['id'] ?>">Edit</a>
          <form method="post"><input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="title" value="<?= esc($a['title']) ?>"><input type="hidden" name="body" value="<?= esc($a['body']) ?>"><input type="hidden" name="start_date" value="<?= esc($a['start_date']) ?>"><input type="hidden" name="end_date" value="<?= esc($a['end_date'] ?? '') ?>"><input type="hidden" name="image_url" value="<?= esc($a['image_url'] ?? '') ?>"><input type="hidden" name="status" value="<?= $a['status'] === 'Published' ? 'Draft' : 'Published' ?>"><button class="action-btn" type="submit"><?= $a['status'] === 'Published' ? 'Unpublish' : 'Publish' ?></button></form>
          <form method="post"><input type="hidden" name="csrf_token" value="<?= esc(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="delete" value="delete"><button class="action-btn danger" type="submit">Remove</button></form>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
