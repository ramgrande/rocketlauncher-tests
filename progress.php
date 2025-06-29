<?php
declare(strict_types=1);
set_time_limit(0);

// ─── Disable ALL buffering and compression ─────────────────────────────────
ini_set('zlib.output_compression','0');
ini_set('output_buffering','Off');
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip','1');
}
ob_implicit_flush(true);
while (ob_get_level()>0) {
    ob_end_flush();
}

// ─── SSE headers ──────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');   // for nginx proxies

// ─── Helpers ──────────────────────────────────────────────────────────────
function sendEvent(array $data): void {
    echo 'data: '.json_encode($data)."\n\n";
    @ob_flush(); @flush();
}

// ─── Validate and send init ───────────────────────────────────────────────
$jobId = $_GET['jobId'] ?? exit;
$dir   = __DIR__.'/jobs';
$meta  = "$dir/{$jobId}.json";
$prog  = "$dir/{$jobId}.progress";

if (!is_file($meta)) {
    sendEvent(['error'=>'Unknown jobId']);
    exit;
}

$config = json_decode(file_get_contents($meta), true);
sendEvent(['init'=>true, 'files'=>array_column($config['files'],'name')]);

// ─── Emit a heartbeat comment to keep the connection alive ────────────────
$lastBeat = time();
$heartbeatInterval = 1;  // seconds

// ─── Tail the progress file from the top ─────────────────────────────────
$fp = fopen($prog, 'c+');
fseek($fp, 0, SEEK_SET);

while (true) {
    // Read any new lines and push them immediately
    while (($line = fgets($fp)) !== false) {
        $json = json_decode($line, true);
        if ($json !== null) {
            sendEvent($json);
        }
    }

    // Send a heartbeat comment every second
    if ((time() - $lastBeat) >= $heartbeatInterval) {
        echo ": \n\n";         // SSE comment
        @ob_flush(); @flush();
        $lastBeat = time();
    }

    usleep(200000);
}
