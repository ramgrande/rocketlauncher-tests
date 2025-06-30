<?php
/**
 *  upload.php – JSON API
 *  ---------------------
 *  POST body (application/json):
 *    {
 *      "folderId"   : "...",   // Google Drive folder
 *      "googleApiKey": "...",
 *      "accessToken": "...",   // Facebook Graph token
 *      "accountId"  : "act_123456",
 *      "count"      : true     // optional flag: only return file count
 *    }
 *
 *  © 2025 –  MIT Licence – Minimal demo, **not** production‑grade security.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');          // Keep JSON output clean
ini_set('log_errors', '1');
ini_set('error_log', __DIR__.'/php-error.log');

header('Content-Type: application/json');

try {
    /* 1. Parse JSON safely */
    $payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

    foreach (['folderId','googleApiKey','accessToken','accountId'] as $key) {
        if (empty($payload[$key])) {
            throw new InvalidArgumentException("Missing field: $key");
        }
    }
    ['folderId'=>$folderId,
     'googleApiKey'=>$googleApiKey,
     'accessToken'=>$accessToken,
     'accountId'=>$accountId] = $payload;

    /* 2‑A  Quick “count only” branch */
    if (!empty($payload['count'])) {
        echo json_encode(['count' => countDriveVideos($folderId, $googleApiKey)],
                         JSON_THROW_ON_ERROR);
        exit;
    }

    /* 2‑B  Create a background job */
    $jobId  = uniqid('job_', true);
    $jobDir = __DIR__ . '/jobs';
    if (!is_dir($jobDir)) mkdir($jobDir, 0777, true);

    // Gather list of videos first
    $driveFiles = listDriveVideos($folderId, $googleApiKey);

    file_put_contents("$jobDir/$jobId.json", json_encode([
        'created' => time(),
        'files'   => $driveFiles,
        'params'  => compact('folderId','googleApiKey','accessToken','accountId')
    ], JSON_PRETTY_PRINT));

    // Kick off the detached worker
    $cmd = escapeshellcmd(PHP_BINARY)." ".escapeshellarg(__DIR__.'/worker.php')
         ." ".escapeshellarg($jobId)." > /dev/null 2>&1 &";
    exec($cmd);

    echo json_encode(['jobId'=>$jobId], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}

/*──────────────────────── Helper functions ───────────────────────────*/

function countDriveVideos(string $folderId, string $apiKey): int {
    return count(listDriveVideos($folderId, $apiKey));
}

function listDriveVideos(string $folderId, string $apiKey): array {
    $pageToken = null;  $out = [];
    do {
        $url = 'https://www.googleapis.com/drive/v3/files'
             .'?q='.urlencode("'$folderId' in parents and mimeType contains 'video/' and trashed=false")
             .'&fields=files(id,name,size,mimeType),nextPageToken'
             .'&pageSize=1000'.($pageToken ? "&pageToken=$pageToken" : '')
             ."&key=$apiKey";

        $json = curlGet($url);
        $out  = array_merge($out, $json['files'] ?? []);
        $pageToken = $json['nextPageToken'] ?? null;
    } while ($pageToken);

    return $out;
}

function curlGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FAILONERROR    => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('cURL: '.curl_error($ch));
    }
    curl_close($ch);
    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}
