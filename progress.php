<?php
/**
 *  progress.php – Server-Sent Events + inline worker
 *  --------------------------------------------------
 *  Streams progress and performs the Drive→FB uploads
 *  in the same request. No background CLI needed.
 */
declare(strict_types=1);
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors',   '0');
ini_set('log_errors',       '1');
ini_set('error_log', __DIR__.'/php-error.log');

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// 1) Validate jobId
$jobId = $_GET['jobId'] ?? '';
if (!$jobId) {
    echo "data: ".json_encode(['error'=>'Missing jobId'])."\n\n";
    exit;
}

// 2) Load job metadata
$jobFile = __DIR__."/jobs/{$jobId}.json";
if (!is_file($jobFile)) {
    echo "data: ".json_encode(['error'=>'Unknown job'])."\n\n";
    exit;
}
$meta   = json_decode(file_get_contents($jobFile), true);
$files  = $meta['files'];   // each item has ['id','name']
$params = $meta['params'];  // folderId, googleApiKey, accessToken, accountId

// 3) Send initial file list
echo "data: ".json_encode([
    'init'  => true,
    'files' => array_column($files, 'name'),
])."\n\n";
@ob_flush(); @flush();

// 4) Process each file inline
foreach ($files as $f) {
    $name = $f['name'];
    try {
        // — Download step
        sendEvent('download', $name, 0);
        $tmp = downloadDriveFile($f['id'], $name, $params['googleApiKey']);
        sendEvent('download', $name, 100);

        // — Upload step
        sendEvent('upload', $name, 0);
        $vid = fbUploadVideo($tmp, $params['accessToken'], $params['accountId']);
        sendEvent('upload', $name, 100);

        // — Done
        sendEvent('done', $name, 100, 'success', ['video_id'=>$vid]);
        @unlink($tmp);

    } catch (Throwable $e) {
        sendEvent('done', $name, 100, 'error', ['error'=>$e->getMessage()]);
    }
}

// 5) Signal overall completion
echo "event: done\ndata: {}\n\n";
@ob_flush(); @flush();


// ───────────────────────────── Helpers ──────────────────────────────

/**
 * Send one SSE “data:” event line
 */
function sendEvent(string $phase, string $filename, int $pct,
                   string $status='running', array $extra=[]) {
    $data = array_merge([
        'phase'    => $phase,
        'filename' => $filename,
        'pct'      => $pct,
        'status'   => $status,
    ], $extra);
    echo 'data: '.json_encode($data)."\n\n";
    @ob_flush(); @flush();
}

/**
 * Download a Drive file to a temp path
 */
function downloadDriveFile(string $id, string $name, string $apiKey): string {
    $url  = "https://www.googleapis.com/drive/v3/files/{$id}?alt=media&key={$apiKey}";
    $dest = sys_get_temp_dir().'/'.basename($name);
    $ch   = curl_init($url);
    $fp   = fopen($dest, 'w');
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 0,
    ]);
    curl_exec($ch);
    fclose($fp);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        throw new RuntimeException("Drive download failed ({$code})");
    }
    return $dest;
}

/**
 * Upload video file to Facebook (single-shot ≤25 MB)
 */
function fbUploadVideo(string $path, string $token, string $account): string {
    $ch = curl_init("https://graph-video.facebook.com/v19.0/{$account}/videos");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => [
            'access_token' => $token,
            'source'       => new CURLFile($path),
        ],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($res, true);
    if (empty($json['id'])) {
        throw new RuntimeException('Facebook upload error: '.json_encode($json));
    }
    return $json['id'];
}
