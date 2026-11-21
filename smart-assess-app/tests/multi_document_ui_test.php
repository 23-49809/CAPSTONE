<?php
declare(strict_types=1);

/*
 * Client portal: several documents in one Document Request application (no database, no network,
 * fictional demo data). The real page runs in headless Chrome; a test-only hook (added to a
 * temporary copy, never to the page itself) reaches its state.
 *
 *  - The requester heading; adding, removing and limiting document cards; no duplicate documents;
 *    every card needs a type and a purpose; missing uploads block submission; the shared uploads,
 *    review list, receipt and tracking show each document; phones fit without sideways scrolling.
 *
 *   php tests/multi_document_ui_test.php        (needs Google Chrome; exits 2 without it)
 */

$project = dirname(__DIR__, 2);
$html = (string) file_get_contents($project . '/smart-assess.html');
$bridge = (string) file_get_contents($project . '/smart-assess-app/web/assets/php-bridge.js');
$results = [];
$check = static function (string $name, bool $passed, mixed $detail = '') use (&$results): void {
    $results[] = [$passed, $name, $passed ? '' : substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 600)];
};
$chrome = getenv('CHROME_BIN') ?: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
if (!is_file($chrome)) {
    fwrite(STDERR, "Google Chrome was not found (set CHROME_BIN). Nothing was checked.\n");
    exit(2);
}

// ---- Source ----
$check('the requester heading reads "Enter Requester Personal Information" with "Requester" emphasised', str_contains($html,
    '<h2><span>Enter <span class="req-word">Requester</span> Personal Information</span></h2>'));
$check('the requester fields keep their names (first, middle, last name, contact, email, address)', (bool) preg_match_all('/data-bind="(firstName|middleName|lastName|contact|email|addressLine)"/', $html, $m)
    && count(array_unique($m[1])) === 6);
$check('the bridge sends every chosen document (type and purpose) and the first one as the single fields', str_contains($bridge, 'payload.document_requests = form.documentRequests.map(function (item) {')
    && str_contains($bridge, 'payload.document_type = payload.document_requests[0].document_type;'));
$check('the page limit matches the server limit (5)', str_contains($html, 'const MAX_DOCUMENT_REQUESTS = 5;')
    && str_contains((string) file_get_contents($project . '/smart-assess-app/app/services/DocumentRequirements.php'), 'public const MAX_DOCUMENTS_PER_APPLICATION = 5;'));
$check('tracking accepts a document\'s own reference (DR-2026-AB12CD34-2)', str_contains($html, 'const TRACK_REF_RE = /^(DR|LT)-\d{4}-[A-Z0-9]{4,12}(-[1-9])?$/;'));

// ---- Rendered ----
$hook = 'window.__SA = {get state(){ return state; }, render:function(){ render(); }, setDocItems:setDocItems, docItems:docItems, nextStep:function(){ nextStep(); },'
    . ' submitRequest:function(){ submitRequest(); }, confirm:function(){ confirmSubmitRequest(); }, applyDraft:function(f,st,mx,fields,files){ draftApplyFields(f,st,mx,fields,files); }, startFlow:function(f){ startFlow(f, true); }, draftFields:function(){ return draftFields(); }};' . "\n" . 'window.SMART_ASSESS_FORM = function(){';
$page0 = str_replace('window.SMART_ASSESS_FORM = function(){', $hook, $html);
$check('the test hook could be added to the temporary copy', $page0 !== $html);
$work = sys_get_temp_dir() . '/sa-multi-doc-ui-' . bin2hex(random_bytes(4));
mkdir($work, 0700);
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($work)));
$run = static function (string $name, string $js, int $width = 1366) use ($page0, $work, $chrome): array {
    $page = $page0 . "\n<style>*{animation:none!important;transition:none!important}</style>"
        . '<script>setTimeout(function(){var out={};try{var S=__SA, state=S.state, fd=state.formData;' . $js . '}catch(e){out.error=String(e)}'
        . 'var p=document.createElement("pre");p.id="__out";p.style.display="none";p.textContent=JSON.stringify(out);document.body.appendChild(p);},300);</script>';
    file_put_contents("{$work}/{$name}.html", $page);
    file_put_contents("{$work}/{$name}-frame.html", '<html><body style="margin:0"><iframe id="f" src="' . $name . '.html" style="border:0;width:' . $width . 'px;height:1400px"></iframe>'
        . '<script>setTimeout(function(){var p=document.getElementById("f").contentDocument.getElementById("__out");var o=document.createElement("pre");o.id="__res";o.textContent=p?p.textContent:"{}";document.body.appendChild(o);},1500)</script></body></html>');
    $dom = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --hide-scrollbars --allow-file-access-from-files --virtual-time-budget=4000 --window-size='
        . max($width, 500) . ',1400 --dump-dom ' . escapeshellarg("file://{$work}/{$name}-frame.html") . ' 2>/dev/null');
    return preg_match('#<pre id="__res">(.*?)</pre>#s', $dom, $mm) ? (array) json_decode(html_entity_decode($mm[1]), true) : ['error' => 'no output'];
};
$base = 'state.view="wizard"; state.flow="docreq"; state.maxStep=4; fd.firstName="Rosa"; fd.lastName="Fictional"; fd.contact="+639170000001"; fd.addressLine="1 Demo St.";'
    . ' fd.requesterBarangay="POBLACION"; fd.barangay="POBLACION"; fd.arp="14-0001-00045";';
