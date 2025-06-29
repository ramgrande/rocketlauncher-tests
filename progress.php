<?php
/**
 *  progress.php – Server‑Sent Events stream
 *  ----------------------------------------
 *  Client opens:  progress.php?jobId=...
 *  Worker (worker.php) writes progress lines to jobs/<jobId>.progress
 */
declare(strict_types=1);
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$jobId = $_GET['jobId'] ?? '';
if (!$jobId) { echo "data: ".json_encode(['error'=>'Missing jobId'])."\n\n"; exit; }

$jobDir   = __DIR__.'/jobs';
$metaFile = "$jobDir/$jobId.json";
if (!is_file($metaFile)) {
    echo "data: ".json_encode(['error'=>'Unknown job'])."\n\n";  exit;
}

/* 1. Send initial file list */
$meta  = json_decode(file_get_contents($metaFile), true);
$names = array_column($meta['files'], 'name');
echo "data: ".json_encode(['init'=>true, 'files'=>$names])."\n\n";
@ob_flush(); @flush();

/* 2. Stream incremental updates */
$progressFile = "$jobDir/$jobId.progress";
$fp = fopen($progressFile, 'c+');               // create if not existing
fseek($fp, 0, SEEK_END);                       // follow tail

while (true) {
    $line = fgets($fp);
    if ($line !== false) {
        echo 'data: '.$line."\n\n";
        @ob_flush(); @flush();
    } elseif (is_file("$jobDir/$jobId.done")) {   // worker signals finish
        echo "event: done\ndata: {}\n\n";
        @ob_flush(); @flush();
        break;
    } else {
        sleep(1);
    }
}
