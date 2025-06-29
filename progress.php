<?php
/*  Facebook Rocket‑Launcher – progress.php
    ===============================================================
    Streams the growing events file produced by worker.php
*/

session_write_close();
set_time_limit(0);

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');  // nginx: disable buffering
flush();

$jobId = $_GET['jobId'] ?? '';
if (!$jobId){
  echo "data: {}\n\n"; flush(); exit;
}

$dir   = sys_get_temp_dir().'/'.basename($jobId);
$ev    = "$dir/events.sse";

/* Wait until the worker creates the file */
while (!file_exists($ev) && !connection_aborted()){
  echo "data: {}\n\n"; flush(); sleep(1);
}

$fp = fopen($ev,'r');
fseek($fp, 0, SEEK_END);          // start at EOF
$lastPing = time();

while (!connection_aborted()){

  while (($line = fgets($fp)) !== false){
    echo $line; flush();
  }

  clearstatcache();
  if (time()-$lastPing > 25){
    echo "data: {}\n\n"; flush();
    $lastPing = time();
  }
  usleep(300000);   // 0.3 s
}
fclose($fp);