$cards = 'out.cards=document.querySelectorAll(".doc-req-card").length; out.remove=document.querySelectorAll("[data-action=remove-doc-request]").length; out.add=!!document.querySelector("[data-action=add-doc-request]");';

$g = $run('heading', $base . 'state.step=1; S.render(); out.h2=document.querySelector(".wizard-card-head h2").textContent; out.word=document.querySelector(".req-word").textContent;');
$check('Step 1 shows "Enter Requester Personal Information"', ($g['h2'] ?? '') === 'Enter Requester Personal Information' && ($g['word'] ?? '') === 'Requester', $g);

$g = $run('one', $base . 'state.step=2; S.render();' . $cards);
$check('a new application starts with one document card, no Remove, and an Add Another Document Request button', ($g['cards'] ?? 0) === 1 && ($g['remove'] ?? 1) === 0 && ($g['add'] ?? false) === true, $g);

$g = $run('add', $base . 'state.step=2; S.setDocItems(fd,[{documentType:"Certification of Assessment",purpose:"Personal Copy"}]); S.render();'
    . ' document.querySelector("[data-action=add-doc-request]").click();' . $cards
    . ' out.items=S.docItems(S.state.formData).length; out.focused=document.activeElement && document.activeElement.id;'
    . ' out.disabled=[].slice.call(document.querySelectorAll("#f-doc-1-type option[disabled]")).map(function(o){return o.value;});'
    . ' out.titles=[].slice.call(document.querySelectorAll(".doc-req-head h3")).map(function(h){return h.textContent;});');
$check('Add Another Document Request adds a second card and moves focus to it', ($g['cards'] ?? 0) === 2 && ($g['items'] ?? 0) === 2 && ($g['focused'] ?? '') === 'f-doc-1-type', $g);
$check('each card is numbered and can be removed; a document already chosen cannot be chosen again', ($g['remove'] ?? 0) === 2
    && ($g['titles'] ?? null) === ['1Document Request 1 of 2', '2Document Request 2 of 2'] && ($g['disabled'] ?? null) === ['Certification of Assessment'], $g);

$g = $run('remove', $base . 'state.step=2; S.setDocItems(fd,[{documentType:"Certification of Assessment",purpose:"Personal Copy"},{documentType:"Certification of No Liens and Encumbrances",purpose:"For Titling"},{documentType:"Certified True Copy of Tax Declaration (CTC-TD)",purpose:"For Transfer"}]); S.render();'
    . ' document.querySelector("[data-action=remove-doc-request][data-index=\'1\']").click();' . $cards
    . ' out.left=S.docItems(S.state.formData).map(function(i){return i.documentType;}); out.first=[S.state.formData.documentType, S.state.formData.purpose];');
$check('Remove takes out only that document; the others keep their choices', ($g['cards'] ?? 0) === 2
    && ($g['left'] ?? null) === ['Certification of Assessment', 'Certified True Copy of Tax Declaration (CTC-TD)'] && ($g['first'] ?? null) === ['Certification of Assessment', 'Personal Copy'], $g);

$g = $run('max', $base . 'state.step=2; S.setDocItems(fd,[0,1,2,3,4].map(function(i){return {documentType:["Certified True Copy of Tax Declaration (CTC-TD)","Certification of No/With Existing Improvement","Certification of Property/No Property Holdings","Certification of No Liens and Encumbrances","Certification of Assessment"][i],purpose:"Personal Copy"};})); S.render();' . $cards
    . ' out.limit=!!document.querySelector(".doc-req-limit");');
$check('with 5 documents there is no Add button; the page says the application is full', ($g['cards'] ?? 0) === 5 && ($g['add'] ?? true) === false && ($g['limit'] ?? false) === true, $g);

$g = $run('validate', $base . 'state.step=2; S.setDocItems(fd,[{documentType:"Certification of Assessment",purpose:"Personal Copy"},{documentType:"",purpose:""}]); S.render(); S.nextStep();'
    . ' out.step=S.state.step; out.errors=Object.keys(S.state.errors); out.msg=(document.getElementById("err-doc-1-type")||{}).textContent;'
    . ' S.setDocItems(S.state.formData,[{documentType:"Certification of Assessment",purpose:"Personal Copy"},{documentType:"Certification of Assessment",purpose:"For Titling"}]); S.state.errors={}; S.render(); S.nextStep();'
    . ' out.dup=(document.getElementById("err-doc-1-type")||{}).textContent; out.dupStep=S.state.step;'
    . ' S.setDocItems(S.state.formData,[{documentType:"Certification of Assessment",purpose:"Personal Copy"},{documentType:"Certification of No Liens and Encumbrances",purpose:""}]); S.state.errors={}; S.render(); S.nextStep();'
    . ' out.purpose=(document.getElementById("err-doc-1-purpose")||{}).textContent;'
    . ' S.setDocItems(S.state.formData,[{documentType:"Certification of Assessment",purpose:"Personal Copy"},{documentType:"Certification of No Liens and Encumbrances",purpose:"For Titling"}]); S.state.errors={}; S.render(); S.nextStep(); out.okStep=S.state.step;');
$check('an empty card stops Next with a message on that card', ($g['step'] ?? 0) === 2 && ($g['errors'] ?? null) === ['doc-1-type'] && ($g['msg'] ?? '') === 'Please select a document type.', $g);
$check('the same document twice is refused on the second card', ($g['dupStep'] ?? 0) === 2 && str_contains((string) ($g['dup'] ?? ''), 'already in your application'), $g);
$check('a card without a purpose is refused; complete cards continue to Step 3', ($g['purpose'] ?? '') === 'Please select a purpose.' && ($g['okStep'] ?? 0) === 3, $g);

