<?php
/**
 * progress.php – SSE tailer (never closes, always shows every line)
 */
declare(strict_types=1);
set_time_limit(0);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$jobId = $_GET['jobId'] ?? exit;
$dir   = __DIR__.'/jobs';
$meta  = "$dir/{$jobId}.json";
$pf    = "$dir/{$jobId}.progress";

if (!is_file($meta)) {
    echo "data:".json_encode(['error'=>'Unknown jobId'])."\n\n";
    exit;
}

// init
$m = json_decode(file_get_contents($meta),true);
echo "data:".json_encode(['init'=>true,'files'=>array_column($m['files'],'name')])."\n\n";
@ob_flush(); @flush();

// tail from top
$fp = fopen($pf,'c+');
fseek($fp,0,SEEK_SET);
while (true) {
    while (($line=fgets($fp))!==false) {
        echo "data:{$line}\n\n";
        @ob_flush(); @flush();
    }
    usleep(250000);
}
