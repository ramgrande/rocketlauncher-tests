<?php
/**
 * upload.php – enqueue a job and kick off worker.php via exec()
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/php-error.log');

header('Content-Type: application/json');

try {
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

    // Count-only?
    if (!empty($payload['count'])) {
        $n = countDriveVideos($folderId, $googleApiKey);
        echo json_encode(['count'=>$n]);
        exit;
    }

    // Enqueue job
    $jobId = uniqid('job_',true);
    $jobDir = __DIR__.'/jobs';
    is_dir($jobDir) || mkdir($jobDir,0777,true);

    $driveFiles = listDriveVideos($folderId, $googleApiKey);
    file_put_contents("$jobDir/$jobId.json", json_encode([
        'created'=>time(),
        'files'=>$driveFiles,
        'params'=>compact('folderId','googleApiKey','accessToken','accountId'),
    ], JSON_PRETTY_PRINT));

    // Fire off worker.php in background
    $php = PHP_BINARY;
    $cmd = escapeshellcmd("$php " . escapeshellarg(__DIR__.'/worker.php') . ' ' . escapeshellarg($jobId))
         . " > /dev/null 2>&1 &";
    exec($cmd, $out, $ret);

    echo json_encode(['jobId'=>$jobId]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
}


/* ─── Helpers ───────────────────────────────── */

function countDriveVideos(string $folderId, string $apiKey): int {
    return count(listDriveVideos($folderId, $apiKey));
}

function listDriveVideos(string $folderId, string $apiKey): array {
    $out=[]; $token=null;
    do {
        $u = 'https://www.googleapis.com/drive/v3/files'
           . '?q='.urlencode("'$folderId' in parents and mimeType contains 'video/' and trashed=false")
           . '&fields=files(id,name),nextPageToken'
           . '&pageSize=1000' . ($token?"&pageToken=$token":'')
           . "&key=$apiKey";
        $json = curlGet($u);
        foreach($json['files']?:[] as $f) {
            $out[]=['id'=>$f['id'],'name'=>$f['name']];
        }
        $token = $json['nextPageToken'] ?? null;
    } while($token);
    return $out;
}

function curlGet(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_TIMEOUT=>30,
    ]);
    $raw = curl_exec($ch);
    $code= curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($raw===false || $code>=400) {
        throw new RuntimeException("HTTP $code fetching $url");
    }
    $j=json_decode($raw,true);
    if(!is_array($j)) throw new RuntimeException("Invalid JSON");
    return $j;
}
