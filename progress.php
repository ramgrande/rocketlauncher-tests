<?php
/**
 * progress.php – Server-Sent Events tailer for job progress
 */
declare(strict_types=1);
set_time_limit(0);

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$jobId   = $_GET['jobId'] ?? exit;
$jobDir  = __DIR__ . '/jobs';
$metaFile = "$jobDir/$jobId.json";
if (!is_file($metaFile)) {
    echo "data: " . json_encode(['error' => 'Unknown jobId']) . "\n\n";
    exit;
}

// 1) Send initial file list
$meta = json_decode(file_get_contents($metaFile), true);
echo "data: " . json_encode([
    'init'  => true,
    'files' => array_column($meta['files'], 'name')
]) . "\n\n";
@ob_flush(); @flush();

// 2) Tail the progress file
$progFile = "$jobDir/$jobId.progress";
$fp = fopen($progFile, 'c+');
fseek($fp, 0, SEEK_END);

while (true) {
    if (($line = fgets($fp)) !== false) {
        echo "data: $line\n";
        @ob_flush(); @flush();
    } elseif (is_file("$jobDir/$jobId.done")) {
        echo "event: done\ndata: {}\n\n";
        @ob_flush(); @flush();
        break;
    } else {
        usleep(250000);
    }
}
