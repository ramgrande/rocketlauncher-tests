<?php
/**
 *  progress.php – SSE + inline worker w/ robust Drive download fallback
 */
declare(strict_types=1);
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/php-error.log');

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// 1) Load job metadata
$jobId   = $_GET['jobId'] ?? '';
$jobFile = __DIR__ . "/jobs/{$jobId}.json";
if (!$jobId || !is_file($jobFile)) {
    echo "data: ".json_encode(['error'=>'Unknown or missing jobId'])."\n\n";
    exit;
}
$meta   = json_decode(file_get_contents($jobFile), true);
$files  = $meta['files']  ?? [];
$params = $meta['params'] ?? [];

// 2) Send initial file list
echo "data: ".json_encode([
    'init'  => true,
    'files' => array_column($files,'name'),
])."\n\n";
@ob_flush(); @flush();

// 3) Process each file
foreach ($files as $f) {
    $name = $f['name'];
    try {
        sendEvent('download',$name,0);
        $tmp = downloadDriveFile(
            $f['id'],
            $name,
            $params['googleApiKey']
        );
        sendEvent('download',$name,100);

        sendEvent('upload',$name,0);
        $vid = fbUploadVideo(
            $tmp,
            $params['accessToken'],
            $params['accountId']
        );
        sendEvent('upload',$name,100);

        sendEvent('done',$name,100,'success',['video_id'=>$vid]);
        @unlink($tmp);

    } catch (Throwable $e) {
        // Final catch — includes HTTP codes when failing download
        sendEvent('done',$name,100,'error',['error'=>$e->getMessage()]);
    }
}

// 4) Signal overall completion
echo "event: done\ndata: {}\n\n";
@ob_flush(); @flush();


/** Helper: emit one SSE data event **/
function sendEvent(string $phase, string $filename, int $pct,
                   string $status='running', array $extra=[]): void
{
    $payload = array_merge([
        'phase'    => $phase,
        'filename' => $filename,
        'pct'      => $pct,
        'status'   => $status,
    ], $extra);
    echo 'data: '.json_encode($payload)."\n\n";
    @ob_flush(); @flush();
}

/**
 * Download a Drive file to a local path,
 * 1) via API alt=media
 * 2) fallback via uc?export=download
 * Throws on both failures (includes both HTTP codes).
 */
function downloadDriveFile(string $id, string $name, string $apiKey): string
{
    $dest = sys_get_temp_dir().'/'.basename($name);

    // Attempt #1: official API
    $url1  = "https://www.googleapis.com/drive/v3/files/{$id}"
           . "?alt=media&key={$apiKey}";
    $code1 = curlDownload($url1, $dest);
    if ($code1 < 400) {
        return $dest;
    }

    // Attempt #2: force-download URL
    $url2  = "https://drive.google.com/uc?export=download&id={$id}";
    $code2 = curlDownload($url2, $dest);
    if ($code2 < 400) {
        return $dest;
    }

    // Both failed: throw with both codes
    throw new RuntimeException(
        "Drive download failed (alt=media HTTP {$code1}, uc?download HTTP {$code2})"
    );
}

/** cURL GET to file, returns HTTP status code **/
function curlDownload(string $url, string $outPath): int
{
    $ch = curl_init($url);
    $fp = fopen($outPath, 'w');
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 0,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    return $code;
}

/** Upload a video to Facebook, returns new video ID **/
function fbUploadVideo(string $path, string $token, string $account): string
{
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

    $json = json_decode($res, true) ?: [];
    if (empty($json['id'])) {
        throw new RuntimeException('Facebook upload error: '
                                 . ($res ?: 'no response'));
    }
    return $json['id'];
}
