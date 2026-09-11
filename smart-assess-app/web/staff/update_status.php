<?php
require_once __DIR__ . '/../includes/auth.php';
$me = require_role(['staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    header('Location: /staff/requests.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

if ($id && in_array($status, STATUS_FLOW, true)) {
    $req = fetch_request_by_id($id);
    if ($req) {
        if ($status === 'Approved' && !$req['requirement_complete']) {
            header('Location: /staff/detail.php?id=' . $id . '&error=incomplete_requirements');
            exit;
        }

        $missing = array_values(array_map(
            fn($d) => $d['label'],
            array_filter($req['documents'], fn($d) => $d['file_status'] !== 'ok')
        ));
        push_status($id, $status, $req['reference_no'], $missing);
        audit('staff', $me['id'], $me['name'], "Marked {$req['reference_no']} as $status", $req['reference_no']);
    }
}

header('Location: /staff/detail.php?id=' . $id);
exit;
