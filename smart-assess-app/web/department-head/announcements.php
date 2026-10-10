<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['head']);

$pdo = db();
publish_due_announcements($pdo);

$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);
            audit('head', $me['id'], $me['name'], 'Deleted announcement', (string) $id);
            $flash = 'Announcement removed.';
        }
    } elseif ($action === 'quick_update') {
        $id = (int) ($_POST['id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        if ($id && in_array($newStatus, ['Published', 'Draft', 'Cancelled'], true)) {
            if ($newStatus === 'Published') {
                $pdo->prepare('UPDATE announcements SET status=?, published_at=NOW(), scheduled_at=NULL WHERE id=?')
                    ->execute([$newStatus, $id]);
            } else {
                $pdo->prepare('UPDATE announcements SET status=?, published_at=NULL WHERE id=?')
                    ->execute([$newStatus, $id]);
            }
            audit('head', $me['id'], $me['name'], "Set announcement status=$newStatus", "#$id");
            $flash = 'Announcement updated.';
        }
    } elseif ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $audience = $_POST['audience'] ?? '';
        $startDate = $_POST['start_date'] ?? date('Y-m-d');
        $endDate = ($_POST['end_date'] ?? '') !== '' ? $_POST['end_date'] : null;
        $imageUrl = trim($_POST['image_url'] ?? '') ?: null;
        $saveMode = $_POST['save_mode'] ?? 'publish'; // 'publish' follows the Publish radio below; 'draft' always drafts
        $publishMode = $_POST['publish_mode'] ?? 'now'; // 'now' or 'schedule'
        $scheduleDate = $_POST['schedule_date'] ?? '';
        $scheduleTime = $_POST['schedule_time'] ?? '';

        $errors = [];
        if ($title === '') $errors[] = 'Title is required.';
        if ($body === '') $errors[] = 'Description is required.';
        if ($startDate === '') $errors[] = 'Start date is required.';
        if (!array_key_exists($audience, ANNOUNCEMENT_AUDIENCES)) $errors[] = 'Select who this announcement is posted to.';

        $status = 'Draft';
        $scheduledAt = null;
        $publishedAt = null;

        if (!$errors) {
            if ($saveMode === 'draft') {
                $status = 'Draft';
            } elseif ($publishMode === 'schedule') {
                if ($scheduleDate === '') $errors[] = 'Schedule date is required.';
                if ($scheduleTime === '') $errors[] = 'Schedule time is required.';
                if (!$errors) {
                    $scheduledAt = $scheduleDate . ' ' . $scheduleTime . ':00';
                    if (strtotime($scheduledAt) <= time()) {
                        $errors[] = 'Scheduled date/time must be in the future.';
                    } else {
                        $status = 'Scheduled';
                    }
                }
            } else {
                $status = 'Published';
                // Editing text on an already-published announcement shouldn't
                // bump its publish timestamp — only set a fresh one the first
                // time it actually becomes Published.
                $already = $id ? $pdo->prepare('SELECT status, published_at FROM announcements WHERE id = ?') : null;
                if ($already) { $already->execute([$id]); $already = $already->fetch(); }
                $publishedAt = ($already && $already['status'] === 'Published' && $already['published_at'])
                    ? $already['published_at']
                    : date('Y-m-d H:i:s');
            }
        }

        if ($errors) {
            $flash = implode(' ', $errors);
            $flashType = 'error';
        } elseif ($id) {
            $pdo->prepare('UPDATE announcements SET title=?, body=?, audience=?, start_date=?, end_date=?, image_url=?, status=?, scheduled_at=?, published_at=? WHERE id=?')
                ->execute([$title, $body, $audience, $startDate, $endDate, $imageUrl, $status, $scheduledAt, $publishedAt, $id]);
            audit('head', $me['id'], $me['name'], "Updated announcement ($status)", $title);
            $flash = 'Announcement updated.';
        } else {
            $pdo->prepare('INSERT INTO announcements (title, body, author, audience, start_date, end_date, image_url, status, scheduled_at, published_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$title, $body, $me['name'], $audience, $startDate, $endDate, $imageUrl, $status, $scheduledAt, $publishedAt]);
            audit('head', $me['id'], $me['name'], "Created announcement ($status)", $title);
            $flash = 'Announcement saved.';
        }
    }
}

