<?php
/**
 *  worker.php – Detached job executor with chunked Facebook upload
 *  ----------------------------------------------------------------
 *  Called from upload.php: php worker.php <jobId>
 *  Writes newline-separated JSON to jobs/<jobId>.progress
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('log_errors','1');
ini_set('display_errors','0');

$jobId      = $argv[1] ?? '';
$jobDir     = __DIR__ . '/jobs';
$meta       = json_decode(file_get_contents("$jobDir/$jobId.json"), true);
$params     = $meta['params'];    // folderId, googleApiKey, accessToken, accountId
$files      = $meta['files'];
$progressFile = "$jobDir/$jobId.progress";
file_put_contents($progressFile, ''); // truncate

// Credentials and config
$fbToken    = $params['accessToken'];
$fbAccount  = $params['accountId'];    // ad account ID (numeric)
$googleKey  = $params['googleApiKey'];
$fbVersion  = 'v19.0';

// 1. List existing FB videos, merging current API results with
//    entries from latest_fb_ids.json -> map of title => id
$existing = [];
$idsFile = __DIR__ . '/latest_fb_ids.json';
if (is_file($idsFile)) {
    $json = json_decode(file_get_contents($idsFile), true);
    if (is_array($json)) {
        foreach ($json as $row) {
            if (!empty($row['filename']) && !empty($row['video_id'])) {
                $bn = pathinfo($row['filename'], PATHINFO_FILENAME);
                $existing[$bn] = $row['video_id'];
            }
        }
    }
}
$nextPage = null;
do {
    $endpoint = sprintf(
        'https://graph.facebook.com/%s/act_%s/advideos?fields=title,id&limit=100&access_token=%s',
        $fbVersion, urlencode($fbAccount), urlencode($fbToken)
    );
    if ($nextPage) {
        $endpoint = $nextPage;
    }
    $response = graphJson($endpoint);
    if (!empty($response['error'])) {
        progress('warning','facebook_list',0,'error',['error'=>'fb_list_fail']);
        break;
    }
    foreach ($response['data'] ?? [] as $video) {
        if (!empty($video['title'])) {
            // store exact title string
            $existing[$video['title']] = $video['id'];
        }
    }
    // paging -> next URL
    $nextPage = $response['paging']['next'] ?? null;
} while ($nextPage);

// 2. Process each Drive file
foreach ($files as $file) {
    $fullName = $file['name'];
    $baseName = pathinfo($fullName, PATHINFO_FILENAME);

    // Skip if title exists
    if (isset($existing[$baseName])) {
        progress('skipped', $fullName, 100, 'success', ['video_id' => $existing[$baseName]]);
        continue;
    }

    try {
        // a) Download from Drive
        $tmpPath = downloadDriveFile($file['id'], $fullName, $googleKey);
        progress('download', $fullName, 100);

        // b) Chunked upload to FB with title = baseName
        list($videoId, $error) = fbChunkedUpload($tmpPath, $fullName, $baseName, $fbAccount, $fbToken, $fbVersion);
        if ($error) {
            throw new RuntimeException($error);
        }
        progress('upload', $fullName, 100);
        progress('done',   $fullName, 100, 'success', ['video_id' => $videoId]);
        $existing[$baseName] = $videoId; // avoid duplicates later in this run
        @unlink($tmpPath);
    } catch (Throwable $e) {
        progress('done', $fullName, 100, 'error', ['error' => $e->getMessage()]);
    }
}

touch("$jobDir/$jobId.done");

// --- Helper functions ---
function graphJson(string $url, array $postFields = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $postFields,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['data'=>[], 'paging'=>[]];
}

function downloadDriveFile(string $fileId, string $fileName, string $apiKey): string {
    $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&key={$apiKey}";
    $dest = sys_get_temp_dir() . '/' . $fileName;
    $ch = curl_init($url);
    $fp = fopen($dest, 'w');
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 0,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if ($code >= 400) throw new RuntimeException('Google Drive download failed');
    return $dest;
}

function fbChunkedUpload(string $filePath, string $fileName, string $title, string $account, string $token, string $version): array {
    $size = filesize($filePath);
    // -- start phase
    $start = graphJson("https://graph-video.facebook.com/{$version}/act_{$account}/advideos", [
        'access_token' => $token,
        'upload_phase' => 'start',
        'file_size'    => $size,
    ]);
    if (!empty($start['error'])) {
        return [null, $start['error']['message'] ?? 'start_error'];
    }
    $sessionId = $start['upload_session_id'];
    $offset    = (int)$start['start_offset'];
    $end       = (int)$start['end_offset'];
    $videoId   = $start['video_id'] ?? null;
    $fh = fopen($filePath, 'rb');

    // -- transfer phase
    while ($offset < $end) {
        fseek($fh, $offset);
        $chunk = fread($fh, $end - $offset);
        $transfer = graphJson("https://graph-video.facebook.com/{$version}/act_{$account}/advideos", [
            'access_token'      => $token,
            'upload_phase'      => 'transfer',
            'upload_session_id' => $sessionId,
            'start_offset'      => $offset,
            'video_file_chunk'  => new CURLFile('data://video/mp4;base64,' . base64_encode($chunk), 'video/mp4', $fileName),
        ]);
        if (!empty($transfer['error'])) {
            fclose($fh);
            return [null, $transfer['error']['message']];
        }
        $offset = (int)$transfer['start_offset'];
        $end    = (int)$transfer['end_offset'];
    }
    fclose($fh);

    // -- finish phase with title
    $finish = graphJson("https://graph-video.facebook.com/{$version}/act_{$account}/advideos", [
        'access_token'      => $token,
        'upload_phase'      => 'finish',
        'upload_session_id' => $sessionId,
        'title'             => $title,
    ]);
    if (!empty($finish['error'])) {
        return [null, $finish['error']['message'] ?? 'finish_error'];
    }
    return [$finish['video_id'] ?? $videoId, null];
}

function progress(string $phase, string $file, int $pct, string $status = 'running', array $extra = []): void {
    global $progressFile;
    $entry = array_merge([
        'phase'    => $phase,
        'filename' => $file,
        'pct'      => $pct,
        'status'   => $status,
    ], $extra);
    file_put_contents($progressFile, json_encode($entry) . "\n", FILE_APPEND);
}
