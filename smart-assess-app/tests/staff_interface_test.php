<?php
declare(strict_types=1);

/*
 * Staff interface: guards for the Staff-only design and the Document Check page.
 *  - Document Check is a Staff sidebar entry directly under Land Transfer Requests,
 *    and appears nowhere in the Admin or Head navigation or page routing;
 *  - the Staff design layer (html.staff-ui) is switched on for Staff accounts only;
 *  - the Staff design layer keeps the existing typography: it never overrides a
 *    font family, font size, letter spacing or text case of an existing element,
 *    and the request popup keeps its Poppins font;
 *  - the Document Check page stays advisory: no approve, reject or status
 *    actions, no progress percentage, originals open only through request-file.php;
 *  - the original interface icons are rendered again (password eye, close X, send, ...);
 *  - Write with AI / Generate with AI are gone; the request popup no longer shows
 *    Notification History, Findings or What to check;
 *  - Admin has the Announcements page; the account form has a password eye toggle.
 *
 * Reads the source files only (no database, no network).
 *
 *   php tests/staff_interface_test.php
 *
 * Exit code 0 when every check passes, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$html = (string) file_get_contents($root . '/smart-assess-internal.html');
$internalPhp = (string) file_get_contents(dirname(__DIR__) . '/web/internal.php');
$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed || $detail === '' ? '' : json_encode($detail, JSON_UNESCAPED_SLASHES)];
};
$between = static function (string $haystack, string $from, string $to): string {
    $a = strpos($haystack, $from);
    if ($a === false) {
        return '';
    }
    $b = strpos($haystack, $to, $a + strlen($from));
    return $b === false ? '' : substr($haystack, $a, $b - $a);
};

$check('the internal interface template was read', strlen($html) > 100000);

// ---- Navigation and routing ----
$nav = $between($html, 'var NAV = {', '};');
$staffNav = $between($nav, 'staff:', ']');
$adminNav = $between($nav, 'admin:', ']');
$headNav = $between($nav, 'head:', ']');
preg_match_all("/view:'([a-z-]+)'/", $staffNav, $m);
$staffViews = $m[1];
$landAt = array_search('land-transfer-requests', $staffViews, true);
$check('Staff navigation has Document Check', in_array('document-check', $staffViews, true), $staffViews);
$check('Document Check sits directly under Land Transfer Requests', $landAt !== false && ($staffViews[$landAt + 1] ?? '') === 'document-check', $staffViews);
$check('Admin navigation has no Document Check', $adminNav !== '' && !str_contains($adminNav, 'document-check'));
$check('Head navigation has no Document Check', $headNav !== '' && !str_contains($headNav, 'document-check'));
$shell = $between($html, 'function renderShell(acct){', 'return shellChrome(acct, body);');
$adminRoutes = $between($shell, "if(acct.role==='admin'){", "} else if(acct.role==='head'){");
$headRoutes = $between($shell, "} else if(acct.role==='head'){", '} else {');
$check('Admin page routing never renders Document Check', $adminRoutes !== '' && !str_contains($adminRoutes, 'renderDocumentCheck'));
$check('Head page routing never renders Document Check', $headRoutes !== '' && !str_contains($headRoutes, 'renderDocumentCheck'));
$check('internal.php allows the document-check view', str_contains($internalPhp, "'document-check'"));

// ---- Staff design layer is Staff-only ----
$theme = $between($html, 'function applyRoleTheme(acct){', "\n}");
$check('the Staff design layer is switched on only for the staff role', str_contains($theme, "acct.role==='staff'") && str_contains($theme, "classList.toggle('staff-ui', staff)"));
$check('render() applies the role theme', substr_count($html, 'applyRoleTheme(') >= 3);

// ---- Typography preserved ----
$styleEnd = strpos($html, '</style>');
$blockStart = strpos($html, 'Staff interface design system (html.staff-ui)');
$check('the Staff design block is inside the stylesheet', $blockStart !== false && $styleEnd !== false && $blockStart < $styleEnd);
$css = $blockStart === false ? '' : (string) preg_replace('~/\*.*?\*/~s', '', substr($html, $blockStart, $styleEnd - $blockStart));
preg_match_all('~([^{}]+)\{([^{}]*)\}~', $css, $rules, PREG_SET_ORDER);
$typographyOverrides = [];
foreach ($rules as [, $selector, $body]) {
    if (str_contains($selector, 'html.staff-ui') && preg_match('/(^|[;\s])(font-family|font-size|letter-spacing|text-transform|font)\s*:/', $body)) {
        $typographyOverrides[] = trim((string) preg_replace('~\s+~', ' ', $selector));
    }
}
$check('Staff rules found to inspect', count($rules) > 50, count($rules));
$check('no Staff rule changes the font family, size, letter spacing or text case of existing elements', $typographyOverrides === [], $typographyOverrides);
$check('the request popup keeps its Poppins font', str_contains($html, ".modal.req-detail, .modal.req-detail *{ font-family:'Poppins', var(--font-body); }"));
$check('page titles keep the display font', str_contains($html, '.main-content .page-head h1{ font-family:var(--font-display);'));

