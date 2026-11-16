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
 *  - no icons are rendered in the internal interface.
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
$check('Document Check opens originals only through request-file.php', str_contains($dc, "/request-file.php?doc=") && !preg_match('#href="(?!/request-file\.php)#', $dc));
$check('Document Check says the AI never approves or rejects', str_contains($dc, 'never approves or rejects a request'));
$check('Document Check says it cannot authenticate documents, signatures or seals', str_contains($dc, 'cannot tell whether a document, signature or seal is genuine'));
$check('Mark as reviewed and Run check again reuse the existing actions', str_contains($dc, 'data-action="ai-mark-reviewed"') && str_contains($dc, 'data-action="ai-requeue"'));

// ---- No icons ----
$check('no icon lookups remain', !str_contains($html, 'ICONS['));
$check('ic() renders nothing', str_contains($html, "function ic(name, size){ return ''; }"));
$check('no × close glyphs remain', !str_contains($html, '&times;') && !str_contains($html, '×'));

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '   ' . $detail : '') . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
