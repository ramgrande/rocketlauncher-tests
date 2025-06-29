<?php
/**
 *  progress.php – Server-Sent Events + inline worker w/ Drive fallback
 *  --------------------------------------------------------------------
 *  Streams upload progress and performs Drive→Facebook uploads in one request,
 *  using a fallback to the file’s webContentLink if `?alt=media` returns 403.
 */
declare(strict_types=1);
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors',   '0');
ini_set('log_errors',       '1');
ini_set('error_log', __DIR__.'/php-error.log');

// — SSE headers —
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// — Validate jobId & load metadata —
$jobId   = $_GET['jobId'] ?? '';
$jobFile = __DIR__ . "/jobs/{$jobId}.json";
if (!$jobId || !is_file($jobFile)) {
    echo "data: " . json_encode(['error' => 'Unknown or missing jobId']) . "\n\n";
    exit;
}
$meta   = json_decode(file_get_contents($jobFile), true);
$files  = $meta['files'];
$params = $meta['params'];  // ['folderId','googleApiKey','accessToken','accountId']

// — Send initial file list —
echo "data: " . json_encode([
    'init'  => true,
    'files' => array_column($files, 'name'),
]) . "\n\n";
@ob_flush(); @flush();

// — Process each file inline —
foreach ($files as $f) {
    $name = $f['name'];
    try {
        // Download step
        sendEvent('download', $name, 0);
        $tmp = downloadDriveFile($f['id'], $name, $params['googleApiKey']);
        sendEvent('download', $name, 100);

        // Upload step
        sendEvent('upload', $name, 0);
        $vid = fbUploadVideo($tmp, $params['accessToken'], $params['accountId']);
        sendEvent('upload', $name, 100);

        // Done
        sendEvent('done', $name, 100, 'success', ['video_id' => $vid]);
        @unlink($tmp);
    } catch (Throwable $e) {
        sendEvent('done', $name, 100, 'error', ['error' => $e->getMessage()]);
    }
}

// — Signal overall completion —
echo "event: done\ndata: {}\n\n";
@ob_flush(); @flush();


// ──────────────── Helper Functions ─────────────────

/**
 * Send one SSE “data:” event with the given payload.
 */
function sendEvent(string $phase, string $filename, int $pct,
                   string $status = 'running', array $extra = []): void
{
    $data = array_merge([
        'phase'    => $phase,
        'filename' => $filename,
        'pct'      => $pct,
        'status'   => $status,
    ], $extra);
    echo 'data: ' . json_encode($data) . "\n\n";
    @ob_flush(); @flush();
}

/**
 * Download a Drive file to a temp path, falling back to webContentLink if needed.
 */
function downloadDriveFile(string $id, string $name, string $apiKey): string
{
    $dest = sys_get_temp_dir() . '/' . basename($name);

    // 1) Try direct `?alt=media`
    $url1  = "https://www.googleapis.com/drive/v3/files/{$id}?alt=media&key={$apiKey}";
    $code1 = curlDownload($url1, $dest);
    if ($code1 < 400) {
        return $dest;
    }

    // 2) Fallback: fetch webContentLink from metadata
    $metaUrl = "https://www.googleapis.com/drive/v3/files/{$id}"
             . "?fields=webContentLink&key={$apiKey}";
    $meta    = curlGet($metaUrl);
    if (empty($meta['webContentLink'])) {
        throw new RuntimeException("No webContentLink available for file {$id}");
    }

    // 3) Download via webContentLink
    $code2 = curlDownload($meta['webContentLink'], $dest);
    if ($code2 >= 400) {
        throw new RuntimeException("Drive download failed HTTP {$code2}");
    }
    return $dest;
}

/**
 * Perform a cURL GET that returns JSON, throwing on HTTP >=400.
 */
function curlGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('cURL GET error');
    }
    if ($code >= 400) {
        throw new RuntimeException("cURL GET failed HTTP {$code}");
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON from {$url}");
    }
    return $json;
}

/**
 * Download a URL to a file path, returning the HTTP status code.
 */
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

/**
 * Upload a local video file to Facebook (single-shot ≤25 MB).
 */
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

    $json = json_decode($res, true);
    if (empty($json['id'])) {
        throw new RuntimeException('Facebook upload error: ' . ($res ?: 'no response'));
    }
    return $json['id'];
}
