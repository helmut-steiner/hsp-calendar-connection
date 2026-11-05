<?php
/** Offline gettext extraction using PHP tokens; never boots WordPress. */
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__);
$files = array_merge(glob($root . '/*.php'), glob($root . '/includes/*.php'));
$functions = ['__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e'];
$unsupported = ['_x', '_ex', '_n', '_nx', '_n_noop', '_nx_noop', 'esc_html_x', 'esc_attr_x'];
$messages = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    if (basename($file) === 'hsp-calendar-connection.php' &&
        preg_match('/^\s*\* Description:\s*([^\r\n]+)\r?$/m', $source, $header)) {
        $messages[trim($header[1])]['references'][] = 'hsp-calendar-connection.php:header';
    }
    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING) { continue; }
        if (in_array($token[1], $unsupported, true)) {
            throw new RuntimeException('Extend the catalogue tooling for contextual/plural messages before use.');
        }
        if (!in_array($token[1], $functions, true)) { continue; }
        $significant = [];
        for ($cursor = $index + 1; $cursor < count($tokens) && count($significant) < 5; ++$cursor) {
            $next = $tokens[$cursor];
            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $significant[] = $next;
        }
        if (($significant[0] ?? null) !== '(' || !is_array($significant[1] ?? null) ||
            $significant[1][0] !== T_CONSTANT_ENCAPSED_STRING || ($significant[2] ?? null) !== ',' ||
            !is_array($significant[3] ?? null) || $significant[3][0] !== T_CONSTANT_ENCAPSED_STRING ||
            ($significant[4] ?? null) !== ')') {
            throw new RuntimeException('Gettext calls must use literal message and domain arguments: ' . $file . ':' . $token[2]);
        }
        $decode = static function ($literal) {
            $inner = substr($literal, 1, -1);
            return $literal[0] === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $inner) : stripcslashes($inner);
        };
        $message = $decode($significant[1][1]);
        if ($decode($significant[3][1]) !== 'hsp-calendar-connection') { throw new RuntimeException('Unexpected translation domain.'); }
        $messages[$message]['references'][] = str_replace('\\', '/', substr($file, strlen($root) + 1)) . ':' . $token[2];
        for ($cursor = $index - 1; $cursor >= max(0, $index - 20); --$cursor) {
            $previous = $tokens[$cursor];
            if (is_array($previous) && in_array($previous[0], [T_COMMENT, T_DOC_COMMENT], true) &&
                preg_match('/translators:\s*(.*?)\s*\*\//s', $previous[1], $match)) {
                $messages[$message]['comment'] = trim($match[1]);
                break;
            }
            if ($previous === ';' || $previous === '}') { break; }
        }
    }
}
ksort($messages);
echo json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
