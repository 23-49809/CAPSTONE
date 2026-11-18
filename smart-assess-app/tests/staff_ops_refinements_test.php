<?php
declare(strict_types=1);

/*
 * Staff dashboard, request processing and internal design refinements.
 *
 * Source checks on the internal page, bridge and API, plus behaviour checks that run the
 * page's own functions in Node (phone format, names, rejection reasons, timer, stat cards).
 * The server side (Processing from Update Status, Processing End, rejection reasons per
 * request type, exact submitted name) is exercised end to end in staff_tracking_flow_test.php.
 *
 * Run: php smart-assess-app/tests/staff_ops_refinements_test.php   (needs node on PATH)
 */

$project = dirname(__DIR__, 2);
$html = (string) file_get_contents($project . '/smart-assess-internal.html');
$bridge = (string) file_get_contents($project . '/smart-assess-app/web/assets/php-bridge.js');
$api = (string) file_get_contents($project . '/smart-assess-app/web/api.php');

$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 500)];
};
$between = static function (string $source, string $start, string $end): string {
    $from = strpos($source, $start);
    if ($from === false) {
        return '';
    }
    $to = strpos($source, $end, $from + strlen($start));
    return $to === false ? '' : substr($source, $from, $to - $from);
};
$fn = static fn (string $name): string => $between($html, 'function ' . $name . '(', "\n}\n") . "\n}\n";

// ---- 1. Staff Dashboard summary cards: information only ----
$staffDash = $between($html, 'function renderStaffDashboard(acct){', "\n}\n");
foreach (['Pending / Under Review', 'Requirements Incomplete', 'Ready for Release'] as $card) {
    $check("Staff Dashboard card \"{$card}\" is not clickable", (bool) preg_match("/dashStatCard\\('[a-z]*', \\w+, '" . preg_quote($card, '/') . "', '[^']+', null\\)/", $staffDash));
}
$check('Admin and Head summary cards still open their pages', str_contains($html, "dashStatCard('', total, 'Total Accounts', 'All internal roles', 'staff-accounts')")
    && str_contains($html, "dashStatCard('', docTotal, 'Document Requests', pendingDoc+' pending action', 'reports')"));
$check('information cards keep the card design and hover, without pointer or press', str_contains($html, '.dash-stat.dash-stat-info{ cursor:default; }')
    && str_contains($html, '.dash-stat.dash-stat-info:active{ transform:none; }') && str_contains($html, '.dash-stat:hover{ transform:translateY(-2px);'));

// ---- 2. Document Requests filter ----
$docList = $between($html, 'function renderDocRequests(', "\n}\n");
$landList = $between($html, 'function renderLandRequests(', "\n}\n");
$check('Document Requests status filter does not offer Timed Out', str_contains($docList, "STATUS_ORDER.filter(function(s){ return s!=='Timed Out'; })"));
$check('Timed Out stays a real status (status list, badge, server)', str_contains($html, "var STATUS_ORDER = ['Received','Processing','Approved','Rejected','Out for Release','Timed Out'];")
    && str_contains($api, "\$validStatuses = ['Received', 'Processing', 'Approved', 'Rejected', 'Out for Release', 'Timed Out'];"));

// ---- 3. Empty states without icons ----
foreach (['Document Requests' => $docList, 'Land Transfer Requests' => $landList] as $page => $source) {
    $empty = $between($source, '<div class="empty-state">', '</div>');
    $check("{$page} empty state is text only", $empty !== '' && !str_contains($empty, "ic('"), $empty);
}

// ---- 4. Sidebar: Send Updates ----
$check('Staff sidebar label is Send Updates (same page)', str_contains($html, "{view:'notifications', label:'Send Updates', icon:'sms'},"));
$check('the Send Updates page heading matches the sidebar', str_contains($html, '<h1>Send Updates</h1>'));

// ---- 5./6. The old Send Updates workflow (restored 17 Nov 2026) ----
// A rejection opens the old pre-filled, editable draft; nothing is sent until Staff click Send.
$check('a rejection opens the old pre-filled draft with the client\'s name and reference',
    str_contains($html, "rejection: 'Rejected', category: 'Request Status Update', topic: 'Request rejected: '+r.id,")
    && str_contains($html, "was rejected. Please contact the office for more information. Thank you."));
