<?php
declare(strict_types=1);

/*
 * Staff page, Announcements by role (no database, no network; the page's own demo accounts). The real
 * page runs in headless Chrome with a prepared state:
 *  - the Assessor Head creates and manages announcements (Create, Edit, Publish/Unpublish, Cancel, Delete);
 *  - Admin can only view them: no Create button or change actions, a View button that opens a read-only
 *    announcement, and the page ignores a change action even if one is forced (the server refuses it too:
 *    applicant_contact_privacy_test).
 *
 *   php tests/announcement_roles_ui_test.php        (needs Google Chrome; exits 2 without it)
 */

$project = dirname(__DIR__, 2);
$internal = (string) file_get_contents($project . '/smart-assess-internal.html');
$api = (string) file_get_contents($project . '/smart-assess-app/web/api.php');
$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 500)];
};
$chrome = getenv('CHROME_BIN') ?: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
if (!is_file($chrome)) {
    fwrite(STDERR, "Google Chrome was not found (set CHROME_BIN). Nothing was checked.\n");
    exit(2);
}
$work = sys_get_temp_dir() . '/sa-ann-roles-' . bin2hex(random_bytes(4));
mkdir($work, 0700);
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($work)));

// ---- Source: the server allows only the Department Head to change announcements ----
$check('server: announcement changes and transitions accept only the Department Head', substr_count($api, "sa_internal_or_fail(['DEPARTMENT_HEAD']);   // only the Assessor Head manages announcements") === 2
    && !str_contains($api, "sa_internal_or_fail(['DEPARTMENT_HEAD', 'ADMIN'])"));
$check('Admin keeps Announcements in the sidebar (to view them)', (bool) preg_match("/admin:\\[[^\\]]*view:'announcements'/s", $internal));

/** Renders the page as $username on $view, runs $js (with out={} and window.__click(action, id)), returns out. Forged buttons go inside #app, where the page's click handler listens. */
$run = static function (string $name, string $username, string $view, string $js) use ($internal, $work, $chrome): array {
    // Test-only hook in the temporary copy: the page's state and render(), which live in the page's own scope.
    $prep = 'var state=(function(){var s=defaultState(); s.session={username:' . json_encode($username) . '}; s.view=' . json_encode($view) . '; return s;})();'
        . ' Object.defineProperty(window, "__S", {get: function(){ return state; }}); window.__render = function(){ render(); };';
    $page = str_replace('var state = loadState();', $prep, $internal) . "\n<style>*{animation:none!important;transition:none!important}</style>"
        . '<script>window.__click=function(action,id){var b=document.createElement("button");b.setAttribute("data-action",action);if(id)b.setAttribute("data-id",id);(document.getElementById("app")||document.body).appendChild(b);b.click();b.remove();};'
        . 'setTimeout(function(){var out={};try{' . $js . '}catch(e){out.error=String(e)}'
        . 'var p=document.createElement("pre");p.id="__res";p.textContent=JSON.stringify(out);document.body.appendChild(p);},600);</script>';
    file_put_contents("{$work}/{$name}.html", $page);
    $dom = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --allow-file-access-from-files --virtual-time-budget=4000 --window-size=1366,1400 --dump-dom '
        . escapeshellarg("file://{$work}/{$name}.html") . ' 2>/dev/null');
    return preg_match('#<pre id="__res">(.*?)</pre>#s', $dom, $m) ? (array) json_decode(html_entity_decode($m[1]), true) : ['error' => 'no output'];
};
$controls = 'var n=function(sel){return document.querySelectorAll(sel).length;};'
    . ' out.rows=n(".table-wrap tbody tr"); out.create=n("[data-action=open-ann-modal]"); out.edit=n("[data-action=edit-ann]"); out.del=n("[data-action=delete-ann]");'
    . ' out.publish=n("[data-action=ann-publish-now]")+n("[data-action=ann-unpublish]")+n("[data-action=ann-cancel]"); out.view=n("[data-action=view-ann]");'
    . ' out.lead=(document.querySelector(".page-head p")||{}).textContent; out.anns=__S.announcements.length; out.firstId=__S.announcements[0] && __S.announcements[0].id;'
    . ' out.firstTitle=__S.announcements[0] && __S.announcements[0].title;';