$two = 'S.setDocItems(fd,[{documentType:"Certification of Assessment",purpose:"Personal Copy"},{documentType:"Certification of No Liens and Encumbrances",purpose:"For Titling"}]);';
$g = $run('uploads', $base . $two . 'state.step=3; S.render(); out.shared=(document.querySelector(".doc-req-shared")||{}).textContent;'
    . ' S.submitRequest(); out.afterSubmit=[S.state.step, S.state.submitConfirmation, Object.keys(S.state.errors)];'
    . ' S.state.formData.isOwner=false; S.state.formData.files={ownerId:{name:"id.png",size:10,type:"image/png",valid:true}}; S.state.errors={}; S.submitRequest();'
    . ' out.rep=[S.state.step, S.state.submitConfirmation, Object.keys(S.state.errors).sort()];');
$check('Step 3 says, in one short line, that the uploads are used for every chosen document', ($g['shared'] ?? '') === 'These files are used for all 2 documents.', $g);
// Each ID needs its type selected and both its sides (front and back), plus the letter for a representative.
$check('a missing upload blocks submission (owner ID type, front and back; for a representative also their ID and the authorization letter)',
    ($g['afterSubmit'] ?? null) === [3, false, ['ownerIdType', 'ownerId', 'ownerIdBack']]
    && ($g['rep'] ?? null) === [3, false, ['authLetter', 'ownerIdBack', 'ownerIdType', 'requesterId', 'requesterIdBack', 'requesterIdType']], $g);

$g = $run('review', $base . $two . 'fd.ownerIdType="philhealth_id"; fd.files={ownerId:{name:"id.png",size:10,type:"image/png",valid:true},ownerIdBack:{name:"id-back.png",size:10,type:"image/png",valid:true}}; state.step=4; S.render();'
    . ' out.sections=[].slice.call(document.querySelectorAll(".rv-sec th")).map(function(e){return e.textContent;});'
    . ' out.rows=[].slice.call(document.querySelectorAll(".review-table tbody tr:not(.rv-sec)")).map(function(r){return r.textContent;}).filter(function(t){return /^Document \d/.test(t);});'
    . ' S.submitRequest(); out.modal=S.state.submitConfirmation;');
$check('the review lists every document with its own purpose; uploads are marked as shared', ($g['sections'] ?? null) === ['Personal Information', 'Property Information', 'Documents Requested (2)', 'Uploaded IDs and Documents (used for all documents)']
    && ($g['rows'] ?? null) === ['Document 1Certification of AssessmentPurpose: Personal Copy', 'Document 2Certification of No Liens and EncumbrancesPurpose: For Titling'], $g);
$check('a complete application opens the one final Submit confirmation', ($g['modal'] ?? false) === true, $g);
$g = $run('reviewOne', $base . 'S.setDocItems(fd,[{documentType:"Certification of Assessment",purpose:"Personal Copy"}]); fd.ownerIdType="philhealth_id"; fd.files={ownerId:{name:"id.png",size:10,type:"image/png",valid:true},ownerIdBack:{name:"id-back.png",size:10,type:"image/png",valid:true}}; state.step=4; S.render();'
    . ' out.sections=[].slice.call(document.querySelectorAll(".rv-sec th")).map(function(e){return e.textContent;});');
$check('one document keeps the original review wording', in_array('Document Request Details', $g['sections'] ?? [], true) && in_array('Uploaded IDs and Documents', $g['sections'] ?? [], true), $g);

$g = $run('demo', $base . $two . 'fd.ownerIdType="philhealth_id"; fd.files={ownerId:{name:"id.png",size:10,type:"image/png",valid:true},ownerIdBack:{name:"id-back.png",size:10,type:"image/png",valid:true}}; state.step=4; S.render(); S.confirm(); var r=S.state.lastSubmission;'
    . ' out.app=[r.isApplication, r.items.length, r.items.map(function(i){return i.id.slice(-2);}), r.items.map(function(i){return i.docType;}), r.items.map(function(i){return i.status;})];'
    . ' out.receipt=[].slice.call(document.querySelectorAll(".rcpt-docs li")).map(function(li){return li.textContent;}); out.ref=(document.querySelector("[data-ref-no]")||{}).textContent;');
$check('submitting (demo mode) gives one tracking number with each document under it (-1, -2), each Received', ($g['app'][0] ?? false) === true && ($g['app'][1] ?? 0) === 2
    && ($g['app'][2] ?? null) === ['-1', '-2'] && ($g['app'][3] ?? null) === ['Certification of Assessment', 'Certification of No Liens and Encumbrances'] && ($g['app'][4] ?? null) === ['Received', 'Received'], $g);
$check('the receipt shows the one tracking number and lists each document with its own reference', count($g['receipt'] ?? []) === 2
    && str_starts_with((string) ($g['receipt'][0] ?? ''), 'Certification of Assessment') && str_ends_with((string) ($g['receipt'][1] ?? ''), '-2') && !str_ends_with((string) ($g['ref'] ?? ''), '-1'), $g);

