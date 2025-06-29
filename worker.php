<?php
/**
 * worker.php – background job executor (supports both CLI and HTTP)
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

// 1) Did we even start?
dbg("START worker.php");

// 2) Determine jobId (CLI or HTTP GET)
if (isset($argv[1]) && trim($argv[1]) !== '') {
    $jobId = $argv[1];
    dbg("Got jobId from CLI: {$jobId}");
} elseif (!empty($_GET['jobId'])) {
    $jobId = $_GET['jobId'];
    dbg("Got jobId from HTTP GET: {$jobId}");
} else {
    dbg("ERROR: Missing jobId");
    exit(1);
}

$jobDir      = __DIR__ . '/jobs';
$metaFile    = "{$jobDir}/{$jobId}.json";
$progressFile= "{$jobDir}/{$jobId}.progress";

// 3) Validate metadata file
if (!is_file($metaFile)) {
    dbg("ERROR: Meta file not found: {$metaFile}");
    exit(1);
}
dbg("Found meta file");

// 4) Load metadata
$meta   = json_decode(file_get_contents($metaFile), true);
$files  = $meta['files']  ?? [];
$params = $meta['params'] ?? [];
dbg("Files to process: ".count($files));

// 5) Scan existing progress for completed
$completed = [];
if (is_file($progressFile)) {
    foreach (file($progressFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
        $m = json_decode($line, true);
        if (isset($m['phase'],$m['status'],$m['filename'])
            && $m['phase']==='done' && $m['status']==='success'
        ) {
            $completed[$m['filename']] = true;
        }
    }
}
dbg("Already completed: ".count($completed));

// 6) (Re)initialize progress file
dbg("Truncating progress file");
file_put_contents($progressFile, '');

// 7) Process each file
foreach ($files as $i => $f) {
    $name = $f['name'];
    dbg("[{$i}] Starting {$name}");

    // Skip if done
    if (isset($completed[$name])) {
        dbg("[{$i}] Skipping {$name} (already success)");
        progress('skip', $name, 100, 'skipped');
        continue;
    }

    try {
        // Download
        dbg("[{$i}] download start");
        progress('download', $name, 0);
        $tmp = downloadDriveFile($f['id'], $name, $params['googleApiKey']);
        progress('download', $name, 100);
        dbg("[{$i}] download done => {$tmp}");

        // Upload
        dbg("[{$i}] upload start");
        progress('upload', $name, 0);
        $vid = fbUploadVideo($tmp, $params['accessToken'], $params['accountId']);
        progress('upload', $name, 100);
        dbg("[{$i}] upload done => video_id={$vid}");

        // Done
        progress('done', $name, 100, 'success', ['video_id' => $vid]);
        @unlink($tmp);
        dbg("[{$i}] completed successfully");
    }
    catch (Throwable $e) {
        dbg("[{$i}] ERROR: ".$e->getMessage());
        progress('done', $name, 100, 'error', ['error' => $e->getMessage()]);
    }
}

// 8) Signal overall completion
touch("{$jobDir}/{$jobId}.done");
dbg("FINISHED worker.php\n");

/**
 * Append one JSON‐line to the progress file.
 */
function progress(string $phase, string $file, int $pct, string $status='running', array $extra=[]): void {
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
 * Download a Drive file to temp, try API then uc?download.
 */
function downloadDriveFile(string $id, string $name, string $apiKey): string {
    $dest = sys_get_temp_dir().'/'.basename($name);
    $url1 = "https://www.googleapis.com/drive/v3/files/{$id}?alt=media&key={$apiKey}";
    $c1 = curlDownload($url1, $dest);
    if ($c1 < 400) return $dest;
    $url2 = "https://drive.google.com/uc?export=download&id={$id}";
    $c2 = curlDownload($url2, $dest);
    if ($c2 < 400) return $dest;
    throw new RuntimeException("Drive download failed (API {$c1}, uc {$c2})");
}

/**
 * cURL GET to a path, return HTTP status code.
 */
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

/**
 * Upload a video file to a Facebook Ad Account via /{account}/advideos.
 */
function fbUploadVideo(string $path, string $token, string $account): string {
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
