#!/usr/bin/env php
<?php
declare(strict_types=1);

// ◆ Make sure this script never times out or OOMs ◆
@ini_set('memory_limit', '1G');
set_time_limit(0);
ignore_user_abort(true);

// ◆ Autoload Monolog (run `composer require monolog/monolog`) ◆
require_once __DIR__ . '/vendor/autoload.php';
use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

// ◆ 1 ── Grab the jobId passed from upload.php ◆
if ($argc < 2) {
    fwrite(STDERR, "Usage: php cli_upload.php <jobId>\n");
    exit(1);
}
$jobId = $argv[1];

// ◆ 2 ── Load the saved request context ◆
// (upload.php must have written this file before exec’ing)
$ctxFile = __DIR__ . "/requests/{$jobId}.json";
if (!is_file($ctxFile)) {
    error_log("cli_upload.php: Missing request context for job {$jobId}\n");
    exit(1);
}
$ctx = json_decode(file_get_contents($ctxFile), true);
/** @var string $accessToken      Google Drive OAuth token
    string $pageAccessToken Facebook Page token (for Graph API)
    string $adAccountId     Facebook Ad Account ID (act_XXXX)
    array  $files           [ ['name'=>'Video1.mp4','driveId'=>'abc123'], … ]  **/
$accessToken     = $ctx['accessToken'];
$pageAccessToken = $ctx['pageAccessToken'];
$adAccountId     = $ctx['adAccountId'];
$files           = $ctx['files'];

// ◆ 3 ── Set up a rotating log for this job ◆
$log = new Logger('uploader');
$log->pushHandler(new RotatingFileHandler(__DIR__ . "/logs/{$jobId}.log", 30, Logger::INFO));

// ◆ 4 ── Prepare for progress crumbs ◆
$progressDir = sys_get_temp_dir();
$prefix      = "{$progressDir}/progress_{$jobId}";

// Emit an “init” snapshot so the front-end can render all queued rows
file_put_contents("{$prefix}_init.json", json_encode([
    'init'  => true,
    'files' => array_column($files, 'name')
]));

// ◆ 5 ── Process each file in turn ◆
foreach ($files as $file) {
    $name    = $file['name'];
    $driveId = $file['driveId'];
    $tmpPath = "{$progressDir}/{$jobId}_{$name}";

    //
    // ─── Download from Google Drive ───
    //
    $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$driveId}?alt=media");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 8 * 1024 * 1024);        // 8 MB chunks
    curl_setopt($ch, CURLOPT_FILE, fopen($tmpPath, 'w'));
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function(
        $resource, $dlTotal, $dlNow
    ) use ($prefix, $name) {
        static $lastPct = -1;
        if ($dlTotal > 0) {
            $pct = (int) (($dlNow / $dlTotal) * 100);
            if ($pct !== $lastPct) {
                file_put_contents("{$prefix}_{$name}.json", json_encode([
                    'filename' => $name,
                    'phase'    => 'download',
                    'bytes'    => $dlNow,
                    'total'    => $dlTotal,
                    'pct'      => $pct
                ]));
                $lastPct = $pct;
            }
        }
        return 0; // continue
    });

    if (!curl_exec($ch)) {
        $err = curl_error($ch);
        $log->error("Drive download failed for {$name}", ['error'=>$err]);
        file_put_contents("{$prefix}_{$name}.json", json_encode([
            'filename'=>$name,'phase'=>'download','status'=>'failed','error'=>$err
        ]));
        curl_close($ch);
        continue;
    }
    curl_close($ch);

    //
    // ─── Upload to Facebook using the Resumable Video API ───
    //
    $endpoint = "https://graph-video.facebook.com/v20.0/{$adAccountId}/advideos";

    // 5.1 Start phase
    $start = [
        'upload_phase'=>'start',
        'file_size'  => filesize($tmpPath)
    ];
    $ch2 = curl_init($endpoint);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$pageAccessToken}"]);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_POST, true);
    curl_setopt($ch2, CURLOPT_POSTFIELDS, $start);
    $resp2 = json_decode(curl_exec($ch2), true);
    curl_close($ch2);

    if (empty($resp2['upload_session_id'])) {
        $err = $resp2['error']['message'] ?? 'Unknown start-phase error';
        $log->error("FB start failed for {$name}", ['error'=>$err]);
        file_put_contents("{$prefix}_{$name}.json", json_encode([
            'filename'=>$name,'phase'=>'upload','status'=>'failed','error'=>$err
        ]));
        @unlink($tmpPath);
        continue;
    }

    $sessionId   = $resp2['upload_session_id'];
    $startOffset = (int)$resp2['start_offset'];
    $endOffset   = (int)$resp2['end_offset'];
    $totalSize   = filesize($tmpPath);

    // 5.2 Transfer phase (chunked)
    while ($startOffset < $endOffset) {
        $ch3 = curl_init($endpoint);
        $chunkFile = new CURLFile($tmpPath);
        $post = [
            'upload_phase'      => 'transfer',
            'start_offset'      => $startOffset,
            'upload_session_id' => $sessionId,
            'video_file_chunk'  => $chunkFile
        ];
        curl_setopt($ch3, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$pageAccessToken}"]);
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_POST, true);
        curl_setopt($ch3, CURLOPT_POSTFIELDS, $post);
        $resp3 = json_decode(curl_exec($ch3), true);
        curl_close($ch3);

        if (!empty($resp3['error'])) {
            $err = $resp3['error']['message'];
            $log->error("FB transfer failed for {$name}", ['error'=>$err]);
            file_put_contents("{$prefix}_{$name}.json", json_encode([
                'filename'=>$name,'phase'=>'upload','status'=>'failed','error'=>$err
            ]));
            break;
        }

        $startOffset = (int)$resp3['start_offset'];
        $endOffset   = (int)$resp3['end_offset'];
        $pct         = (int)(($startOffset / $totalSize) * 100);

        file_put_contents("{$prefix}_{$name}.json", json_encode([
            'filename'=>$name,'phase'=>'upload','bytes'=>$startOffset,
            'total'=>$totalSize,'pct'=>$pct
        ]));
    }

    // 5.3 Finish phase
    $ch4 = curl_init($endpoint);
    curl_setopt($ch4, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$pageAccessToken}"]);
    curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch4, CURLOPT_POST, true);
    curl_setopt($ch4, CURLOPT_POSTFIELDS, [
        'upload_phase'      => 'finish',
        'upload_session_id' => $sessionId
    ]);
    $resp4 = json_decode(curl_exec($ch4), true);
    curl_close($ch4);

    if (!empty($resp4['video_id'])) {
        file_put_contents("{$prefix}_{$name}.json", json_encode([
            'filename'=>$name,'phase'=>'done','status'=>'success',
            'videoId'=>$resp4['video_id']
        ]));
    } else {
        $err = $resp4['error']['message'] ?? 'Unknown finish-phase error';
        $log->error("FB finish failed for {$name}", ['error'=>$err]);
        file_put_contents("{$prefix}_{$name}.json", json_encode([
            'filename'=>$name,'phase'=>'done','status'=>'failed','error'=>$err
        ]));
    }

    // Cleanup
    @unlink($tmpPath);
}