$check('the category list is the fixed one again (no per-type rebuild in the page)',
    !str_contains($html, 'notifCategoryOptions') && !str_contains($html, 'REJECTION_CATEGORIES')
    && str_contains($html, "var NOTIF_CATEGORIES = ['Missing Document','Additional Requirements','Request Status Update','Land Transfer Status','Completion / Approval','Other Instruction'];"));
$docReasons = ['Invalid or Missing Government ID', 'Incomplete Request Details', 'Missing Property Information', 'Missing Authorization Documents'];
$landReasons = ['Missing or Invalid Tax Declaration / Title', 'Missing Deed of Sale / Donation', 'Missing Vicinity Map',
    'Missing Certification of No Improvement', 'Missing Tax Clearance', 'Invalid or Missing Government ID'];
foreach ($docReasons as $reason) {
    $check("server accepts Document Request reason \"{$reason}\"", (bool) preg_match("/SA_DOCREQ_REJECTION_CATEGORIES = \\[[^\\]]*'" . preg_quote($reason, '/') . "'/s", $api));
}
foreach ($landReasons as $reason) {
    $check("server accepts Land Transfer reason \"{$reason}\"", (bool) preg_match("/SA_LANDTRANSFER_REJECTION_CATEGORIES = \\[[^\\]]*'" . preg_quote($reason, '/') . "'/s", $api));
}
$check('the server refuses a reason from the other request type', str_contains($api, "if (!sa_category_fits_flow(\$category, (string) \$request['flow'])) {"));
$check('the old static message placeholder is back', str_contains($html, 'placeholder="Type the message for the applicant…" required>'));

// ---- 7. Approve ----
$detail = $between($html, 'function modalReqDetail(id, kind){', "\nfunction ");
$check('Staff see Approve, which saves the existing Out for Release status', str_contains($detail, "['Out for Release','Approve']"));
$check('Approve still opens Send Updates with a pickup draft after the server confirms', str_contains($bridge, "if (data.status === 'Rejected' || data.status === 'Out for Release') {")
    && str_contains($bridge, "kind: wanted === 'Rejected' ? 'rejected' : 'release'") && str_contains($html, "category: 'Completion / Approval', topic: 'Ready for pickup: '+r.id, pickupDate: pickup,"));

// ---- 8./9. Processing, Processing End, no Elapsed / Complete Request in the details ----
$check('Update Status > Processing uses the existing start_processing timer', str_contains($bridge, "if (data.status === 'Processing') {\n        internalSuccess('start_processing', { id: data.id });"));
$check('no "Elapsed" label on the processing time', !str_contains($html, "'Elapsed: '"));
$check('no Complete Request or Process Request button beside the time in the details', !str_contains($detail, 'Complete Request') && !str_contains($detail, 'btn-process'));
$check('the details label the end time Processing End', str_contains($detail, "['Processing End', fmtDateTime(r.processingCompletedAt)]"));

// ---- 10. The old visible Send by choice (restored 17 Nov 2026) ----
$notif = $between($html, 'function renderNotifications(acct){', "\n}\n");
$check('the Send by fieldset is visible again with both channel boxes', str_contains($notif, '<fieldset class="nf-channels"><legend>Send by</legend>')
    && str_contains($notif, 'SMS to the phone number') && str_contains($notif, 'Email to the email address')
    && !str_contains($html, '.nf-channels[hidden]') && !str_contains($notif, 'data-channel-summary'));
$check('channel boxes follow availability but Staff keep the choice (no extra client-side gating)',
    str_contains($bridge, 'var smsUsable = !!phone && !(channelStatus && !channelStatus.sms.ready);')
    && !str_contains($bridge, 'isPhilippineMobile') && !str_contains($bridge, 'updateChannelSummary')
    && !str_contains($bridge, '!data.send_sms && !data.send_email'));
