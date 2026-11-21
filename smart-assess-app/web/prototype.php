<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function sa_ini_size_bytes(string $value): int
{
    if (!preg_match('/^\s*(\d+(?:\.\d+)?)\s*([KMG]?)B?\s*$/i', $value, $matches)) {
        return PHP_INT_MAX;
    }
    $factor = match (strtoupper($matches[2])) {
        'K' => 1024,
        'M' => 1024 ** 2,
        'G' => 1024 ** 3,
        default => 1,
    };
    return (int) ((float) $matches[1] * $factor);
}

function sa_emit_prototype(string $filename, array $bootstrap, string $portal): never
{
    $projectRoot = dirname(__DIR__, 2);
    $sourcePath = $projectRoot . '/' . $filename;
    $html = file_get_contents($sourcePath);
    if ($html === false) {
        http_response_code(500);
        exit('The SMART ASSESS interface template could not be loaded.');
    }

    $html = str_replace(
        ['bg MAO.jpg', 'hero-mao.jpg', 'sidebar-mao.jpg', 'LOGO.png', 'smart-assess-logo.png', 'ID CLear.png', 'Blurry ID.png', 'close.png'],
        ['assets/bg%20MAO.jpg', 'assets/hero-mao.jpg', 'assets/sidebar-mao.jpg', 'assets/LOGO.png', 'assets/smart-assess-logo.png', 'assets/ID%20CLear.png', 'assets/Blurry%20ID.png', 'assets/close.png'],
        $html
    );

    if ($portal === 'client') {
        $html = preg_replace_callback(
            '/const state = (\{\n  view:\'landing\'.*?\n\});/s',
            static fn(array $match): string => 'const state = Object.assign(' . $match[1] . ', window.SMART_ASSESS_PAGE || {});',
            $html,
            1,
            $stateMatches
        ) ?? $html;
        if ($stateMatches !== 1) {
            http_response_code(500);
            exit('The public interface state could not be initialized.');
        }
    } else {
        $html = str_replace('var state = loadState();', 'var state = window.SMART_ASSESS_PAGE || loadState();', $html, $stateMatches);
        if ($stateMatches !== 1) {
            http_response_code(500);
            exit('The internal interface state could not be initialized.');
        }
    }

    if ($portal === 'internal') {
        $html = str_replace(
            '</style>',
            '</style>' . "\n<style>\n  @import url(\"/assets/internal-tables.css\");\n</style>",
            $html,
            $styleMatches
        );
        if ($styleMatches !== 1) {
            http_response_code(500);
            exit('The internal table styles could not be initialized.');
        }
    }

    $config = [
        'portal' => $portal,
        'csrf' => sa_csrf_token(),
        'api' => '/api.php',
        'maxFileUploadBytes' => min(
            sa_ini_size_bytes((string) ini_get('upload_max_filesize')),
            5 * 1024 * 1024
        ),
    ];
    if ($portal === 'client') {
        // Whether this server can save several documents in one Document Request application
        // (database update 20261119_request_applications.sql has run). Until then the page offers one
        // document per application, instead of letting the client fill in several and be refused at
        // Submit. If this cannot be checked, the page behaves as before (the server still decides).
        try {
            $config['multiDocument'] = sa_applications_available();
        } catch (Throwable) {
            $config['multiDocument'] = true;
        }
    }
    // The submit script's address carries a short hash of its content, so a browser that cached an
    // older copy fetches the new one as soon as it changes (the page itself is never cached).
    $bridgeFile = __DIR__ . '/assets/php-bridge.js';
    $bridgeVersion = is_file($bridgeFile) ? substr((string) hash_file('sha256', $bridgeFile), 0, 12) : '0';
    $bridge = '<script>window.SMART_ASSESS_CONFIG='
        . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';window.SMART_ASSESS_PAGE='
        . json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';</script><script src="/assets/php-bridge.js?v=' . $bridgeVersion . '"></script>';

    $scriptNeedle = "<script>\n(function(){\n";
    if (!str_contains($html, $scriptNeedle)) {
        http_response_code(500);
        exit('The interface scripts could not be initialized.');
    }
    $html = str_replace($scriptNeedle, $bridge . $scriptNeedle, $html);

    header('Content-Type: text/html; charset=UTF-8');
    // Pages embed announcement data; never let a browser reuse a stale copy.
    header('Cache-Control: no-store, private');
    // Resume links carry a recovery code; never send it to other sites.
    header('Referrer-Policy: same-origin');
    echo $html;
    exit;
}