// ---- Document Check stays advisory ----
$dc = $between($html, 'function renderDocumentCheck(acct){', 'function renderRequirementResults(acct){');
$check('the Document Check code was found', strlen($dc) > 3000);
$check('Document Check has no approve, reject or status actions', !preg_match('/data-action="(approve|reject|update-status|set-status|start-processing|complete-processing)/', $dc));
$check('Document Check shows no progress percentage', !preg_match('/\d+\s*%|%\s*</', $dc));
// Every link and preview source is the request-file.php URL (built in one place, then HTML-escaped).
preg_match_all('#(?:href|src)="\'\+esc\((\w+)\)#', $dc, $escapedUrls);
$check('Document Review opens and previews originals only through request-file.php', str_contains($dc, "var url = uploaded ? '/request-file.php?doc='+encodeURIComponent(f.docId)+'&ref='+encodeURIComponent(r.id) : '';")
    && str_contains($dc, "var previewUrl = url ? url+'&preview=1' : '';")
    && array_diff(array_unique($escapedUrls[1]), ['url', 'previewUrl']) === [] && !preg_match('#(?:href|src)="(?!/request-file\.php|\'\+esc\((?:url|previewUrl)\))#', $dc), $escapedUrls[1]);
$check('Document Check says the AI never approves or rejects', str_contains($dc, 'never approves or rejects a request'));
$check('Document Check says it cannot authenticate documents, signatures or seals', str_contains($dc, 'cannot tell whether a document, signature or seal is genuine'));
$check('Mark as reviewed and Run check again reuse the existing actions', str_contains($dc, 'data-action="ai-mark-reviewed"') && str_contains($dc, 'data-action="ai-requeue"'));

// ---- Original icons restored ----
preg_match('/var ICONS = \{(.*?)\n\};/s', $html, $iconSet);
preg_match_all('/^\s*([A-Za-z0-9_]+)\s*:/m', $iconSet[1] ?? '', $iconNames);
preg_match_all("/ic\\('([A-Za-z0-9_]+)'/", $html, $used);
$check('ic() renders the icon set again', str_contains($html, "function ic(name, size){ return '<span class=\"icon\" style=\"width:'+(size||'17px')+';height:'+(size||'17px')+'\">'+ICONS[name]+'</span>'; }"));
$check('icons are not hidden by CSS', !str_contains($html, '.icon{ display:none !important; }'));
$check('every icon used exists in the icon set', array_diff(array_unique($used[1]), $iconNames[1]) === [], array_values(array_diff(array_unique($used[1]), $iconNames[1])));
$check('sign-in password field has the eye toggle icon', str_contains($html, 'data-action="toggle-login-password" aria-label="Show password" aria-pressed="false">\'+ic(\'eye\',\'19px\')'));
$check('the eye toggle switches to the eye-off icon', str_contains($html, "el.innerHTML = ic(showPassword?'eyeOff':'eye','19px');"));
$check('popups have the close X icon', substr_count($html, "data-action=\"close-modal\">'+ic('x','15px')") >= 4);
$check('the announcement Send button has its send icon', str_contains($html, "data-send-main data-publish-label=") && str_contains($html, "ic('send','16px')+'<span data-send-label>"));
$check('sidebar navigation is text only for every role', str_contains($html, "      + '<span>'+esc(item.label)+'</span></button>';") && !str_contains($html, "ICONS[item.icon]"));
$check('Logout keeps its icon', str_contains($html, "data-action=\"logout\">'+ic('logout','18px')+'<span>Logout</span></button>"));

// ---- AI writing features removed; request popup trimmed ----
$check('Write with AI is gone from the announcement composer', !str_contains($html, 'Write with AI') && !str_contains($html, 'composer-ai"><summary>'));
$check('Generate with AI is gone from the internal interface', !str_contains($html, 'Generate with AI') && !str_contains($html, 'data-action="generate-ai-message"'));
$check('the AI-only Topic and Details fields are gone from the notification form', !str_contains($html, 'name="aiTopic"') && !str_contains($html, 'name="aiDetails"'));
$popup = $between($html, 'function modalReqDetail(id, kind){', "\n}\n");
$check('request popup no longer shows Notification History', $popup !== '' && !str_contains($popup, 'reqNotifHistoryHtml') && !str_contains($html, 'function reqNotifHistoryHtml'));
$check('request popup has no AI Document Check section at all', $popup !== '' && !str_contains($popup, 'reqAiHtml') && !str_contains($html, 'function reqAiHtml'));
$detail = $between($html, 'function renderDocCheckDetail(x){', "\n}\n");
$check('Document Review labels model suggestions as not verified (current and older results)', str_contains($detail . $dc, " <span class=\"cell-sub\">Suggested from the text, not verified</span>")
    && str_contains($dc, "facts.push(['Looks like the required document?', esc({yes:'Yes', no:'No', unclear:'Unclear'}[d.expected_type_match]||'—')+' <span class=\"cell-sub\">Suggested from the text, not verified</span>']);"));
