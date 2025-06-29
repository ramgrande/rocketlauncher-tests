<?php
/**
 *  worker.php – Detached job executor (inline or CLI)
 *
 *  Usage via CLI:
 *      php worker.php <jobId>
 *
 *  Usage inline (after fastcgi_finish_request in upload.php):
 *      require 'worker.php';
 *      // upload.php must set $_GET['jobId'] = $jobId before require.
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/php-error.log');

// ── Determine jobId ─────────────────────────────────────
if (php_sapi_name() === 'cli') {
    $jobId = $argv[1] ?? exit("Missing jobId\n");
} else {
    $jobId = $_GET['jobId'] ?? exit("No jobId in \$_GET\n");
}

$jobDir       = __DIR__.'/jobs';
$metaFile     = "$jobDir/$jobId.json";
$progressFile = "$jobDir/$jobId.progress";

// Validate metadata file
if (!is_file($metaFile)) {
    error_log("worker.php: job meta not found: $metaFile");
    exit;
}

// Load metadata
$meta   = json_decode(file_get_contents($metaFile), true);
$files  = $meta['files']  ?? [];
$params = $meta['params'] ?? [];

// Truncate or create the progress file
file_put_contents($progressFile, '');

// ── Process each file ───────────────────────────────────
foreach ($files as $f) {
    $name = $f['name'];
    try {
        // 1) Download
        progress('download', $name, 0);
        $tmp = downloadDriveFile($f['id'], $name, $params['googleApiKey']);
        progress('download', $name, 100);

        // 2) Upload
        progress('upload', $name, 0);
        $videoId = fbUploadVideo($tmp, $params['accessToken'], $params['accountId']);
        progress('upload', $name, 100);

        // 3) Done
        progress('done', $name, 100, 'success', ['video_id' => $videoId]);
        @unlink($tmp);

    } catch (Throwable $e) {
        progress('done', $name, 100, 'error', ['error'=>$e->getMessage()]);
    }
}

// Signal that the worker is complete
touch("$jobDir/$jobId.done");


// ── Helpers ────────────────────────────────────────────

/**
 * Append one JSON‐line to the progress file.
 */
function progress(string $phase, string $file, int $pct,
                  string $status='running', array $extra=[]): void
{
    global $progressFile;
    $data = array_merge([
        'phase'    => $phase,
        'filename' => $file,
        'pct'      => $pct,
        'status'   => $status,
    ], $extra);

    file_put_contents($progressFile, json_encode($data)."\n", FILE_APPEND);
}

/**
 * Download a Drive file to a temp path, trying both API and uc?download.
 */
function downloadDriveFile(string $id, string $name, string $apiKey): string
{
    $dest = sys_get_temp_dir().'/'.basename($name);

    // Attempt #1: official API endpoint
    $url1  = "https://www.googleapis.com/drive/v3/files/{$id}?alt=media&key={$apiKey}";
    $code1 = curlDownload($url1, $dest);
    if ($code1 < 400) {
        return $dest;
    }

    // Attempt #2: universal “forced download” URL
    $url2  = "https://drive.google.com/uc?export=download&id={$id}";
    $code2 = curlDownload($url2, $dest);
    if ($code2 < 400) {
        return $dest;
    }

    throw new RuntimeException(
        "Drive download failed (alt=media HTTP {$code1}, uc?download HTTP {$code2})"
    );
}

/**
 * Perform a cURL GET to a file path and return the HTTP status code.
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
 * Upload a video to a Facebook Ad Account using /{account}/advideos.
 */
function fbUploadVideo(string $path, string $token, string $account): string
{
    $endpoint = "https://graph-video.facebook.com/v19.0/{$account}/advideos";
    $ch = curl_init($endpoint);
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
        throw new RuntimeException('Facebook upload error: '.($res ?: 'no response'));
    }
    return $json['id'];
}
