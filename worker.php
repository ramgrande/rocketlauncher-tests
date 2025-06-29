<?php
/**
 * worker.php – CLI‐friendly, idempotent: will skip files
 * that have already made it to done/success in the .progress log.
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

$jobDir        = __DIR__ . '/jobs';
$metaFile      = "$jobDir/{$jobId}.json";
$progressFile  = "$jobDir/{$jobId}.progress";

// Validate
if (!is_file($metaFile)) {
    error_log("worker.php: job meta not found: $metaFile");
    exit;
}

// ── Load metadata ─────────────────────────────────────
$meta   = json_decode(file_get_contents($metaFile), true);
$files  = $meta['files']  ?? [];
$params = $meta['params'] ?? [];

// ── Scan existing progress for completed files ────────
$completed = [];
if (is_file($progressFile)) {
    foreach (file($progressFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line) {
        $m = json_decode($line, true);
        if (isset($m['phase'], $m['status'])
            && $m['phase']==='done' && $m['status']==='success'
            && isset($m['filename'])
        ) {
            $completed[$m['filename']] = true;
        }
    }
}

// ── (Re)initialize progress ───────────────────────────
file_put_contents($progressFile, '');

// ── Process each file, skipping if already done ───────
foreach ($files as $f) {
    $name = $f['name'];

    // SKIP if already marked done/success
    if (isset($completed[$name])) {
        progress('skip', $name, 100, 'skipped');
        continue;
    }

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

// Signal completion
touch("$jobDir/$jobId.done");


// ── Helpers ──────────────────────────────────────────

function progress($phase, $file, $pct, $status='running', $extra=[]): void
{
    global $progressFile;
    $data = array_merge([
        'phase'    => $phase,
        'filename' => $file,
        'pct'      => $pct,
        'status'   => $status,
    ], $extra);
    file_put_contents($progressFile, json_encode($data) . "\n", FILE_APPEND);
}

// ... downloadDriveFile, curlDownload, fbUploadVideo remain unchanged ...
