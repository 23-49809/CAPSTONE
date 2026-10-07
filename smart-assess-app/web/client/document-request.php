<?php
require_once __DIR__ . '/../includes/client_auth.php';

// No login required to submit — matches the scope statement that clients
// can request and track documents without an account. If a client IS
// logged in, we prefill their details and link the request to their
// account so it shows up under "My Requests."
$client = current_client();

$flow = 'docreq';
$errors = [];
$values = [
    'first_name' => $client['first_name'] ?? '', 'middle_name' => '', 'last_name' => $client['last_name'] ?? '',
    'contact_number' => $client['contact_number'] ?? '', 'email' => $client['email'] ?? '',
    'address_line' => '', 'province' => 'Batangas', 'city' => 'Mabini', 'zip_code' => '',
    'document_type' => '', 'purpose' => '', 'arp_number' => '', 'property_address' => '', 'barangay' => '',
    'is_owner' => true,
];
$aiIssue = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors['_csrf'] = 'Your session expired. Please resubmit the form.';
    }

    foreach ($values as $key => $default) {
        if ($key === 'is_owner') continue;
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $values['is_owner'] = isset($_POST['is_owner']);

    $errors = array_merge($errors, validate_personal($values));
    $errors = array_merge($errors, validate_property_common($values));
    if (!in_array($values['document_type'], DOC_TYPES, true)) {
        $errors['document_type'] = 'Please select a document type.';
    }

    $requiredKeys = required_id_keys($values['is_owner']);

    if (empty($errors)) {
        $uploadedMeta = build_uploaded_meta_from_files($requiredKeys);

        $ai = call_ai_checker([
            'flow' => $flow,
            'document_type' => $values['document_type'],
            'transfer_type' => null,
            'purpose' => $values['purpose'],
            'is_owner' => $values['is_owner'],
            'uploaded_files' => $uploadedMeta,
        ]);

        if (!$ai['ok']) {
            $aiIssue = $ai['error'];
            $errors['_system'] = 'The AI Rule-Based Requirement Checker could not be reached, so this request was not submitted. Please try again shortly.';
        } else {
            $statusByKey = array_column($ai['checklist'], 'status', 'key');
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $refNo = next_reference_no($flow);
                $stmt = $pdo->prepare(
                    'INSERT INTO requests
                     (client_id, reference_no, flow, first_name, middle_name, last_name, contact_number, email,
                      address_line, province, city, zip_code, document_type, transfer_type, purpose,
                      arp_number, property_address, barangay, is_owner, status, requirement_complete, advisory)
                     VALUES (?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?, "Received", ?, ?)'
                );
                $stmt->execute([
                    $client['id'] ?? null, $refNo, $flow, $values['first_name'], $values['middle_name'] ?: null, $values['last_name'],
                    $values['contact_number'], $values['email'],
                    $values['address_line'], $values['province'], $values['city'], $values['zip_code'],
                    $values['document_type'], null, $values['purpose'],
                    $values['arp_number'], $values['property_address'], $values['barangay'], $values['is_owner'] ? 1 : 0,
                    $ai['requirement_complete'] ? 1 : 0, $ai['advisory'],
                ]);
                $requestId = (int) $pdo->lastInsertId();

                $docStmt = $pdo->prepare(
                    'INSERT INTO request_documents (request_id, doc_key, label, original_name, stored_path, mime_type, file_size, file_status)
                     VALUES (?,?,?,?,?,?,?,?)'
                );
                foreach ($requiredKeys as $key) {
                    $saved = save_uploaded_file($key, $refNo);
                    $fileStatus = $statusByKey[$key] ?? 'missing';
                    $docStmt->execute([
                        $requestId, $key, id_label($flow, $key),
                        $saved['original_name'] ?? null, $saved['stored_path'] ?? null,
                        $saved['mime'] ?? null, $saved['size'] ?? null,
                        $fileStatus,
                    ]);
                    record_document_validation($pdo, (int) $pdo->lastInsertId(), $fileStatus);
                }

                push_status($requestId, 'Received', $refNo, $ai['missing'], 'client');
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors['_system'] = 'Something went wrong saving your request. Please try again.';
            }

            if (empty($errors)) {
                header('Location: /confirmation.php?ref=' . urlencode($refNo));
                exit;
            }
        }
    }
}

