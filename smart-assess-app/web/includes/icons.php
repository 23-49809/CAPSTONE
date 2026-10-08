<?php
/** Small inline-SVG icon set, mirrors the ones used in the artifact prototype. */
function svg_wrap(string $inner): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}

function icon(string $name): string
{
    $paths = [
        'doc' => '<rect x="5" y="3" width="14" height="18" rx="2"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="13" y2="16"/>',
        'swap' => '<line x1="4" y1="8" x2="20" y2="8"/><polyline points="15 3 20 8 15 13"/><line x1="20" y1="16" x2="4" y2="16"/><polyline points="9 11 4 16 9 21"/>',
        'shield' => '<path d="M12 3 4 6v6c0 5 3.5 8.5 8 9 4.5-.5 8-4 8-9V6l-8-3z"/><polyline points="8.5 12 11 14.5 15.5 9.5"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><line x1="12" y1="7" x2="12" y2="12"/><line x1="12" y1="12" x2="16" y2="14"/>',
        'headset' => '<path d="M4 13v-1a8 8 0 0 1 16 0v1"/><rect x="3" y="13" width="4" height="6" rx="1.5"/><rect x="17" y="13" width="4" height="6" rx="1.5"/><path d="M20 19v1a3 3 0 0 1-3 3h-3"/>',
        'pin' => '<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.3"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
        'home' => '<path d="M4 11 12 4l8 7"/><path d="M6 10v9a1 1 0 0 0 1 1h4v-6h2v6h4a1 1 0 0 0 1-1v-9"/>',
        'id' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="11" r="2"/><path d="M5.5 16c.5-1.8 1.7-2.7 3-2.7s2.5.9 3 2.7"/><line x1="14" y1="9" x2="18" y2="9"/><line x1="14" y1="12" x2="18" y2="12"/>',
        'file' => '<path d="M7 3h7l4 4v14a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><polyline points="14 3 14 7 18 7"/>',
        'check' => '<polyline points="5 13 10 18 19 6"/>',
        'arrowRight' => '<line x1="4" y1="12" x2="20" y2="12"/><polyline points="14 6 20 12 14 18"/>',
        'login' => '<path d="M13 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/><polyline points="10 8 15 12 10 16"/><line x1="15" y1="12" x2="3" y2="12"/>',
        'logout' => '<path d="M11 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5"/><polyline points="14 16 19 12 14 8"/><line x1="19" y1="12" x2="7" y2="12"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'alert' => '<path d="M12 3 22 20H2z"/><line x1="12" y1="9" x2="12" y2="14"/><circle cx="12" cy="17" r=".9" fill="currentColor" stroke="none"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'checklist' => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><polyline points="9 13 11 15 15 11"/>',
        'timer' => '<circle cx="12" cy="13" r="8"/><line x1="12" y1="13" x2="12" y2="9"/><line x1="12" y1="13" x2="15" y2="14.5"/>',
        'sms' => '<path d="M4 4h16v12H8l-4 4V4z"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="11" x2="13" y2="11"/>',
        'send' => '<line x1="21" y1="3" x2="10" y2="14"/><polygon points="21 3 14 21 10 14 3 10 21 3"/>',
        'bell' => '<path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.5 2.7-6 6-6s6 2.5 6 6"/><circle cx="17.5" cy="9" r="2.6"/>',
        'bar' => '<line x1="5" y1="20" x2="5" y2="12"/><line x1="12" y1="20" x2="12" y2="6"/><line x1="19" y1="20" x2="19" y2="15"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'x' => '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
        'print' => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'archive' => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'restore' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 9 8 9"/>',
        'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
    ];
    return svg_wrap($paths[$name] ?? $paths['doc']);
}

function icon_span(string $name, string $size = '17px'): string
{
    return '<span style="display:inline-flex;width:' . $size . ';height:' . $size . ';vertical-align:-3px">'
        . icon($name) . '</span>';
}

function brand_mark(): string
{
    return '<svg class="brand-mark" viewBox="0 0 40 40"><circle cx="20" cy="20" r="18" fill="var(--brand-700)"/>'
        . '<circle cx="20" cy="20" r="18" fill="none" stroke="var(--mark-accent)" stroke-width="1.4"/>'
        . '<circle cx="20" cy="20" r="13" fill="none" stroke="var(--mark-accent)" stroke-width="1"/>'
        . '<path d="M20 11l2.3 5.2 5.7.5-4.3 3.8 1.3 5.6-4.9-3-4.9 3 1.3-5.6-4.3-3.8 5.7-.5z" fill="var(--mark-accent)"/></svg>';
}
