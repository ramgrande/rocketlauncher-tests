<?php
declare(strict_types=1);

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Validate
if (empty($_GET['jobId'])) {
    echo "event: error\n";
    echo "data: {\"error\":\"Missing jobId\"}\n\n";
    exit;
}
$jobId = preg_replace('/[^a-z0-9]/i','',$_GET['jobId']);
$temp  = sys_get_temp_dir();
$prefix = "{$temp}/progress_{$jobId}_";

// Track whether we’ve sent the init list
$sentInit = false;

// Loop until worker is done
while (true) {
    clearstatcache();

    // 1) Send the “init” list exactly once
    if (!$sentInit) {
        $initFile = "{$prefix}init.json";
        if (file_exists($initFile)) {
            $data = json_decode(file_get_contents($initFile), true);
            echo "data: ".json_encode($data)."\n\n";
            $sentInit = true;
        }
    }

    // 2) Emit any per-file crumbs
    foreach (glob("{$prefix}*.json") as $file) {
        if (basename($file) === "progress_{$jobId}_init.json") {
            // already handled
            continue;
        }
        $json = file_get_contents($file);
        echo "data: {$json}\n\n";
        // remove it so we don’t resend
        @unlink($file);
    }

    // 3) If init was sent and no more crumbs remain, we’re done
    if ($sentInit && !glob("{$prefix}*.json")) {
        break;
    }

    // flush to client & sleep
    @ob_flush(); @flush();
    usleep(200000);  // 0.2s
}