$track = '{id:"DR-2026-AB12CD34",flow:"docreq",isApplication:true,applicant:"R. F.",applicantInitialsOnly:true,submittedAt:"2026-11-19 10:00:00",items:['
    . '{id:"DR-2026-AB12CD34-1",flow:"docreq",docType:"Certification of Assessment",status:"Out for Release",timestamps:{"Received":"2026-11-19 10:00:00","Out for Release":"2026-11-19 15:00:00"},requirementComplete:true,missing:[]},'
    . '{id:"DR-2026-AB12CD34-2",flow:"docreq",docType:"Certification of No Liens and Encumbrances",status:"Processing",timestamps:{"Received":"2026-11-19 10:00:00","Processing":"2026-11-19 12:00:00"},requirementComplete:false,missing:["Valid Government-Issued ID"]}]}';
$g = $run('track', 'state.view="track"; state.track={query:"DR-2026-AB12CD34",searched:true,result:' . $track . '}; S.render();'
    . ' out.docs=[].slice.call(document.querySelectorAll(".trk-doc")).map(function(c){return [c.querySelector(".trk-doc-title").textContent, c.querySelector(".trk-doc-ref").textContent, c.querySelector(".trk-pill").textContent, !!c.querySelector(".trk-note")];});'
    . ' out.ref=(document.querySelector(".trk-ref")||{}).textContent; out.initials=!!document.querySelector(".trk-dd-note");');
$check('tracking an application shows each document with its own status, reference and requirement note', ($g['ref'] ?? '') === 'DR-2026-AB12CD34'
    && ($g['docs'] ?? null) === [['Certification of Assessment', 'DR-2026-AB12CD34-1', 'Out for Release', false], ['Certification of No Liens and Encumbrances', 'DR-2026-AB12CD34-2', 'Processing', true]]
    && ($g['initials'] ?? false) === true, $g);
$g = $run('trackItem', 'state.view="track"; state.track={query:"DR-2026-AB12CD34-2",searched:true,result:{id:"DR-2026-AB12CD34-2",flow:"docreq",docType:"Certification of Assessment",status:"Received",timestamps:{},requirementComplete:true,missing:[],applicant:"R. F.",applicantInitialsOnly:true,submittedAt:"2026-11-19 10:00:00",applicationRef:"DR-2026-AB12CD34",itemNo:2,itemCount:3}}; S.render();'
    . ' out.text=document.querySelector(".trk-grid").textContent;');
$check('tracking one document says which application it belongs to', str_contains((string) ($g['text'] ?? ''), 'ApplicationDR-2026-AB12CD34Document 2 of 3. Track DR-2026-AB12CD34 to see every document.'), $g);

// ---- A saved draft restored in the page ----
$g = $run('draft', 'S.applyDraft("docreq", 2, 3, {firstName:"Rosa", lastName:"Fictional", barangay:"POBLACION", arp:"14-0001-00045", termsToken:"x", documentRequests:['
    . '{documentType:"Certification of Assessment", purpose:"For Titling"}, {documentType:"Certification of No Liens and Encumbrances", purpose:"Personal Copy"}]}, []); S.render();'
    . ' out.step=S.state.step; out.view=S.state.view; out.cards=document.querySelectorAll(".doc-req-card").length;'
    . ' out.values=[0,1].map(function(i){ return [document.getElementById("f-doc-"+i+"-type").value, (document.getElementById("f-doc-"+i+"-purpose")||{}).value]; });'
    . ' out.first=[S.state.formData.documentType, S.state.formData.purpose];');
$check('a saved draft with two documents reopens at Step 2 with both cards, types and purposes restored', ($g['view'] ?? '') === 'wizard' && ($g['step'] ?? 0) === 2 && ($g['cards'] ?? 0) === 2
    && ($g['values'] ?? null) === [['Certification of Assessment', 'For Titling'], ['Certification of No Liens and Encumbrances', 'Personal Copy']]
    && ($g['first'] ?? null) === ['Certification of Assessment', 'For Titling'], $g);
$g = $run('draftOld', 'S.applyDraft("docreq", 2, 2, {firstName:"Rosa", documentType:"Certification of Assessment", purpose:"For Transfer", termsToken:"x"}, []); S.render();'
    . ' out.cards=document.querySelectorAll(".doc-req-card").length; out.values=[document.getElementById("f-doc-0-type").value, document.getElementById("f-doc-0-purpose").value];');
$check('an older draft (one document, saved before this change) reopens as one card with its type and purpose', ($g['cards'] ?? 0) === 1
    && ($g['values'] ?? null) === ['Certification of Assessment', 'For Transfer'], $g);

foreach ([1366 => 'desktop', 390 => 'phone'] as $width => $label) {
    $g = $run('layout-' . $label, $base . 'state.step=2; S.setDocItems(fd,[{documentType:"Certified True Copy of Tax Declaration (CTC-TD)",purpose:"Personal Copy"},{documentType:"Certification of Property/No Property Holdings",purpose:"For Building Permit"},{documentType:"",purpose:""}]); S.render();'
        . ' var w=document.querySelector(".wizard-card").getBoundingClientRect(); out.wizard=[Math.round(w.left),Math.round(w.right)];'
        . ' out.over=[].slice.call(document.querySelectorAll(".doc-req-card, .doc-req-card select, .doc-req-remove, .doc-req-add")).filter(function(e){var b=e.getBoundingClientRect(); return b.right>w.right+1 || b.left<w.left-1;}).length;'
        . ' out.scroll=document.documentElement.scrollWidth; out.view=window.innerWidth;', $width);
    $check("{$label} ({$width}px): every card, select and button fits inside the form; no sideways scrolling", ($g['over'] ?? 1) === 0 && ($g['scroll'] ?? 9999) <= ($g['view'] ?? 0), $g);
}

