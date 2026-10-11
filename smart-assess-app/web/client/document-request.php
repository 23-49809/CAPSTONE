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
<div class="wizard-shell"><div class="wizard-col">
  <div class="wizard-header">
    <h1>Document Request</h1>
    <p>Submit certified document requests through the Municipal Assessor's Office portal.</p>
  </div>

  <?php if ($aiIssue): ?>
    <div class="flash error"><?= icon_span('alert') ?> The AI Rule-Based Requirement Checker service is unavailable right now (<?= esc($aiIssue) ?>). Make sure the Django service is running, then resubmit.</div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="form-errors"><?= icon_span('alert') ?> Please fix the following:
      <ul><?php foreach ($errors as $msg): ?><li><?= esc($msg) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div data-wizard>
    <div class="stepper">
      <button type="button" class="step-pill"><span class="step-num">1</span><span class="step-label">Personal</span></button>
      <button type="button" class="step-pill"><span class="step-num">2</span><span class="step-label">Property &amp; Document</span></button>
      <button type="button" class="step-pill"><span class="step-num">3</span><span class="step-label">Uploads</span></button>
      <button type="button" class="step-pill"><span class="step-num">4</span><span class="step-label">Review</span></button>
    </div>
    <div class="wizard-card">
      <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>

        <div class="wizard-step">
          <div class="wizard-card-head"><h2><?= icon_span('user') ?> Personal &amp; Address Information</h2><p>All fields are required unless marked optional.</p></div>
          <div class="wizard-card-body">
            <div class="field-grid">
              <div class="field"><label>First Name<span class="req">*</span></label><input type="text" name="first_name" value="<?= esc($values['first_name']) ?>" data-review-field="First Name" data-review-group="Personal Information" data-review-source="first_name" required></div>
              <div class="field"><label>Last Name<span class="req">*</span></label><input type="text" name="last_name" value="<?= esc($values['last_name']) ?>" data-review-field="Last Name" data-review-group="Personal Information" data-review-source="last_name" required></div>
              <div class="field"><label>Middle Name</label><input type="text" name="middle_name" value="<?= esc($values['middle_name']) ?>" data-review-field="Middle Name" data-review-group="Personal Information" data-review-source="middle_name"></div>
              <div class="field"><label>Contact Number<span class="req">*</span></label><input type="text" name="contact_number" placeholder="09XX-XXX-XXXX" value="<?= esc($values['contact_number']) ?>" data-review-field="Contact Number" data-review-group="Personal Information" data-review-source="contact_number" required></div>
              <div class="field" style="grid-column:1/-1"><label>Email Address<span class="req">*</span></label><input type="text" name="email" value="<?= esc($values['email']) ?>" data-review-field="Email Address" data-review-group="Personal Information" data-review-source="email" required></div>
            </div>
            <div class="section-divider"><?= icon_span('pin', '15px') ?><span>Address Information</span></div>
            <div class="field-grid">
              <div class="field" style="grid-column:1/-1"><label>House No. / Street / Village / Barangay<span class="req">*</span></label><input type="text" name="address_line" value="<?= esc($values['address_line']) ?>" data-review-field="Address" data-review-group="Personal Information" data-review-source="address_line" required></div>
              <div class="field"><label>Province<span class="req">*</span></label><input type="text" name="province" value="<?= esc($values['province']) ?>" required></div>
              <div class="field"><label>City/Municipality<span class="req">*</span></label><input type="text" name="city" value="<?= esc($values['city']) ?>" required></div>
              <div class="field"><label>Zip Code<span class="req">*</span></label><input type="text" name="zip_code" value="<?= esc($values['zip_code']) ?>" pattern="\d{4}" required></div>
            </div>
          </div>
          <div class="wizard-card-foot">
            <a class="btn btn-ghost" href="/index.php">Cancel</a>
            <button type="button" class="btn btn-primary" data-wizard-next>Next: Property &amp; Document <?= icon_span('arrowRight') ?></button>
          </div>
        </div>

        <div class="wizard-step" hidden>
          <div class="wizard-card-head"><h2><?= icon_span('home') ?> Property &amp; Document Details</h2><p>Tell us what you need and where the property is.</p></div>
          <div class="wizard-card-body">
            <div class="field-grid">
              <div class="field"><label>Document Type<span class="req">*</span></label>
                <select name="document_type" data-review-field="Document Type" data-review-group="Property &amp; Document" data-review-source="document_type" required>
                  <option value="">Select a document type</option>
                  <?php foreach (DOC_TYPES as $t): ?>
                    <option value="<?= esc($t) ?>" <?= $values['document_type'] === $t ? 'selected' : '' ?>><?= esc($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field"><label>Purpose<span class="req">*</span></label>
                <select name="purpose" data-review-field="Purpose" data-review-group="Property &amp; Document" data-review-source="purpose" required>
                  <option value="">Select a purpose</option>
                  <?php foreach (PURPOSES as $p): ?>
                    <option value="<?= esc($p) ?>" <?= $values['purpose'] === $p ? 'selected' : '' ?>><?= esc($p) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field"><label>ARP / Tax Declaration Number<span class="req">*</span></label>
                <div class="arp-input" data-arp-input>
                  <span>14-</span>
                  <input type="text" placeholder="XXXX-XXXXX" pattern="\d{4}-\d{5}" value="<?= esc(preg_replace('/^14-/', '', $values['arp_number'])) ?>">
                  <input type="hidden" name="arp_number" value="<?= esc($values['arp_number']) ?>" data-review-field="ARP Number" data-review-group="Property &amp; Document" data-review-source="arp_number" required>
                </div>
              </div>
              <div class="field"><label>Property Address<span class="req">*</span></label><input type="text" name="property_address" value="<?= esc($values['property_address']) ?>" data-review-field="Property Address" data-review-group="Property &amp; Document" data-review-source="property_address" required></div>
              <div class="field">
                <label>Barangay (Property Location)<span class="req">*</span></label>
                <div class="barangay-dropdown" data-barangay-dropdown>
                  <button type="button" class="barangay-trigger placeholder"><span class="barangay-trigger-label">Select a barangay&hellip;</span><span class="barangay-chevron"></span></button>
                  <div class="barangay-menu" hidden role="listbox">
                    <?php foreach (BARANGAYS as $b): ?>
                      <button type="button" class="barangay-option" data-value="<?= esc($b) ?>" role="option"><span><?= esc($b) ?></span><span class="barangay-check"><?= icon_span('check', '13px') ?></span></button>
                    <?php endforeach; ?>
                  </div>
                  <select name="barangay" class="barangay-native-select" data-review-field="Barangay" data-review-group="Property &amp; Document" data-review-source="barangay" required>
                    <option value="">Select a barangay</option>
                    <?php foreach (BARANGAYS as $b): ?>
                      <option value="<?= esc($b) ?>" <?= $values['barangay'] === $b ? 'selected' : '' ?>><?= esc($b) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
          </div>
          <div class="wizard-card-foot">
            <button type="button" class="btn btn-ghost" data-wizard-back><?= icon_span('arrowLeft') ?> Back</button>
            <button type="button" class="btn btn-primary" data-wizard-next>Next: Uploads <?= icon_span('arrowRight') ?></button>
          </div>
        </div>

        <div class="wizard-step" hidden>
          <div class="wizard-card-head"><h2><?= icon_span('id') ?> Owner &amp; Uploads</h2><p>Uploads must be JPG, PNG, or PDF under 15&nbsp;MB.</p></div>
          <div class="wizard-card-body">
            <label class="owner-toggle"><input type="checkbox" name="is_owner" <?= $values['is_owner'] ? 'checked' : '' ?> data-review-field="Registered Owner?" data-review-group="Owner &amp; Uploads" data-review-source="is_owner"> I am the registered property owner</label>
            <div class="upload-grid">
              <div class="upload-box"><div class="upload-head"><?= icon_span('id') ?> Valid Government-Issued ID</div><input type="file" name="ownerId" accept=".jpg,.jpeg,.png,.pdf" data-review-field="Government-Issued ID" data-review-group="Owner &amp; Uploads" data-review-source="ownerId"><div class="upload-hint">Required for every request.</div></div>
              <div class="upload-box"><div class="upload-head"><?= icon_span('id') ?> Valid ID of Requester</div><input type="file" name="requesterId" accept=".jpg,.jpeg,.png,.pdf" data-review-field="Requester's ID" data-review-group="Owner &amp; Uploads" data-review-source="requesterId"><div class="upload-hint">Required only if you are not the owner.</div></div>
              <div class="upload-box"><div class="upload-head"><?= icon_span('id') ?> Authorization Letter</div><input type="file" name="authLetter" accept=".jpg,.jpeg,.png,.pdf" data-review-field="Authorization Letter" data-review-group="Owner &amp; Uploads" data-review-source="authLetter"><div class="upload-hint">Required only if you are not the owner.</div></div>
            </div>
            <p class="field-hint">The AI Rule-Based Requirement Checker validates these against your ownership status the moment you submit.</p>
          </div>
          <div class="wizard-card-foot">
            <button type="button" class="btn btn-ghost" data-wizard-back><?= icon_span('arrowLeft') ?> Back</button>
            <button type="button" class="btn btn-primary" data-wizard-next>Next: Review <?= icon_span('arrowRight') ?></button>
          </div>
        </div>

        <div class="wizard-step" hidden>
          <div class="wizard-card-head"><h2><?= icon_span('check') ?> Review Your Request</h2><p>Confirm everything below before submitting.</p></div>
          <div class="wizard-card-body" data-review-body></div>
          <div class="wizard-card-foot">
            <button type="button" class="btn btn-ghost" data-wizard-back><?= icon_span('arrowLeft') ?> Back</button>
            <button type="submit" class="btn btn-primary" data-wizard-submit>Submit Request <?= icon_span('arrowRight') ?></button>
          </div>
        </div>
      </form>
    </div>

    <div class="submit-modal-backdrop" hidden>
      <div class="submit-modal">
        <h2>Confirm Submission</h2>
        <p>Please confirm the details below before submitting your Document Request.</p>
        <ul data-submit-modal-list></ul>
        <div class="submit-modal-actions">
          <button type="button" class="btn btn-ghost" data-submit-modal-cancel>Go Back</button>
          <button type="button" class="btn btn-primary" data-submit-modal-confirm>Confirm &amp; Submit</button>
        </div>
      </div>
    </div>
  </div>
</div></div>
<script src="/assets/wizard.js" defer></script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