// ---- Admin: view only ----
$a = $run('admin', 'maricar.admin', 'announcements', $controls
    . ' document.querySelector("[data-action=view-ann]").click(); var m=document.querySelector(".modal");'
    . ' out.modal={open:!!m, title:m && (m.querySelector(".modal-head h3")||{}).textContent, sub:m && (m.querySelector(".modal-head p")||{}).textContent,'
    . ' inputs:m ? m.querySelectorAll("input,textarea,select,form").length : -1, changeButtons:m ? m.querySelectorAll("[data-action=edit-ann],[data-action=delete-ann],[data-action=ann-publish-now],[data-action=save-ann]").length : -1};'
    . ' __click("close-modal"); __S.modal=null; __render();'
    . ' var forced={}; ["open-ann-modal","edit-ann","delete-ann"].forEach(function(act){ __click(act, out.firstId); forced[act]=__S.modal ? __S.modal.type || "confirm" : null; __S.modal=null; __render(); });'
    . ' ["ann-publish-now","ann-unpublish","ann-cancel"].forEach(function(act){ var before=JSON.stringify(__S.announcements); __click(act, out.firstId); forced[act]=JSON.stringify(__S.announcements)===before ? "unchanged" : "CHANGED"; });'
    . ' out.forced=forced; __S.modal={type:"ann-edit", id:null}; __render(); out.composer=document.querySelectorAll(".composer-modal").length; __S.modal=null; __render();');
$check('Admin sees every announcement in a list', ($a['rows'] ?? 0) === ($a['anns'] ?? -1) && ($a['anns'] ?? 0) > 0, $a);
$check('Admin has no Create Announcement button and no Edit, Publish, Unpublish, Cancel or Delete action', ($a['create'] ?? 1) === 0 && ($a['edit'] ?? 1) === 0
    && ($a['del'] ?? 1) === 0 && ($a['publish'] ?? 1) === 0, $a);
$check('Admin has a View button on every announcement, and the page says it is view only', ($a['view'] ?? 0) === ($a['rows'] ?? -1)
    && str_contains((string) ($a['lead'] ?? ''), 'only the Assessor Head can create or change them'), $a);
$check('View opens the announcement read-only: its title, "(view only)", no form, no change button', ($a['modal']['open'] ?? false) === true
    && ($a['modal']['title'] ?? '') === ($a['firstTitle'] ?? '-') && ($a['modal']['sub'] ?? '') === 'Announcement (view only)'
    && ($a['modal']['inputs'] ?? 1) === 0 && ($a['modal']['changeButtons'] ?? 1) === 0, $a['modal'] ?? $a);
$check('a forced change action does nothing for Admin (no composer, no delete confirmation, no status change)', ($a['forced'] ?? null) === ['open-ann-modal' => null, 'edit-ann' => null, 'delete-ann' => null,
    'ann-publish-now' => 'unchanged', 'ann-unpublish' => 'unchanged', 'ann-cancel' => 'unchanged'] && ($a['composer'] ?? 1) === 0, [$a['forced'] ?? null, $a['composer'] ?? null]);
$ad = $run('adminDash', 'maricar.admin', 'dashboard', 'out.create=document.querySelectorAll("[data-action=open-ann-modal]").length;');
$check('the Admin dashboard offers no announcement creation', ($ad['create'] ?? 1) === 0, $ad);

// ---- Assessor Head: manages announcements as before ----
$h = $run('head', 'rodel.head', 'announcements', $controls . ' document.querySelector("[data-action=open-ann-modal]").click(); out.composer=document.querySelectorAll(".composer-modal").length;');
$check('the Assessor Head still has Create Announcement and the change actions, and no View-only list', ($h['create'] ?? 0) >= 1 && ($h['edit'] ?? 0) === ($h['rows'] ?? -1)
    && ($h['del'] ?? 0) === ($h['rows'] ?? -1) && ($h['view'] ?? 1) === 0 && !str_contains((string) ($h['lead'] ?? ''), 'only the Assessor Head'), $h);
$check('Create Announcement still opens the composer for the Assessor Head', ($h['composer'] ?? 0) === 1, $h);
$hd = $run('headDash', 'rodel.head', 'dashboard', 'out.create=document.querySelectorAll("[data-action=open-ann-modal]").length;');
$check('the Assessor Head dashboard keeps New Announcement', ($hd['create'] ?? 0) === 1, $hd);

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