// The edit target can be on any page, so it's looked up by its own
// unfiltered/unpaginated query — the list below is independently searched
// and paginated for display only.
$editingId = (int) ($_GET['edit'] ?? 0);
$editingAnnouncement = null;
if ($editingId) {
    $editStmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
    $editStmt->execute([$editingId]);
    $editingAnnouncement = $editStmt->fetch() ?: null;
}

$searchQuery = trim($_GET['q'] ?? '');
$listWhere = '';
$listParams = [];
if ($searchQuery !== '') {
    $listWhere = ' WHERE title LIKE ?';
    $listParams[] = "%$searchQuery%";
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM announcements$listWhere");
$countStmt->execute($listParams);
$pageInfo = paginate_info((int) $countStmt->fetchColumn(), 10);
$listStmt = $pdo->prepare("SELECT * FROM announcements$listWhere ORDER BY created_at DESC LIMIT {$pageInfo['perPage']} OFFSET {$pageInfo['offset']}");
$listStmt->execute($listParams);
$announcements = $listStmt->fetchAll();

$editingPublishMode = ($editingAnnouncement && $editingAnnouncement['status'] === 'Scheduled') ? 'schedule' : 'now';
$editingScheduleDate = '';
$editingScheduleTime = '';
if ($editingAnnouncement && $editingAnnouncement['scheduled_at']) {
    $editingScheduleDate = date('Y-m-d', strtotime($editingAnnouncement['scheduled_at']));
    $editingScheduleTime = date('H:i', strtotime($editingAnnouncement['scheduled_at']));
}

$pageTitle = 'Announcements';
require __DIR__ . '/../includes/internal_header.php';
?>
<div class="dash-shell">
  <div class="dash-top"><div class="wrap"><h1>Announcements</h1><p>Send office-wide notices to the Client Interface, the Staff Interface, or both.</p></div></div>
  <div class="wrap" style="max-width:720px">
    <?php if ($flash): ?><div class="flash <?= esc($flashType) ?>"><?= esc($flash) ?></div><?php endif; ?>
    <form method="post" class="compose-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editingAnnouncement ? (int) $editingAnnouncement['id'] : 0 ?>">
      <div class="field"><label>Title</label><input type="text" name="title" value="<?= esc($editingAnnouncement['title'] ?? '') ?>" placeholder="e.g. Office schedule update" required></div>
      <div class="field"><label>Description</label><textarea name="body" rows="4" placeholder="Write the announcement for clients and staff..." required><?= esc($editingAnnouncement['body'] ?? '') ?></textarea></div>
      <div class="field">
        <label>Post To <span class="req">*</span></label>
        <select name="audience" required>
          <option value="">Select destination</option>
          <?php foreach (ANNOUNCEMENT_AUDIENCES as $value => $label): ?>
            <option value="<?= esc($value) ?>"<?= ($editingAnnouncement['audience'] ?? '') === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field-grid">
        <div class="field"><label>Start Date</label><input type="date" name="start_date" value="<?= esc($editingAnnouncement['start_date'] ?? date('Y-m-d')) ?>" required></div>
        <div class="field"><label>End Date <span class="field-hint">(optional)</span></label><input type="date" name="end_date" value="<?= esc($editingAnnouncement['end_date'] ?? '') ?>"></div>
      </div>
      <div class="field"><label>Announcement Image URL <span class="field-hint">(optional)</span></label><input type="url" name="image_url" value="<?= esc($editingAnnouncement['image_url'] ?? '') ?>" placeholder="https://..."></div>

      <div class="field">
        <label>Publish</label>
        <div class="publish-options" data-publish-options>
          <label class="publish-option"><input type="radio" name="publish_mode" value="now" <?= $editingPublishMode === 'now' ? 'checked' : '' ?>><span><b>Send Immediately</b><br><span class="field-hint">Goes out to the selected audience as soon as you save.</span></span></label>
          <label class="publish-option"><input type="radio" name="publish_mode" value="schedule" <?= $editingPublishMode === 'schedule' ? 'checked' : '' ?>><span><b>Schedule for Later</b><br><span class="field-hint">Stays hidden until the date/time below.</span></span></label>
        </div>
      </div>
      <div class="field-grid schedule-fields" data-schedule-fields hidden>
        <div class="field"><label>Schedule Date</label><input type="date" name="schedule_date" value="<?= esc($editingScheduleDate) ?>"></div>
        <div class="field"><label>Schedule Time</label><input type="time" name="schedule_time" value="<?= esc($editingScheduleTime) ?>"></div>
      </div>

      <div class="action-row">
        <button type="submit" name="save_mode" value="publish" class="btn btn-primary"><?= icon_span('send') ?> <?= $editingAnnouncement ? 'Update Announcement' : 'Save Announcement' ?></button>
        <button type="submit" name="save_mode" value="draft" class="btn btn-ghost">Save as Draft</button>
        <?php if ($editingAnnouncement): ?><a class="btn btn-ghost" href="/department-head/announcements.php">Cancel Edit</a><?php endif; ?>
      </div>
    </form>

    <?php if (!$announcements): ?><div class="empty-state">No announcements sent yet.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Title</th><th>Post To</th><th>Status</th><th>Published</th><th>Scheduled</th><th>Created By</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($announcements as $a): ?>
          <tr>
            <td data-label="Title"><?= esc($a['title']) ?></td>
            <td data-label="Post To"><?= esc(announcement_audience_label($a['audience'])) ?></td>
            <td data-label="Status"><span class="badge <?= announcement_status_badge_class($a['status']) ?>"><?= esc($a['status']) ?></span></td>
            <td class="mono" data-label="Published"><?= fmt_datetime($a['published_at']) ?></td>
            <td class="mono" data-label="Scheduled"><?= fmt_datetime($a['scheduled_at']) ?></td>
            <td data-label="Created By"><?= esc($a['author']) ?></td>
            <td class="actions-cell" data-label="Actions"><div class="table-actions">
              <a class="action-btn" href="/department-head/announcements.php?edit=<?= (int) $a['id'] ?>">Edit</a>
              <?php if (in_array($a['status'], ['Draft', 'Scheduled'], true)): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="quick_update"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="new_status" value="Published"><button class="action-btn primary" type="submit">Publish Now</button></form>
              <?php endif; ?>
              <?php if ($a['status'] === 'Published'): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="quick_update"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="new_status" value="Draft"><button class="action-btn" type="submit">Unpublish</button></form>
              <?php endif; ?>
              <?php if ($a['status'] === 'Scheduled'): ?>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="quick_update"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="new_status" value="Cancelled"><button class="action-btn" type="submit">Cancel</button></form>
              <?php endif; ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="action-btn danger" type="submit">Remove</button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function(){
  var fields = document.querySelector('[data-schedule-fields]');
  var dateInput = fields ? fields.querySelector('input[name="schedule_date"]') : null;
  var timeInput = fields ? fields.querySelector('input[name="schedule_time"]') : null;
  function sync(){
    var scheduled = document.querySelector('input[name="publish_mode"][value="schedule"]').checked;
    if (fields) fields.hidden = !scheduled;
    if (dateInput) dateInput.required = scheduled;
    if (timeInput) timeInput.required = scheduled;
  }
  document.querySelectorAll('input[name="publish_mode"]').forEach(function(radio){
    radio.addEventListener('change', sync);
  });
  sync();
})();
</script>
<?php require __DIR__ . '/../includes/internal_footer.php'; ?>