// ---- Head report labels (Staff page, Head account, demo data; nothing new for Staff performance) ----
$internal = (string) file_get_contents($project . '/smart-assess-internal.html');
$prep = 'var state=(function(){var s=defaultState(); s.session={username:"rodel.head"}; s.view="reports";'
    . ' s.docRequests[0].itemNo=1; s.docRequests[0].itemCount=2; s.docRequests[0].applicationRef="DR-2026-AB12CD34";'
    . ' s.docRequests[1].itemNo=2; s.docRequests[1].itemCount=2; s.docRequests[1].applicationRef="DR-2026-AB12CD34"; s.docRequests[1].submitted=s.docRequests[0].submitted;'
    . ' var d=String(s.docRequests[0].submitted).slice(0,7).split("-"); s.sysReport={v2:true, month:Number(d[1]), year:Number(d[0]), draftMonth:Number(d[1]), draftYear:Number(d[0]), type:"all", data:null, loading:false, error:""}; return s;})();';
$ipage = str_replace('var state = loadState();', $prep, $internal) . "\n<style>*{animation:none!important;transition:none!important}</style>"
    . '<script>setTimeout(function(){var out={};try{out.cards=[].slice.call(document.querySelectorAll(".sr-stat")).map(function(c){return [c.querySelector(".sr-stat-label").textContent, c.querySelector(".sr-stat-num").textContent, (c.querySelector(".sr-stat-detail")||{}).textContent||""];});'
    . 'out.staffHead=[].slice.call(document.querySelectorAll(".sr-staff-table th")).map(function(t){return t.textContent;}); out.note=(document.querySelector(".sr-chart-note")||{}).textContent;}catch(e){out.error=String(e)}'
    . 'var p=document.createElement("pre");p.id="__res";p.textContent=JSON.stringify(out);document.body.appendChild(p);},800);</script>';
file_put_contents("{$work}/report.html", $ipage);
$dom = (string) shell_exec(escapeshellarg($chrome) . ' --headless=new --disable-gpu --allow-file-access-from-files --virtual-time-budget=4000 --window-size=1366,1400 --dump-dom ' . escapeshellarg("file://{$work}/report.html") . ' 2>/dev/null');
$g = preg_match('#<pre id="__res">(.*?)</pre>#s', $dom, $mm) ? (array) json_decode(html_entity_decode($mm[1]), true) : ['error' => 'no output'];
$labels = array_column($g['cards'] ?? [], 0);
$byLabel = array_combine($labels ?: [], $g['cards'] ?? []) ?: [];
$check('the Head report shows applications and individual document requests apart, each clearly labelled', $labels === ['Document Request Applications', 'Individual Document Requests', 'Total Land Transfer', 'Total Applications'], $g);
$apps = (int) ($byLabel['Document Request Applications'][1] ?? -1);
$docs = (int) ($byLabel['Individual Document Requests'][1] ?? -1);
$check('a two-document application counts as one application and two document requests (one fewer application than documents here)', $docs > 0 && $apps === $docs - 1
    && str_contains((string) ($byLabel['Document Request Applications'][2] ?? ''), $docs . ' individual document request'), $g);
$check('the chart says each document of an application counts as its own request', str_contains((string) ($g['note'] ?? ''), 'Each document in a multi-document application is counted as its own request.'), $g);
$check('Staff performance keeps its figures, only labelled by what they count (no application figure)', in_array('Document Requests Processed (individual documents)', $g['staffHead'] ?? [], true)
    && in_array('Total Requests Completed (individual requests)', $g['staffHead'] ?? [], true) && !preg_grep('/application/i', $g['staffHead'] ?? []), $g['staffHead'] ?? null);
$check('the printed report and the report details use the same labels', str_contains($internal, "'Applications: <b>'+applications+'</b> &nbsp;·&nbsp; Individual document requests submitted: <b>'")
    && str_contains($internal, "[['Applications', s.applications==null ? s.total : s.applications], ['Individual Document Requests', s.total]]")
    && str_contains($internal, '<th class="num">Total Requests Completed (individual requests)</th>'));

// ---- Compact selection and upload layout (spacing and text only; same fonts, colours and behaviour) ----
$compactJs = 'var cards=[].slice.call(document.querySelectorAll(".doc-req-card")); var t0=document.getElementById("f-doc-0-type").getBoundingClientRect(), p0=document.getElementById("f-doc-0-purpose").getBoundingClientRect();'
    . ' var heads=[].slice.call(document.querySelectorAll(".doc-req-head")), beside=heads.every(function(h){ var a=h.querySelector("h3").getBoundingClientRect(), b=h.querySelector(".doc-req-remove").getBoundingClientRect(); return Math.abs(a.top+a.height/2-(b.top+b.height/2))<4; });'
    . ' var oneLine=heads.every(function(h){ var e=h.querySelector("h3"); return e.getBoundingClientRect().height < parseFloat(getComputedStyle(e).fontSize)*2; });'
    . ' out.c=[cards.length, document.querySelectorAll(".doc-req-needs").length, (document.querySelector(".doc-req-needs")||{}).textContent, Math.abs(t0.top-p0.top)<2, beside && oneLine,'
    . ' document.documentElement.scrollWidth<=window.innerWidth, getComputedStyle(cards[0]).paddingTop, getComputedStyle(document.querySelector(".doc-req-card .field label")).fontSize, getComputedStyle(document.querySelector(".doc-req-card select")).fontSize,'
    . ' getComputedStyle(document.querySelector("#f-barangay, .field select:not([data-doc-item])")||document.body).fontSize, document.querySelector(".wizard-card-head p").textContent];';