$pageTitle = 'Document Request';
require __DIR__ . '/../includes/client_header.php';
?>
<div class="form-shell"><div class="form-col">
  <div class="form-header">
    <h1>Document Request</h1>
    <p>Submit certified document requests through the Municipal Assessor's Office portal.</p>
  </div>
  <div class="form-card">
    <div class="form-card-head">
      <h2><?= icon_span('user') ?> Applicant, Property &amp; Document Details</h2>
      <p>All fields are required unless marked optional. Uploads must be JPG, PNG, or PDF under 15&nbsp;MB.</p>
    </div>
    <div class="form-card-body">
      <?php if ($aiIssue): ?>
        <div class="flash error"><?= icon_span('alert') ?> The AI Rule-Based Requirement Checker service is unavailable right now (<?= esc($aiIssue) ?>). Make sure the Django service is running, then resubmit.</div>
      <?php endif; ?>
      <?php if (!empty($errors)): ?>
        <div class="form-errors"><?= icon_span('alert') ?> Please fix the following:
          <ul>
            <?php foreach ($errors as $msg): ?><li><?= esc($msg) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>
        <div class="field-grid">
          <div class="field"><label>First Name<span class="req">*</span></label><input type="text" name="first_name" value="<?= esc($values['first_name']) ?>"></div>
          <div class="field"><label>Last Name<span class="req">*</span></label><input type="text" name="last_name" value="<?= esc($values['last_name']) ?>"></div>
          <div class="field"><label>Middle Name</label><input type="text" name="middle_name" value="<?= esc($values['middle_name']) ?>"></div>
          <div class="field"><label>Contact Number<span class="req">*</span></label><input type="text" name="contact_number" placeholder="09XX-XXX-XXXX" value="<?= esc($values['contact_number']) ?>"></div>
          <div class="field" style="grid-column:1/-1"><label>Email Address<span class="req">*</span></label><input type="text" name="email" value="<?= esc($values['email']) ?>"></div>
        </div>

        <div class="section-divider"><?= icon_span('pin', '15px') ?><span>Address Information</span></div>
        <div class="field-grid">
          <div class="field" style="grid-column:1/-1"><label>House No. / Street / Village / Barangay<span class="req">*</span></label><input type="text" name="address_line" value="<?= esc($values['address_line']) ?>"></div>
          <div class="field"><label>Province<span class="req">*</span></label><input type="text" name="province" value="<?= esc($values['province']) ?>"></div>
          <div class="field"><label>City/Municipality<span class="req">*</span></label><input type="text" name="city" value="<?= esc($values['city']) ?>"></div>
          <div class="field"><label>Zip Code<span class="req">*</span></label><input type="text" name="zip_code" value="<?= esc($values['zip_code']) ?>"></div>
        </div>

        <div class="section-divider"><?= icon_span('home', '15px') ?><span>Property &amp; Document</span></div>
        <div class="field-grid">
          <div class="field"><label>Document Type<span class="req">*</span></label>
            <select name="document_type">
              <option value="">Select a document type</option>
              <?php foreach (DOC_TYPES as $t): ?>
                <option value="<?= esc($t) ?>" <?= $values['document_type'] === $t ? 'selected' : '' ?>><?= esc($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>Purpose<span class="req">*</span></label>
            <select name="purpose">
              <option value="">Select a purpose</option>
              <?php foreach (PURPOSES as $p): ?>
                <option value="<?= esc($p) ?>" <?= $values['purpose'] === $p ? 'selected' : '' ?>><?= esc($p) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field"><label>ARP / Tax Declaration Number<span class="req">*</span></label><input type="text" name="arp_number" value="<?= esc($values['arp_number']) ?>"></div>
          <div class="field"><label>Property Address<span class="req">*</span></label><input type="text" name="property_address" value="<?= esc($values['property_address']) ?>"></div>
          <div class="field"><label>Barangay (Property Location)<span class="req">*</span></label><input type="text" name="barangay" value="<?= esc($values['barangay']) ?>"></div>
        </div>

        <div class="section-divider"><?= icon_span('id', '15px') ?><span>Owner &amp; Uploads</span></div>
        <label class="owner-toggle"><input type="checkbox" name="is_owner" <?= $values['is_owner'] ? 'checked' : '' ?>> I am the registered property owner</label>
        <div class="upload-grid">
          <div class="upload-box"><div class="upload-head"><?= icon_span('id') ?> Valid Government-Issued ID</div><input type="file" name="ownerId" accept=".jpg,.jpeg,.png,.pdf"><div class="upload-hint">Required for every request.</div></div>
          <div class="upload-box"><div class="upload-head"><?= icon_span('id') ?> Valid ID of Requester</div><input type="file" name="requesterId" accept=".jpg,.jpeg,.png,.pdf"><div class="upload-hint">Required only if you are not the owner.</div></div>
          <div class="upload-box"><div class="upload-head"><?= icon_span('id') ?> Authorization Letter</div><input type="file" name="authLetter" accept=".jpg,.jpeg,.png,.pdf"><div class="upload-hint">Required only if you are not the owner.</div></div>
        </div>
        <p class="field-hint">The AI Rule-Based Requirement Checker validates these against your ownership status the moment you submit.</p>

        <div class="form-card-foot">
          <a class="btn btn-ghost" href="/index.php">Cancel</a>
          <button type="submit" class="btn btn-primary">Submit Request <?= icon_span('arrowRight') ?></button>
        </div>
      </form>
    </div>
  </div>
</div></div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
