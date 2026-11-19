<?php
declare(strict_types=1);

/*
 * Update Status layout in the Staff request popup (no database, no network, fictional demo data):
 * the page's own demo state is rendered in headless Chrome at desktop and phone widths, and the
 * status select and the Update Status button are measured.
 *
 *  - Desktop/tablet: the select and the button sit on one line, same top and same height.
 *  - Phone: the row spans the card; select and button stack at the full card width.
 *  - The status choices and the form wiring (data-form="update-status") are unchanged.
 *
 *   php tests/update_status_layout_test.php        (needs Google Chrome; exits 2 without it)
 */

$project = dirname(__DIR__, 2);
$html = (string) file_get_contents($project . '/smart-assess-internal.html');
$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 500)];
};
$chrome = getenv('CHROME_BIN') ?: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
if (!is_file($chrome)) {
    fwrite(STDERR, "Google Chrome was not found (set CHROME_BIN). Nothing was checked.\n");
    exit(2);
}

// ---- Source: behaviour unchanged ----
$check('the status choices are unchanged (Processing; Rejected and Approve for the assigned Staff member)', str_contains($html, "var statusChoices = [['Processing','Processing']];")
    && str_contains($html, "if(isAssignedStaff && r.processingStartedAt) statusChoices.push(['Rejected','Rejected'], ['Out for Release','Approve']);"));
$check('the form still posts through data-form="update-status" with a submit button', str_contains($html, '<form class="rd-status-form" data-form="update-status" data-kind="')
    && str_contains($html, "'<button type=\"submit\" class=\"btn btn-primary\">Update Status</button>'"));
$cssFrom = (int) strpos($html, '/* Update Status: select and button share one 40px line');
$css = substr($html, $cssFrom, (int) strpos($html, '.rd-reject{', $cssFrom) - $cssFrom);
$phoneFrom = (int) strpos($html, '/* Phone: the Update Status row spans the card');
$css .= substr($html, $phoneFrom, (int) strpos($html, "\n  }\n", $phoneFrom) - $phoneFrom);
$check('the alignment rules set no font family, size, colour or animation', $css !== '' && !preg_match('/font-(family|size)|color\s*:|background|animation|transition/', (string) preg_replace('#/\*.*?\*/#s', '', $css)), $css);

// ---- Rendered geometry ----
$work = sys_get_temp_dir() . '/sa-update-status-' . bin2hex(random_bytes(4));
mkdir($work, 0700);
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($work)));
$measure = 'var f=document.querySelector(".rd-status-form"); if(f){ var q=function(e){var b=e.getBoundingClientRect(); return [Math.round(b.left),Math.round(b.top),Math.round(b.width),Math.round(b.height)];};'
    . ' out.select=q(f.querySelector("select")); out.button=q(f.querySelector("button")); out.cell=q(f.closest("td")); out.label=q(f.closest("tr").querySelector("th"));'
    . ' out.choices=[].slice.call(f.querySelectorAll("option")).map(function(o){return o.textContent;}); }';
$render = static function (string $name, int $width, string $prep) use ($html, $work, $chrome, $measure): array {
    $state = 'var state=(function(){var s=defaultState(); s.session={username:"jessica.staff"}; s.accounts.forEach(function(a){ if(a.username==="jessica.staff") a.id=3; });'
        . $prep . ' return s;})();';
    $page = str_replace('var state = loadState();', $state, $html)
        . "\n<style>*{animation:none!important;transition:none!important}</style>"
        . '<script>setTimeout(function(){var out={};try{' . $measure . '}catch(e){out.error=String(e)}var p=document.createElement("pre");p.id="__out";p.style.display="none";p.textContent=JSON.stringify(out);document.body.appendChild(p);},400);</script>';
    file_put_contents("{$work}/{$name}.html", $page);
    // Chrome's window cannot be narrower than 500px, so phone widths are measured inside an iframe.
    file_put_contents("{$work}/{$name}-frame.html", '<html><body style="margin:0"><iframe id="f" src="' . $name . '.html" style="border:0;width:' . $width . 'px;height:900px"></iframe>'
        . '<script>setTimeout(function(){var p=document.getElementById("f").contentDocument.getElementById("__out");var o=document.createElement("pre");o.id="__res";o.textContent=p?p.textContent:"{}";document.body.appendChild(o);},1500)</script></body></html>');
    $cmd = escapeshellarg($chrome) . ' --headless=new --disable-gpu --hide-scrollbars --allow-file-access-from-files --virtual-time-budget=4000'
        . ' --window-size=' . max($width, 500) . ',900 --dump-dom ' . escapeshellarg("file://{$work}/{$name}-frame.html") . ' 2>/dev/null';
    $dom = (string) shell_exec($cmd);
    return preg_match('#<pre id="__res">(.*?)</pre>#s', $dom, $m) ? (array) json_decode(html_entity_decode($m[1]), true) : ['error' => 'no output'];
};
$unassigned = 'var r=s.docRequests[0]; r.status="Received"; r.processingStaffId=null; r.processingStartedAt=null; s.modal={type:"doc-detail",id:r.id};';
$assigned = 'var r=s.docRequests[0]; r.status="Processing"; r.processingStaffId=3; r.processingStaffName="Jessica Ann M. Reyes"; r.processingStartedAt="2026-11-19 09:00:00"; s.modal={type:"doc-detail",id:r.id};';

foreach (['desktop' => 1366, 'laptop' => 1100, 'tablet' => 820] as $label => $width) {
    foreach (['unassigned' => $unassigned, 'assigned' => $assigned] as $case => $prep) {
        $g = $render("{$label}-{$case}", $width, $prep);
        $s = $g['select'] ?? null;
        $b = $g['button'] ?? null;
        $ok = $s && $b && abs($s[1] - $b[1]) <= 1 && abs($s[3] - $b[3]) <= 1 && $s[3] >= 38 && $b[0] >= $s[0] + $s[2]
            && $b[0] + $b[2] <= ($g['cell'][0] + $g['cell'][2]) && abs(($s[1] + $s[3] / 2) - ($g['label'][1] + $g['label'][3] / 2)) <= 2;
        $check("{$label} ({$width}px, {$case}): select and Update Status share one line, same top and height, centred with the row label, inside the cell", $ok, $g);
    }
}
$g = $render('assigned-choices', 1366, $assigned);
$check('the assigned Staff member still sees Processing, Rejected and Approve', ($g['choices'] ?? null) === ['Choose a new status', 'Processing', 'Rejected', 'Approve'], $g);
foreach ([375, 414] as $width) {
    $g = $render("phone-{$width}", $width, $unassigned);
    $s = $g['select'] ?? null;
    $b = $g['button'] ?? null;
    $c = $g['cell'] ?? null;
    $ok = $s && $b && $c && $s[0] === $b[0] && abs($s[2] - $b[2]) <= 1 && $b[1] >= $s[1] + $s[3] && $s[3] >= 44 && $b[3] >= 44
        && $s[2] >= $c[2] - 30 && ($g['label'][1] + $g['label'][3]) <= $c[1] + 1;
    $check("phone ({$width}px): the row spans the card, label above, select and button stacked at full width (44px touch height)", $ok, $g);
}

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