foreach (['desktop' => 1366, 'phone' => 390, 'small' => 320] as $device => $width) {
    $c = $run('compact' . $device, $base . $two . 'state.step=2; S.render();' . $compactJs, $width)['c'] ?? [];
    $check("Step 2 is compact ({$device}): two cards, one shared requirements line, " . ($device === 'small' ? 'titles on one line' : 'each title on one line with Remove beside it') . ', no sideways scrolling', ($c[0] ?? 0) === 2 && ($c[1] ?? 0) === 1
        && ($c[2] ?? '') === 'Requirements (all documents): Property Owner Valid ID, uploaded in Step 3.' && ($device === 'small' || ($c[4] ?? false) === true) && ($c[5] ?? false) === true && ($c[6] ?? '') === '12px', $c);
    if ($device === 'small') {
        $titles = $run('compactTitles', $base . $two . 'state.step=2; S.render(); out.t=[].slice.call(document.querySelectorAll(".doc-req-head h3")).map(function(e){ return e.getBoundingClientRect().height < parseFloat(getComputedStyle(e).fontSize)*2; });', $width);
        $check('on a 320px screen each title stays on one line (Remove moves below it)', ($titles['t'] ?? null) === [true, true], $titles);
    }
    $check("Step 2 keeps the existing text sizes ({$device}): labels 13px, choices the same size as the other form fields", ($c[7] ?? '') === '13px' && ($c[8] ?? 'a') === ($c[9] ?? 'b'), $c);
    $check("Step 2 shows document type and purpose " . ($device === 'desktop' ? 'side by side on a wide screen' : 'one above the other on a phone'), ($c[3] ?? null) === ($device === 'desktop'), $c);
    if ($device === 'desktop') {
        $check('the Step 2 lead is one short line', ($c[10] ?? '') === 'Up to 5 documents per application, one tracking number.', $c);
    }
}
$one = $run('compactOne', $base . 'state.step=2; S.setDocItems(fd,[{documentType:"Certification of Assessment",purpose:"Personal Copy"}]); S.render(); out.needs=[document.querySelectorAll(".doc-req-needs").length, (document.querySelector(".doc-req-needs")||{}).textContent];');
$check('one document: one requirements line without "(all documents)"', ($one['needs'] ?? null) === [1, 'Requirements: Property Owner Valid ID, uploaded in Step 3.'], $one);
$upJs = 'var b=document.querySelector(".id-card-block"), sd=document.querySelector(".id-side"), pv=document.querySelector(".id-side-preview");'
    . ' out.u=[getComputedStyle(b).paddingTop, getComputedStyle(sd).paddingTop, Math.round(pv.getBoundingClientRect().height)<=120, document.documentElement.scrollWidth<=window.innerWidth, getComputedStyle(document.querySelector(".id-side-label")).fontSize];';
foreach (['desktop' => 1366, 'phone' => 390] as $device => $width) {
    $u = $run('compactUp' . $device, $base . $two . 'state.step=3; S.render();' . $upJs, $width)['u'] ?? [];
    $check("Step 3 ID upload is compact ({$device}): tighter card and sides, previews at most 120px high, no sideways scrolling, same label size", ($u[0] ?? '') === '12px' && ($u[1] ?? '') === '10px'
        && ($u[2] ?? false) === true && ($u[3] ?? false) === true && ($u[4] ?? '') === ($device === 'desktop' ? '14px' : '13.5px'), $u);
}

// ---- A server that cannot yet save several documents in one application (its database update has not run) ----
$oneOnly = 'window.SMART_ASSESS_CONFIG={multiDocument:false};';
$g = $run('oneOnly', $oneOnly . $base . 'state.step=2; S.setDocItems(fd,[{documentType:"Certification of Assessment",purpose:"Personal Copy"}]); S.render();' . $cards
    . ' out.hint=(document.querySelector(".doc-req-one")||{}).textContent; out.lead=document.querySelector(".wizard-card-head p").textContent; S.nextStep(); out.step=S.state.step;');
$check('when the server takes one document per application, the page offers no Add button and says to apply separately', ($g['cards'] ?? 0) === 1 && ($g['add'] ?? true) === false
    && ($g['hint'] ?? '') === "One document per application for now.\nSubmit other documents separately." && ($g['lead'] ?? '') === 'Choose the document you need.', $g);
$check('one document still continues normally on such a server', ($g['step'] ?? 0) === 3, $g);
$g = $run('oneOnlyDraft', $oneOnly . $base . 'state.step=2; ' . $two . ' S.render(); S.nextStep(); out.step=S.state.step; out.msg=(document.getElementById("err-doc-multi")||{}).textContent;'
    . ' S.state.step=4; S.render(); S.submitRequest(); out.submit=[S.state.step, S.state.submitConfirmation];'
    . ' out.notices=document.querySelectorAll(".doc-req-one").length;'
    . ' document.querySelector("[data-action=remove-doc-request][data-index=\'1\']").click(); out.left=S.docItems(S.state.formData).length; out.errAfter=!!document.getElementById("err-doc-multi");'
    . ' out.noteAfter=(document.querySelector(".doc-req-one")||{}).textContent;'
    . ' S.nextStep(); out.after=S.state.step;');
