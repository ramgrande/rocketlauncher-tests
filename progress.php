<?php
/**
 *  progress.php – SSE + inline worker w/ detailed Drive fallback debugging
 *  ----------------------------------------------------------------------
 *  Streams progress, attempts `?alt=media`, then falls back to webContentLink,
 *  and reports both HTTP status codes in any error message.
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

// Load job meta
$jobId   = $_GET['jobId'] ?? '';
$jobFile = __DIR__ . "/jobs/{$jobId}.json";
if (!$jobId || !is_file($jobFile)) {
    echo "data: ".json_encode(['error'=>'Unknown or missing jobId'])."\n\n";
    exit;
}
$meta   = json_decode(file_get_contents($jobFile), true);
$files  = $meta['files'] ?? [];
$params = $meta['params'] ?? [];

// Initial file list
echo "data: ".json_encode([
    'init'=>true,
    'files'=>array_column($files,'name'),
])."\n\n";
@ob_flush(); @flush();

foreach ($files as $f) {
    $name = $f['name'];
    try {
        sendEvent('download',$name,0);
        $tmp = downloadDriveFile($f['id'],$name,$params['googleApiKey']);
        sendEvent('download',$name,100);

        sendEvent('upload',$name,0);
        $vid = fbUploadVideo($tmp,$params['accessToken'],$params['accountId']);
        sendEvent('upload',$name,100);

        sendEvent('done',$name,100,'success',['video_id'=>$vid]);
        @unlink($tmp);
    } catch (Throwable $e) {
        sendEvent('done',$name,100,'error',['error'=>$e->getMessage()]);
    }
}

// Signal done
echo "event: done\ndata: {}\n\n";
@ob_flush(); @flush();


// ──────────────── HELPERS ───────────────────────

function sendEvent(string $phase, string $filename, int $pct,
                   string $status='running', array $extra=[]): void
{
    $payload = array_merge([
        'phase'=>$phase,
        'filename'=>$filename,
        'pct'=>$pct,
        'status'=>$status,
    ], $extra);
    echo 'data: '.json_encode($payload)."\n\n";
    @ob_flush(); @flush();
}

function downloadDriveFile(string $id, string $name, string $apiKey): string
{
    $dest = sys_get_temp_dir().'/'.basename($name);

    // 1) direct alt=media
    $url1  = "https://www.googleapis.com/drive/v3/files/{$id}?alt=media&key={$apiKey}";
    $code1 = curlDownload($url1,$dest);
    if ($code1 < 400) {
        return $dest;
    }

    // 2) fetch webContentLink
    $metaUrl = "https://www.googleapis.com/drive/v3/files/{$id}"
             ."?fields=webContentLink&key={$apiKey}";
    $meta    = curlGet($metaUrl);
    $link    = $meta['webContentLink'] ?? '';
    if (!$link) {
        throw new RuntimeException(
            "No webContentLink. alt=media HTTP {$code1}"
        );
    }

    // 3) download via webContentLink
    $code2 = curlDownload($link,$dest);
    if ($code2 < 400) {
        return $dest;
    }

    // both failed
    throw new RuntimeException(
        "Drive download failed: alt=media HTTP {$code1}, "
      . "webContentLink HTTP {$code2}"
    );
}

/** cURL GET to a file path, returns HTTP status code **/
function curlDownload(string $url, string $outPath): int
{
    $ch = curl_init($url);
    $fp = fopen($outPath,'w');
    curl_setopt_array($ch,[
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 0,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    return $code;
}

/** cURL GET that returns JSON array, throws on HTTP>=400 **/
function curlGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException("cURL error for {$url}");
    }
    if ($code >= 400) {
        throw new RuntimeException("Metadata request failed HTTP {$code}");
    }
    $json = json_decode($raw,true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON from {$url}");
    }
    return $json;
}

function fbUploadVideo(string $path, string $token, string $account): string
{
    $ch = curl_init("https://graph-video.facebook.com/v19.0/{$account}/videos");
    curl_setopt_array($ch,[
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => [
            'access_token'=>$token,
            'source'=>new CURLFile($path),
        ],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($res,true) ?: [];
    if (empty($json['id'])) {
        throw new RuntimeException('FB upload error: '.($res?:'no response'));
    }
    return $json['id'];
}
