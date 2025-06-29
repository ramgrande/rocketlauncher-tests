<?php
/**
 * upload.php – enqueue job + spawn worker.php via CLI exec()
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/php-error.log');

header('Content-Type: application/json');

try {
    // 1. Parse + validate
    $body = file_get_contents('php://input');
    $p    = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    foreach (['folderId','googleApiKey','accessToken','accountId'] as $k) {
        if (empty($p[$k])) throw new InvalidArgumentException("Missing field: $k");
    }
    $folderId     = $p['folderId'];
    $googleApiKey = $p['googleApiKey'];
    $accessToken  = $p['accessToken'];
    $accountId    = $p['accountId'];

    // 2. COUNT only?
    if (!empty($p['count'])) {
        echo json_encode(['count'=>countDriveVideos($folderId,$googleApiKey)], JSON_THROW_ON_ERROR);
        exit;
    }

    // 3. Enqueue
    $jobId  = uniqid('job_', true);
    $jobDir = __DIR__ . '/jobs';
    if (!is_dir($jobDir) && !mkdir($jobDir, 0777, true)) {
        throw new RuntimeException("Cannot create jobs dir");
    }
    $files = listDriveVideos($folderId, $googleApiKey);
    file_put_contents("$jobDir/{$jobId}.json", json_encode([
        'created'=>time(),
        'files'  =>$files,
        'params' =>compact('folderId','googleApiKey','accessToken','accountId'),
    ], JSON_PRETTY_PRINT));

    // 4. Spawn worker via CLI exec()
    //    Use an explicit CLI PHP binary rather than PHP_BINARY to avoid CGI
    $phpCli = '/usr/local/bin/php';             // ← adjust this path if needed
    $worker = __DIR__ . '/worker.php';
    $cmd    = escapeshellcmd("$phpCli $worker $jobId") . " > /dev/null 2>&1 &";
    error_log("upload.php exec: $cmd");
    exec($cmd, $out, $ret);
    if ($ret !== 0) {
        error_log("upload.php: exec returned code $ret, output: ".implode("\n",$out));
    }

    // 5. Return jobId immediately
    echo json_encode(['jobId'=>$jobId], JSON_THROW_ON_ERROR);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>$e->getMessage()]);
    exit;
}

/* ─── Helpers ───────────────────────────────────────── */

function countDriveVideos(string $folderId, string $key): int {
    return count(listDriveVideos($folderId,$key));
}

function listDriveVideos(string $folderId, string $key): array {
    $out=[]; $tok=null;
    do {
        $url = "https://www.googleapis.com/drive/v3/files"
             . "?q=".urlencode("'$folderId' in parents and mimeType contains 'video/' and trashed=false")
             . "&fields=files(id,name),nextPageToken"
             . "&pageSize=1000".($tok?"&pageToken=$tok":"")
             . "&key=$key";
        $j = curlGet($url);
        foreach ($j['files'] as $f) {
            $out[]=['id'=>$f['id'],'name'=>$f['name']];
        }
        $tok = $j['nextPageToken'] ?? null;
    } while($tok);
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
    if ($raw===false)       throw new RuntimeException("cURL failed for $url");
    if ($code>=400)         throw new RuntimeException("HTTP $code for $url");
    $j = json_decode($raw,true);
    if (!is_array($j))      throw new RuntimeException("Invalid JSON from $url");
    return $j;
}
