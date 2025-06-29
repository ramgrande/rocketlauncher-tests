<?php
/**
 * upload.php – JSON API to enqueue jobs and then run worker.php
 * asynchronously after the HTTP response via fastcgi_finish_request().
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__.'/php-error.log');

// 1) Parse and validate JSON body
$body = file_get_contents('php://input');
try {
    $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(400);
    die(json_encode(['error'=>'Invalid JSON']));
}

foreach (['folderId','googleApiKey','accessToken','accountId'] as $k) {
    if (empty($payload[$k])) {
        http_response_code(400);
        die(json_encode(['error'=>"Missing field: $k"]));
    }
}

$folderId     = $payload['folderId'];
$googleApiKey = $payload['googleApiKey'];
$accessToken  = $payload['accessToken'];
$accountId    = $payload['accountId'];

// 2) Handle the COUNT-only branch
if (!empty($payload['count'])) {
    $n = countDriveVideos($folderId, $googleApiKey);
    header('Content-Type: application/json');
    echo json_encode(['count'=>$n]);
    exit;
}

// 3) Enqueue a new job
$jobId  = uniqid('job_', true);
$jobDir = __DIR__ . '/jobs';
if (!is_dir($jobDir)) mkdir($jobDir, 0777, true);

// List the files from Drive
$driveFiles = listDriveVideos($folderId, $googleApiKey);
// Write the job meta-file
file_put_contents("$jobDir/$jobId.json", json_encode([
    'created' => time(),
    'files'   => $driveFiles,
    'params'  => compact('folderId','googleApiKey','accessToken','accountId'),
], JSON_PRETTY_PRINT));

// 4) Return the jobId immediately
header('Content-Type: application/json');
echo json_encode(['jobId'=>$jobId]);

// 5) Detach and run the worker
if (function_exists('fastcgi_finish_request')) {
    // flush all response data to the client
    fastcgi_finish_request();

    // now safely run the long‐running worker
    $_GET['jobId'] = $jobId;           // so worker.php sees the jobId
    require __DIR__ . '/worker.php';   // worker writes to jobs/*.progress in the background
    // after worker ends, PHP process exits naturally
}

exit;




/* ─────────── Helper functions for Drive ─────────── */

function countDriveVideos(string $folderId, string $apiKey): int {
    return count(listDriveVideos($folderId, $apiKey));
}

function listDriveVideos(string $folderId, string $apiKey): array {
    $out       = [];
    $pageToken = null;
    do {
        $url = 'https://www.googleapis.com/drive/v3/files'
             . '?q=' . urlencode("'$folderId' in parents and mimeType contains 'video/' and trashed=false")
             . '&fields=files(id,name),nextPageToken'
             . '&pageSize=1000'
             . ($pageToken ? "&pageToken=$pageToken" : '')
             . "&key=$apiKey";

        $json = curlGet($url);
        foreach ($json['files'] as $f) {
            $out[] = ['id'=>$f['id'], 'name'=>$f['name']];
        }
        $pageToken = $json['nextPageToken'] ?? null;
    } while ($pageToken);

    return $out;
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

    if ($raw === false) {
        throw new RuntimeException("cURL error requesting $url");
    }
    if ($code >= 400) {
        throw new RuntimeException("Drive metadata fetch failed HTTP $code");
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON from $url");
    }
    return $json;
}