$check('Staff still click Send, and duplicate prevention is unchanged', str_contains($notif, ' Send Notification</button>') && str_contains($bridge, "if (form.getAttribute('data-sending') === '1') return;")
    && str_contains($bridge, "error.code !== 'sms_unconfirmed'"));
$check('the recipient shows the submitted name without "contact on this request"', !str_contains($bridge, 'contact on this request') && str_contains($bridge, 'var shownName = result.data.submittedName || ownerName;'));

// ---- 11. Profile ----
$profile = $between($html, 'function renderProfile(acct){', "\n}\n");
$check('Staff profile has eye buttons on Current and New Password', str_contains($profile, "profilePasswordInput('profile-current-password', 'current_password'")
    && str_contains($profile, "profilePasswordInput('profile-new-password', 'password'") && str_contains($html, 'data-action="toggle-login-password" data-target="\'+id+\'"'));
$check('Staff Account Details and Role Information have no icons', str_contains($profile, "'<div class=\"panel-card\"><h3>'+(isStaff?'':ic('user'))+'Account Details</h3>'")
    && str_contains($profile, "'<div class=\"panel-card\"><h3>'+(isStaff?'':ic('idCard'))+'Role Information</h3>'"));

// ---- 12. Bell ----
$check('the bell is shown for every internal role and opens Announcements', str_contains($html, "+ '<button type=\"button\" class=\"hd-bell\" data-action=\"open-ann-inbox\"")
    && !str_contains($html, '(acct.role===\'staff\' ? \'<button class="hd-bell"') && str_contains($html, "state.modal = {type:'ann-inbox'}; render();"));
$check('the bell does not open Send Updates', !str_contains($html, 'class="hd-bell" data-action="nav" data-view="notifications"'));
$check('the bell lists only live internal announcements', str_contains($fn('internalAnnouncementsVisible'), "(a.postTo==='staff' || a.postTo==='both') && annVisibleTo(a, 'staff')"));

// ---- 13. Sidebar ----
$check('the original sidebar photo layer is shown for Staff too', !str_contains($html, 'html.staff-ui .sidebar-art{ display:none; }')
    && str_contains($html, 'background:url("sidebar-mao.jpg") 60% 70% / cover no-repeat; mix-blend-mode:luminosity; opacity:.34;'));
$check('Staff use the original sidebar width and spacing', !preg_match('/html\.staff-ui[^{]*\.(sidebar|side-link|sb-brand)/', $html) && !str_contains($html, '--sidebar-w:240px'));
$check('the original sidebar image is in the repository and served', is_file($project . '/smart-assess-app/web/assets/sidebar-mao.jpg')
    && str_contains((string) file_get_contents($project . '/smart-assess-app/web/prototype.php'), "'assets/sidebar-mao.jpg'"));

// ---- 14. Typography: no new font families or sizes in the new rules ----
$newCss = implode("\n", array_filter([
    $between($html, '.dash-stat.dash-stat-info{', "\n"), $between($html, '.proc-end{', "\n"), $between($html, '.modal.ann-inbox{', '.ann-inbox-list .dash-ann p'),
    $between($html, '.nf-channels[hidden]', "\n  .nf-channels legend"),
]));
$check('the new styles set no font family or size', $newCss !== '' && !preg_match('/font-(family|size)\s*:|font\s*:/', $newCss), $newCss);

// ---- Behaviour: run the page's own functions in Node ----
$script = 'var state = {docRequests:[], landRequests:[]};'
    . 'function esc(s){ return String(s==null?"":s).replace(/[&<>"\']/g,function(c){return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#39;"}[c];}); }'
    . 'function findDocReq(id){ return state.docRequests.find(function(r){return r.id===id;}); }'
    . 'function findLandReq(id){ return state.landRequests.find(function(r){return r.id===id;}); }'
    . $between($html, 'var NOTIF_CATEGORIES = [', "\n/* The selected request's own record") . "\n"
    . $fn('padTimer') . $fn('formatDuration') . $fn('processingTimerHtml') . $fn('dashStatCard')
    . <<<'JS'
