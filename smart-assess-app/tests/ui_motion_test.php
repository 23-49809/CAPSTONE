<?php
declare(strict_types=1);

/*
 * UI motion: guards for the animation layer on the public and internal pages.
 *  - entrance animations are applied only after a real change (each render calls
 *    saMotionAfterRender, which compares with the previous render);
 *  - animation classes are removed again once the animation has finished;
 *  - prefers-reduced-motion switches the new animations off, and the scroll reveal
 *    is skipped entirely when reduced motion is requested;
 *  - printing never hides a section that has not been revealed yet;
 *  - popups close through the existing close actions (only delayed by the fade);
 *  - no permanent compositing hints (will-change) are added;
 *  - public hover lifts apply only to real pointers and stop for reduced motion;
 *    the Why Choose cards keep their wording, with decorative icons hidden from
 *    screen readers.
 *
 * Reads the source files only (no database, no network).
 *
 *   php tests/ui_motion_test.php
 *
 * Exit code 0 when every check passes, 1 otherwise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$internal = (string) file_get_contents($root . '/smart-assess-internal.html');
$client = (string) file_get_contents($root . '/smart-assess.html');
$legal = (string) file_get_contents(dirname(__DIR__) . '/web/assets/legal.css');
$results = [];
$check = static function (string $name, bool $passed) use (&$results): void {
    $results[] = [$passed, $name];
};
$between = static function (string $haystack, string $from, string $to): string {
    $a = strpos($haystack, $from);
    if ($a === false) {
        return '';
    }
    $b = strpos($haystack, $to, $a + strlen($from));
    return $b === false ? '' : substr($haystack, $a, $b - $a);
};

foreach (['internal' => $internal, 'public' => $client] as $name => $html) {
    $render = $between($html, "\nfunction render(){", "\n}\n");
    $motionCss = $between($html, 'Motion (animations and transitions only)', '</style>');
    $check("$name: the motion stylesheet is present", $motionCss !== '');
    $check("$name: every render hands over to saMotionAfterRender", $render !== '' && substr_count($render, 'saMotionAfterRender(app);') === substr_count($render, 'bindEvents('));
    $check("$name: the motion styles respect prefers-reduced-motion", str_contains($motionCss, '@media (prefers-reduced-motion: reduce)'));
    $check("$name: no will-change hints are added", !str_contains($motionCss, 'will-change'));
    $check("$name: hover/press transitions use zero-specificity :where() selectors", str_contains($motionCss, ':where(.btn'));
    $check("$name: the existing global reduced-motion rule is kept", str_contains($html, '@media (prefers-reduced-motion: reduce){ *{ animation-duration:.001ms !important; transition-duration:.001ms !important; } }'));
}

// Internal: animation classes are removed after they finish; closing reuses the existing close.
$check('internal: page entrance classes are removed after the animation', str_contains($internal, "function saFlash(el, cls, ms){ el.classList.add(cls); setTimeout(function(){ el.classList.remove(cls); }, ms); }"));
$check('internal: Close still clears the popup through the existing state change', str_contains($internal, "saCloseOverlay(false, function(){ state.modal = null; render(); });"));
$check('internal: Cancel/close on confirmations still clears them', str_contains($internal, "saCloseOverlay(true, function(){ state.confirm = null; render(); });"));
$check('internal: closing skips the fade when reduced motion is requested', str_contains($between($internal, 'function saCloseOverlay(', "\n}"), 'saReducedMotion()'));

// Public: the scroll reveal is optional and never leaves content hidden.
$saMotion = $between($client, 'function saMotionAfterRender(app){', "\nfunction render(){");
$check('public: the scroll reveal is skipped for reduced motion or without IntersectionObserver', str_contains($saMotion, "!saReducedMotion() && 'IntersectionObserver' in window"));
$check('public: revealed elements are handed back to their own styles', str_contains($saMotion, "el.classList.remove('sa-reveal', 'sa-in')"));
$check('public: printing shows sections that were not revealed yet', str_contains($client, '@media print{ .sa-reveal{ opacity:1 !important; transform:none !important; } }'));
$check('public: reduced motion shows sections without the reveal', str_contains($client, '.sa-reveal{ opacity:1 !important; transform:none !important; }'));

// Public polish: hover feedback and the Why Choose section.
$polish = $between($client, 'Client polish: hover feedback and the Why Choose section', 'Motion (animations and transitions only)');
$check('public: the polish stylesheet is present', $polish !== '');
$check('public: hover lifts apply only to devices that can really hover', substr_count($polish, '@media (hover:hover) and (pointer:fine){') === 2
    && !preg_match('/^\s*\.(service-card|why-grid li|btn-primary[^{]*):hover\{[^}]*transform/m', (string) preg_replace('/@media \(hover:hover\) and \(pointer:fine\)\{.*?\n  \}\n/s', '', $polish)));
$check('public: hover lifts stop for reduced motion', str_contains($polish, '@media (prefers-reduced-motion: reduce){')
    && str_contains($polish, '.service-card:hover, .why-grid li:hover, .why-grid li:hover .why-icon, .brand:hover .brand-mark{ transform:none; }'));
$check('public: the polish never changes font sizes or families', !preg_match('/font-(size|family)\s*:/', $polish));
foreach (['Convenient Online Applications', 'Clear Requirements', 'Application Status Updates', 'In-Person Assistance and Claiming'] as $benefit) {
    $check("public: Why Choose keeps \"{$benefit}\"", substr_count($client, "<span class=\"why-icon\" aria-hidden=\"true\">'+ic(") >= 4
        && str_contains($client, "</span><div><h4>{$benefit}</h4>"));
}
$check('public: the Why Choose cards still reveal on scroll', str_contains($client, '.section .why-grid > li'));

// Legal pages.
$check('legal pages: entrance animation respects reduced motion', str_contains($legal, '@media (prefers-reduced-motion: reduce){ .legal-page .section-head, .legal-page .legal-layout{ animation:none; } }'));

$failed = 0;
foreach ($results as [$passed, $name]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . "\n";
    $failed += $passed ? 0 : 1;
}
printf("%d / %d passed\n", count($results) - $failed, count($results));
exit($failed === 0 ? 0 : 1);