$check('a restored application with several documents is stopped at Step 2 with a clear message (not refused only at Submit)', ($g['step'] ?? 0) === 2
    && ($g['msg'] ?? '') === "One document per application for now.\nRemove the others and submit them separately." && ($g['submit'] ?? null) === [2, false], $g);
$check('the message replaces the note, so only one two-line notice is shown', ($g['notices'] ?? 0) === 1, $g);
$check('removing the extra document clears the message, brings back the note, and the application continues', ($g['left'] ?? 0) === 1 && ($g['errAfter'] ?? true) === false
    && str_starts_with((string) ($g['noteAfter'] ?? ''), 'One document per application for now.') && ($g['after'] ?? 0) === 3, $g);
// The notice is compact: two short lines, smaller text, no extra space; on a phone too; same colours as before.
$noticeJs = ' var e=document.querySelector(".doc-req-one"), cs=getComputedStyle(e), r=e.getBoundingClientRect(); out.n=[Math.round(r.height/parseFloat(cs.lineHeight)), parseFloat(cs.fontSize), parseFloat(cs.lineHeight)/parseFloat(cs.fontSize), cs.paddingTop, cs.marginBottom, cs.color, (function(){ var ref=document.createElement("div"); ref.className=e.classList.contains("field-error") ? "field-error" : "field-hint"; e.parentNode.appendChild(ref); var c=getComputedStyle(ref).color; ref.remove(); return c; })(), document.documentElement.scrollWidth<=window.innerWidth];';
foreach (['desktop' => 1366, 'phone' => 390] as $device => $width) {
    $note = $run('noteSize' . $device, $oneOnly . $base . 'state.step=2; S.setDocItems(fd,[{documentType:"Certification of Assessment",purpose:"Personal Copy"}]); S.render();' . $noticeJs, $width);
    $err = $run('errSize' . $device, $oneOnly . $base . 'state.step=2; ' . $two . ' S.render(); S.nextStep();' . $noticeJs, $width);
    $check("the one-document notice is two short lines of small text with no extra space ({$device})", ($note['n'][0] ?? 0) === 2 && ($err['n'][0] ?? 0) === 2
        && ($note['n'][1] ?? 99) <= 13 && ($err['n'][1] ?? 99) <= 12.5 && ($note['n'][2] ?? 9) <= 1.4 && ($note['n'][3] ?? '') === '0px' && ($note['n'][4] ?? '') === '14px'
        && ($note['n'][7] ?? false) === true && ($err['n'][7] ?? false) === true, [$note, $err]);
    $check("the notice keeps the existing hint and error colours ({$device})", ($note['n'][5] ?? 'a') === ($note['n'][6] ?? 'b') && ($err['n'][5] ?? 'a') === ($err['n'][6] ?? 'b'), [$note, $err]);
}
$g = $run('multiOn', 'window.SMART_ASSESS_CONFIG={multiDocument:true};' . $base . 'state.step=2; S.render();' . $cards);
$check('when the server takes several documents, the Add button is offered (also without any server, in the demo)', ($g['add'] ?? false) === true, $g);
$prototype = (string) file_get_contents($project . '/smart-assess-app/web/prototype.php');
$check('the client page is told whether the server can take several documents (from the database check)', str_contains($prototype, "\$config['multiDocument'] = sa_applications_available();"));

// ---- The page's own key: one per application started or resumed, kept while that application is retried ----
$g = $run('pageKey', 'S.startFlow("docreq"); var k1=S.state.submissionKey; out.k1=k1; out.exposed=window.SMART_ASSESS_FORM().submissionKey===k1;'
    . ' S.nextStep(); S.submitRequest(); out.kept=S.state.submissionKey===k1; out.inDraft=JSON.stringify(S.draftFields()).indexOf(k1)>=0;'
    . ' S.startFlow("docreq"); out.k2=S.state.submissionKey; S.startFlow("landtransfer"); out.k3=S.state.submissionKey;'
    . ' S.applyDraft("docreq",2,2,{firstName:"Rosa"},[]); out.k4=S.state.submissionKey;');
$keysSeen = [$g['k1'] ?? '', $g['k2'] ?? '', $g['k3'] ?? '', $g['k4'] ?? ''];
$check('every application the page starts or resumes gets its own random key (32 hex characters), given to the submit script',
    count(array_filter($keysSeen, static fn ($k) => (bool) preg_match('/^[a-f0-9]{32}$/', (string) $k))) === 4 && count(array_unique($keysSeen)) === 4 && ($g['exposed'] ?? false) === true, $g);
$check('the key stays the same while that application is checked and retried, and is never saved in a draft', ($g['kept'] ?? false) === true && ($g['inDraft'] ?? true) === false, $g);