var out = {};
out.phone = ['+639989695966', '639989695966', '+63 998 969 5966', '09989695966', '+6328765432', ''].map(phLocalMobile);
state.docRequests.push({id:'DR-1', status:'Rejected', applicant:'Ana  Reyes Dimaculangan', details:{firstName:'Ana', middleName:'Reyes', lastName:'Dimaculangan'}});
state.docRequests.push({id:'DR-2', status:'Processing', applicant:'Bo Cruz', details:{firstName:'Bo', middleName:'', lastName:'Cruz'}});
state.landRequests.push({id:'LT-1', status:'Rejected', applicant:'Cy Ramos', details:{firstName:'Cy', middleName:'D.', lastName:'Ramos'}});
out.name = [submittedFullName(findDocReq('DR-1')), submittedFullName(findDocReq('DR-2')), submittedFullName({applicant:'Only  Applicant'})];
out.timerRunning = processingTimerHtml({processingStartedEpoch: Math.floor(Date.now()/1000)-65, processingDurationSeconds:null}, true);
out.timerEnded = processingTimerHtml({processingStartedEpoch: 1, processingDurationSeconds: 3725}, true);
out.timerListEnded = processingTimerHtml({processingStartedEpoch: 1, processingDurationSeconds: 60});
out.cardInfo = dashStatCard('amber', 4, 'Requirements Incomplete', 'Needs applicant follow-up', null);
out.cardNav = dashStatCard('', 9, 'Total Accounts', 'All internal roles', 'staff-accounts');
process.stdout.write(JSON.stringify(out));
JS;
$jsFile = tempnam(sys_get_temp_dir(), 'sa-ops-') . '.js';
file_put_contents($jsFile, $script);
$node = trim((string) shell_exec('command -v node'));
$check('node is available for the behaviour checks', $node !== '');
$raw = $node !== '' ? (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($jsFile) . ' 2>&1') : '';
@unlink($jsFile);
$out = json_decode($raw, true);
$check('the page functions run', is_array($out), $raw);
$out = is_array($out) ? $out : [];

$check('+63 mobile numbers are shown with a leading 0', ($out['phone'] ?? null) === ['09989695966', '09989695966', '09989695966', '09989695966', '+6328765432', ''], $out['phone'] ?? null);
$check('the client\'s full submitted name is shown as entered', ($out['name'] ?? null) === ['Ana Reyes Dimaculangan', 'Bo Cruz', 'Only Applicant'], $out['name'] ?? null);
$check('a running timer shows only the time (no "Elapsed", no Processing End)', !str_contains((string) ($out['timerRunning'] ?? ''), 'Elapsed')
    && !str_contains((string) ($out['timerRunning'] ?? ''), 'Processing End') && str_contains((string) ($out['timerRunning'] ?? ''), 'data-final-seconds=""'), $out['timerRunning'] ?? '');
$check('an ended timer is frozen at the final duration and marked Processing End', str_contains((string) ($out['timerEnded'] ?? ''), '>01:02:05</span>')
    && str_contains((string) ($out['timerEnded'] ?? ''), '<span class="proc-end">Processing End</span>'), $out['timerEnded'] ?? '');
$check('request lists also show Processing End', str_contains((string) ($out['timerListEnded'] ?? ''), 'Processing time: ') && str_contains((string) ($out['timerListEnded'] ?? ''), 'Processing End'), $out['timerListEnded'] ?? '');
$card = (string) ($out['cardInfo'] ?? '');
$check('an information card has the same parts but no button or navigation', str_starts_with($card, '<div class="dash-stat dash-stat-info amber"') && !str_contains($card, 'data-action')
    && !str_contains($card, '<button') && str_contains($card, 'dash-stat-num">4<') && str_contains($card, 'Needs applicant follow-up'), $card);
$check('a navigation card is unchanged', ($out['cardNav'] ?? '') === '<button type="button" class="dash-stat" data-action="nav" data-view="staff-accounts"><span class="dash-stat-label"><span class="dash-stat-dot" aria-hidden="true"></span>Total Accounts</span><span class="dash-stat-num">9</span><span class="dash-stat-sub">All internal roles</span></button>', $out['cardNav'] ?? '');

// ---- Report ----
$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
