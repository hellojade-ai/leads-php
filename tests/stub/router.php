<?php

declare(strict_types=1);

// Router for the php -S stub server. Pops the next scripted response from the
// queue file and appends the received request to the log file. Both paths are
// passed via environment by StubServer. Nothing here talks to the real API.

$dir = getenv('HJ_STUB_DIR');
if ($dir === false || $dir === '') {
    http_response_code(500);
    echo '{"error":"stub_misconfigured"}';
    return true;
}
$queueFile = $dir . '/queue.json';
$logFile = $dir . '/requests.json';
$lock = fopen($dir . '/lock', 'c');
flock($lock, LOCK_EX);

$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}
$body = (string) file_get_contents('php://input');

$log = is_file($logFile) ? (json_decode((string) file_get_contents($logFile), true) ?: []) : [];
$log[] = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'headers' => $headers,
    'body' => $body,
];
file_put_contents($logFile, json_encode($log));

$queue = is_file($queueFile) ? (json_decode((string) file_get_contents($queueFile), true) ?: []) : [];
$scripted = array_shift($queue) ?? ['status' => 500, 'body' => ['error' => 'stub_unscripted'], 'headers' => [], 'delay' => 0];
file_put_contents($queueFile, json_encode($queue));
flock($lock, LOCK_UN);
fclose($lock);

if (($scripted['delay'] ?? 0) > 0) {
    usleep((int) round($scripted['delay'] * 1_000_000));
}
http_response_code((int) $scripted['status']);
header('Content-Type: application/json');
header('X-Request-Id: ' . ($headers['x-request-id'] ?? ('stub-' . bin2hex(random_bytes(4)))));
foreach (($scripted['headers'] ?? []) as $k => $v) {
    header("{$k}: {$v}");
}
$out = $scripted['body'] ?? [];
echo is_string($out) ? $out : json_encode($out);
return true;
