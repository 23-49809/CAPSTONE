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
        ['bg MAO.jpg', 'LOGO.png', 'ID CLear.png', 'Blurry ID.png', 'close.png'],
        ['assets/bg%20MAO.jpg', 'assets/LOGO.png', 'assets/ID%20CLear.png', 'assets/Blurry%20ID.png', 'assets/close.png'],
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
            '<style>',
            '<style>' . "\n  @import url(\"/assets/internal-tables.css\");",
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
    $bridge = '<script>window.SMART_ASSESS_CONFIG='
        . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';window.SMART_ASSESS_PAGE='
        . json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . ';</script><script src="/assets/php-bridge.js"></script>';

    $scriptNeedle = "<script>\n(function(){\n";
    if (!str_contains($html, $scriptNeedle)) {
        http_response_code(500);
        exit('The interface scripts could not be initialized.');
    }
    $html = str_replace($scriptNeedle, $bridge . $scriptNeedle, $html);

    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}
