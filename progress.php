<?php
declare(strict_types=1);
set_time_limit(0);
header('Content-Type:text/event-stream');
header('Cache-Control:no-cache');
header('Connection:keep-alive');

$jobId = $_GET['jobId'] ?? exit;
$dir   = __DIR__.'/jobs';
$meta  = "$dir/$jobId.json";
if(!is_file($meta)){
    echo "data:".json_encode(['error'=>'Unknown job'])."\n\n";
    exit;
}

// initial list
$m = json_decode(file_get_contents($meta), true);
echo "data:".json_encode(['init'=>true,'files'=>array_column($m['files'],'name')])."\n\n";
@ob_flush(); @flush();

// tail progress
$pf = "$dir/$jobId.progress";
$fp = fopen($pf,'c+'); fseek($fp,0,SEEK_END);
while(true){
  if(($line=fgets($fp))!==false){
    echo "data:$line\n"; @ob_flush(); @flush();
  } elseif(is_file("$dir/$jobId.done")){
    echo "event:done\ndata:{}\n\n"; @ob_flush(); @flush();
    break;
  } else {
    usleep(200000);
  }
}
