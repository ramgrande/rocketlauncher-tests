<?php
/**
 * upload.php – JSON API to enqueue jobs and kick off worker asynchronously
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

header('Content-Type: application/json');

try {
    // 1) Parse JSON body
    $payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['folderId','googleApiKey','accessToken','accountId'] as $k) {
        if (empty($payload[$k])) {
            throw new InvalidArgumentException("Missing field: $k");
        }
    }
    $folderId     = $payload['folderId'];
    $googleApiKey = $payload['googleApiKey'];
    $accessToken  = $payload['accessToken'];
    $accountId    = $payload['accountId'];

    // 2) Count-only branch
    if (!empty($payload['count'])) {
        $n = countDriveVideos($folderId, $googleApiKey);
        echo json_encode(['count' => $n], JSON_THROW_ON_ERROR);
        exit;
    }

    // 3) Enqueue a new job
    $jobId  = uniqid('job_', true);
    $jobDir = __DIR__ . '/jobs';
    if (!is_dir($jobDir)) mkdir($jobDir, 0777, true);

    // List Drive videos & write job metadata
    $driveFiles = listDriveVideos($folderId, $googleApiKey);
    file_put_contents("$jobDir/$jobId.json", json_encode([
        'created' => time(),
        'files'   => $driveFiles,
        'params'  => compact('folderId','googleApiKey','accessToken','accountId')
    ], JSON_PRETTY_PRINT));

    // 4) Fire off worker.php via non-blocking HTTP
    $host = $_SERVER['HTTP_HOST'];
    $port = $_SERVER['SERVER_PORT'] ?? 80;
    $path = dirname($_SERVER['REQUEST_URI']) . "/worker.php?jobId=" . urlencode($jobId);

    $fp = @fsockopen($host, $port, $errno, $errstr, 1);
    if ($fp) {
        $out  = "GET $path HTTP/1.1\r\n";
        $out .= "Host: $host\r\n";
        $out .= "Connection: Close\r\n\r\n";
        fwrite($fp, $out);
        fclose($fp);
    }

    // 5) Respond immediately with jobId
    echo json_encode(['jobId' => $jobId], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}



/** Helpers for Drive listing **/

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
        foreach ($json['files'] ?? [] as $f) {
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
        throw new RuntimeException("cURL GET error for $url");
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