$check('Document Review gives each problem a short title, what to verify and (when useful) a follow-up', str_contains($dc, 'var DR_ISSUES = {') && str_contains($dc, "'<span class=\"dr-follow\">Follow-up: '")
    && str_contains($dc, 'function drIssueFor(f, d){'));
$check('Document Review: the original first in every card, with Open (new tab) instead of a long report', str_contains($dc, "'<div class=\"dr-doc-body\">'+preview")
    && str_contains($dc, '>Open<span class="sr-only"> ') && !str_contains($dc, 'What to ask the client (if needed)') && !str_contains($dc, 'Open check'));
$check('Document Review: back-arrow icon and View Requirements', str_contains($detail, 'class="dc-back" data-action="dc-back" aria-label="Back to Document Review list"') && str_contains($detail, "ic('chevLeft','20px')")
    && str_contains($detail, '>View Requirements</button>') && !str_contains($dc, 'Open full request'));
$check('Document Check uses no colour-coded badges', !str_contains($dc, 'class="badge ') && !str_contains($dc, "<span class=\"badge '"));
$check('Document Check list rows open the check (keyboard too)', str_contains($dc, '<tr class="dash-row dc-row" data-action="dc-open"'));

// ---- Out for Release opens a pickup notification that Staff review and send ----
$bridge = (string) file_get_contents(dirname(__DIR__) . '/web/assets/php-bridge.js');
$check('Out for Release opens the Notifications page with a pickup draft (after the server confirms)', str_contains($bridge, "if (data.status === 'Rejected' || data.status === 'Out for Release') {")
    && str_contains($bridge, "if (!result.data || result.data.status !== wanted) {") && str_contains($bridge, "kind: wanted === 'Rejected' ? 'rejected' : 'release'"));
$check('the pickup draft is prepared for that request and editable', str_contains($html, "if(raw.kind==='release'){") && str_contains($html, "category: 'Completion / Approval'")
    && str_contains($html, 'message: releaseMessage(name, r.id, pickup)'));
$check('the pickup date field is on the notification form', str_contains($html, '<label for="notification-pickup">Available for pickup on</label><input id="notification-pickup" name="pickupDate" type="date"'));
$check('a pickup notification needs a date before it can be sent', str_contains($bridge, "notice('Choose the date the document will be available for pickup. Nothing was sent.');"));
$check('nothing is sent automatically: the redirect only stores a draft', !preg_match("/kind: wanted[^;]*;\\s*jsonRequest\\('send_notification'/", $bridge));

// ---- Notification page: no draft explanation, no service diagnostics ----
$notif = $between($html, 'function renderNotifications(acct){', "\n}\n");
$check('no automatic draft explanation on the Notifications page', !str_contains($notif, 'Draft prepared for the rejected request') && !str_contains($notif, 'nf-draft'));
$check('no service status diagnostics on the Notifications page', !str_contains($notif, 'Service status') && !str_contains($notif, 'notification-services'));
$check('an unavailable channel is still locked without the status box', str_contains($bridge, "if (!channelStatus) return;") && str_contains($bridge, "label.title = pair[2] + ' is unavailable right now.';"));

// ---- Property owner and requester are separate fields ----
$check('request details show Requester Name and Property Owner Name separately', str_contains($html, "['Requester Name', val(fullName)],")
    && str_contains($html, "['Property Owner Name', d.isOwner ? val(fullName) : '<span class=\"cell-sub\">Not recorded on this request (filed by a representative for the registered owner)</span>'],"));

// ---- Hover feedback (all internal roles) ----
$check('internal hover lifts apply only to real pointers and stop for reduced motion', str_contains($html, 'Hover feedback (Staff, Admin, Head)')
    && str_contains($html, '.btn:hover, .icon-btn:hover, .action-btn:hover, .sidebar .side-link:hover, .dash-stat:hover, .modal-close:hover, .dc-back:hover{ transform:none; }'));

// ---- Announcements for Admin; audience choices; password toggle on the account form ----
$check('Admin navigation has Announcements', str_contains($adminNav, "view:'announcements'"));
$check('Admin page routing renders Announcements', str_contains($adminRoutes, "view==='announcements') body = renderAnnouncements(acct);"));
$check('announcement audience offers Client Interface and Internal Interface (Staff and Admin)', str_contains($html, 'value="client"') && str_contains($html, '> Client Interface</label>')
    && str_contains($html, '> Internal Interface (Staff and Admin)</label>') && str_contains($html, "return picked.length===2 ? 'both' : (picked[0] || '');"));
$check('Create Account password field has the eye toggle', str_contains($html, 'data-action="toggle-login-password" data-target="acct-pass"'));
$check('the toggle works for whichever field it targets', str_contains($html, "document.getElementById(el.getAttribute('data-target') || 'li-pass')"));

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