// ---- Submit: one key per application, kept for retries (no duplicate application without a saved draft) ----
$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    $check('Node.js is available for the submit-key checks', false, 'node not found');
} else {
    $bridgeJs = $work . '/bridge-run.js';
    file_put_contents($bridgeJs, <<<'JS'
const fs = require('fs');
const posts = []; const answers = []; const assigned = []; let clickHandler = null;
const node = () => ({ style: {}, appendChild() {}, insertBefore() {}, remove() {}, setAttribute() {}, addEventListener() {}, querySelector: () => null, prepend() {}, classList: { add() {}, remove() {} } });
global.window = { SMART_ASSESS_CONFIG: { portal: 'client', csrf: 'c', api: '/api.php' }, crypto: globalThis.crypto, setTimeout: () => 0, location: { assign: (u) => assigned.push(u) } };
window.SMART_ASSESS_FORM = () => ({ flow: 'docreq', draftCode: '', formData: { isOwner: true, firstName: 'Rosa', documentRequests: [
  { documentType: 'Certification of Assessment', purpose: 'Personal Copy' }, { documentType: 'Certification of No Liens and Encumbrances', purpose: 'For Titling' }] } });
global.document = { addEventListener: (type, fn) => { if (type === 'click') clickHandler = fn; }, getElementById: () => null, querySelector: () => null, querySelectorAll: () => [],
  createElement: node, body: node(), readyState: 'complete' };
global.fetch = (url, opts) => { posts.push(JSON.parse(opts.body.get('payload'))); return answers.shift()(); };
eval(fs.readFileSync(process.argv[2], 'utf8'));
const submit = () => clickHandler({ target: { closest: () => ({ getAttribute: () => 'confirm-submit' }) }, preventDefault() {}, stopImmediatePropagation() {} });
const ok = () => Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, request: { reference_no: 'DR-2026-AB12CD34' } }) });
const lost = () => Promise.reject(new TypeError('Failed to fetch'));
const refused = () => Promise.resolve({ ok: false, json: () => Promise.resolve({ ok: false, error: 'Upload every required document before submitting.' }) });
const settle = () => new Promise((r) => setTimeout(r, 20));
(async () => {
  answers.push(lost, refused, ok, ok);
  submit(); submit(); await settle();          // a double click sends once; the response is lost
  submit(); await settle();                    // Submit again: refused (nothing saved)
  submit(); await settle();                    // Submit again: saved
  submit(); await settle();                    // a click while the page leaves for the receipt: nothing sent
  eval(fs.readFileSync(process.argv[2], 'utf8')); // the next application: a new page load
  submit(); await settle();
  // A current page gives its own key per application: the script sends that one.
  const pageKeys = ['0123456789abcdef0123456789abcdef', 'fedcba9876543210fedcba9876543210'];
  let flowNow = 'docreq', pageKey = pageKeys[0];
  window.SMART_ASSESS_FORM = () => ({ flow: flowNow, draftCode: '', submissionKey: pageKey, formData: { isOwner: true, firstName: 'Rosa' } });
  eval(fs.readFileSync(process.argv[2], 'utf8'));
  answers.push(lost, ok);
  submit(); await settle();                    // lost response
  pageKey = pageKeys[1]; flowNow = 'landtransfer';
  submit(); await settle();                    // the client started a different application on the same page
  // An older page (no key of its own): the script's key changes with the application type.
  window.SMART_ASSESS_FORM = () => ({ flow: flowNow, draftCode: '', formData: { isOwner: true, firstName: 'Rosa' } });
  eval(fs.readFileSync(process.argv[2], 'utf8'));
  answers.push(lost, lost);
  flowNow = 'docreq'; submit(); await settle();
  flowNow = 'landtransfer'; submit(); await settle();
  console.log(JSON.stringify({ count: posts.length, keys: posts.map((p) => p.submission_key), docs: posts[0].document_requests, assigned }));
})();
JS);
    $out = (array) json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($bridgeJs) . ' ' . escapeshellarg($project . '/smart-assess-app/web/assets/php-bridge.js') . ' 2>&1'), true);
    $keys = $out['keys'] ?? [];
    $check('the page sends a random submission key with every application (32 hex characters); a double click, or a click after success, sends nothing more', ($out['count'] ?? 0) === 8
        && count($keys) === 8 && (bool) preg_match('/^[a-f0-9]{32}$/', (string) ($keys[0] ?? '')), $out);
    $check('after a lost response or a refusal, Submit again sends the same key, so the server returns the saved application', isset($keys[2]) && $keys[0] === $keys[1] && $keys[1] === $keys[2], $out);
    $check('the next application (a new page after the receipt) gets a new key', isset($keys[3]) && $keys[3] !== $keys[2] && (bool) preg_match('/^[a-f0-9]{32}$/', (string) $keys[3]), $out);
    $check('with a current page, the page\'s key for each application is sent (a new application on the same page, a new key)',
        array_slice($keys, 4, 2) === ['0123456789abcdef0123456789abcdef', 'fedcba9876543210fedcba9876543210'], $out);
    $check('with an older page, the script makes a new key when the application type changes', isset($keys[7]) && $keys[6] !== $keys[7]
        && !in_array($keys[6], array_slice($keys, 0, 6), true), $out);
    $check('each attempt still sends every chosen document', ($out['docs'] ?? null) === [['document_type' => 'Certification of Assessment', 'purpose' => 'Personal Copy'],
        ['document_type' => 'Certification of No Liens and Encumbrances', 'purpose' => 'For Titling']] && count($out['assigned'] ?? []) === 3, $out);
}

$failed = 0;
foreach ($results as [$passed, $name, $detail]) {
    echo ($passed ? 'PASS  ' : 'FAIL  ') . $name . ($passed ? '' : '   ' . $detail) . "\n";
    $failed += $passed ? 0 : 1;
}
echo (count($results) - $failed) . ' / ' . count($results) . " passed\n";
exit($failed === 0 ? 0 : 1);
