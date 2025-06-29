<?php
declare(strict_types=1);

// ─── Disable all buffering / gzip so flush() actually works ─────────────────
ini_set('zlib.output_compression', '0');
ini_set('output_buffering', 'Off');
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ob_implicit_flush(true);
while (ob_get_level() > 0) {
    ob_end_flush();
}

// ─── SSE headers ──────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// ─── Validate jobId and send initial file list ────────────────────────────
$jobId  = $_GET['jobId'] ?? exit;
$jobDir = __DIR__ . '/jobs';
$meta   = "$jobDir/{$jobId}.json";
$pf     = "$jobDir/{$jobId}.progress";

if (!is_file($meta)) {
    echo "data: " . json_encode(['error'=>'Unknown jobId']) . "\n\n";
    exit;
}

// send the init event
$config = json_decode(file_get_contents($meta), true);
$init   = ['init'=>true, 'files'=>array_column($config['files'],'name')];
echo "data: " . json_encode($init) . "\n\n";
@flush();

// ─── Start tailing from top of the progress file ───────────────────────────
$fp = fopen($pf, 'c+');
fseek($fp, 0, SEEK_SET);

while (true) {
    // read any new lines
    while (($line = fgets($fp)) !== false) {
        echo "data: {$line}\n\n";
        @flush();
    }
    // no new data, wait a bit
    usleep(200000);
}
