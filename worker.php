<?php
/**
 * worker.php – background job executor with Facebook pre-check
 * Usage (CLI): php worker.php <jobId>
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/php-error.log');

$debugFile = __DIR__ . '/jobs/debug.log';
function dbg(string $msg): void {
    global $debugFile;
    file_put_contents($debugFile, date('c').' | '.$msg."\n", FILE_APPEND);
}

// ── 1) Startup ─────────────────────────────────────────
dbg("START worker.php");

// Determine jobId (CLI or HTTP)
if (php_sapi_name() === 'cli' && !empty($argv[1])) {
    $jobId = $argv[1];
    dbg("JobId from CLI: {$jobId}");
} elseif (!empty($_GET['jobId'])) {
    $jobId = $_GET['jobId'];
    dbg("JobId from HTTP GET: {$jobId}");
} else {
    dbg("ERROR: Missing jobId");
    exit(1);
}

$jobDir       = __DIR__ . '/jobs';
$metaFile     = "$jobDir/{$jobId}.json";
$progressFile = "$jobDir/{$jobId}.progress";

if (!is_file($metaFile)) {
    dbg("ERROR: Meta file not found: {$metaFile}");
    exit(1);
}
dbg("Loaded meta file");

// ── 2) Load job metadata ──────────────────────────────
$meta    = json_decode(file_get_contents($metaFile), true);
$files   = $meta['files']  ?? [];
$params  = $meta['params'] ?? [];
dbg("Found ".count($files)." files in job");

// ── 3) Fetch existing FB video titles ─────────────────
dbg("Fetching existing FB video titles…");
$existingFB = [];
$after      = null;
do {
    $url = "https://graph.facebook.com/v19.0/{$params['accountId']}/advideos"
         . "?fields=title&limit=1000"
         . ($after ? "&after={$after}" : "")
         . "&access_token={$params['accessToken']}";
    $resp = curlGet($url);
    foreach ($resp['data'] as $vid) {
        if (!empty($vid['title'])) {
            $existingFB[$vid['title']] = true;
        }
    }
    $after = $resp['paging']['cursors']['after'] ?? null;
} while ($after);
dbg("Found ".count($existingFB)." existing FB titles");

// ── 4) Prepare progress file ──────────────────────────
file_put_contents($progressFile, '');
dbg("Truncated progress file");

// ── 5) Main loop ──────────────────────────────────────
foreach ($files as $i => $f) {
    $name = $f['name'];
    dbg("[$i] Processing {$name}");
    $base = pathinfo($name, PATHINFO_FILENAME);

    // Pre-check: skip if already on FB
    if (isset($existingFB[$base])) {
        dbg("[$i] Skipping {$name} (already on Facebook)");
        progress('skip', $name, 100, 'skipped');
        continue;
    }

    try {
        // Download
        dbg("[$i] download start");
        progress('download', $name, 0);
        $tmp = downloadDriveFile($f['id'], $name, $params['googleApiKey']);
        progress('download', $name, 100);
        dbg("[$i] downloaded to {$tmp}");

        // Upload (include title so it matches our check)
        dbg("[$i] upload start");
        progress('upload', $name, 0);
        $vid = fbUploadVideo($tmp, $params['accessToken'], $params['accountId'], $base);
        progress('upload', $name, 100);
        dbg("[$i] uploaded, got video_id={$vid}");

        // Done
        progress('done', $name, 100, 'success', ['video_id' => $vid]);
        @unlink($tmp);
        dbg("[$i] done successfully");
    }
    catch (Throwable $e) {
        dbg("[$i] ERROR: ".$e->getMessage());
        progress('done', $name, 100, 'error', ['error' => $e->getMessage()]);
    }
}

// ── 6) Signal completion ───────────────────────────────
touch("$jobDir/{$jobId}.done");
dbg("FINISHED worker.php\n");


// ── Helper functions ───────────────────────────────────

function progress(string $phase, string $file, int $pct, string $status='running', array $extra=[]): void {
    global $progressFile;
    $data = array_merge([
        'phase'=>$phase,'filename'=>$file,'pct'=>$pct,'status'=>$status
    ], $extra);
    file_put_contents($progressFile, json_encode($data)."\n", FILE_APPEND);
}

function downloadDriveFile(string $id, string $name, string $apiKey): string {
    $dest = sys_get_temp_dir().'/'.basename($name);
    $url1 = "https://www.googleapis.com/drive/v3/files/{$id}?alt=media&key={$apiKey}";
    $c1   = curlDownload($url1, $dest);
    if ($c1 < 400) {
        return $dest;
    }
    $url2 = "https://drive.google.com/uc?export=download&id={$id}";
    $c2   = curlDownload($url2, $dest);
    if ($c2 < 400) {
        return $dest;
    }
    throw new RuntimeException("Drive download failed (API {$c1}, uc {$c2})");
}

function curlDownload(string $url, string $outPath): int {
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

function curlGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code >= 400) {
        throw new RuntimeException("HTTP {$code} fetching {$url}");
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON from {$url}");
    }
    return $json;
}

/**
 * Upload a video to a Facebook Ad Account via /{account}/advideos.
 * Includes the 'title' field so our pre-check can match filename.
 */
function fbUploadVideo(string $path, string $token, string $account, string $title): string {
    $endpoint = "https://graph-video.facebook.com/v19.0/{$account}/advideos";
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => [
            'access_token' => $token,
            'source'       => new CURLFile($path),
            'title'        => $title,
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
