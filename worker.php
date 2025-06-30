<?php
/**
 *  worker.php  – Detached job executor
 *  -----------------------------------
 *  Called from upload.php:   php worker.php <jobId>
 *  Writes newline-separated JSON to jobs/<jobId>.progress
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('log_errors','1');
ini_set('display_errors','0');

$jobId  = $argv[1] ?? '';
$jobDir = __DIR__.'/jobs';
$meta   = json_decode(file_get_contents("$jobDir/$jobId.json"), true);
$params = $meta['params'];   // folderId, googleApiKey, accessToken, accountId
$files  = $meta['files'];

$progressFile = "$jobDir/$jobId.progress";
file_put_contents($progressFile, '');     // truncate

// 0. Fetch existing videos on the Ad Account to skip duplicates
$fbToken   = $params['accessToken'];
$fbAccount = $params['accountId']; // ad account ID, e.g. '1234567890'
$existingTitles = [];
$after = null;
do {
    // Query Graph API for the ad account's videos
    $url = sprintf(
        'https://graph.facebook.com/v19.0/act_%s/advideos?fields=title&limit=100&access_token=%s',
        urlencode($fbAccount),
        urlencode($fbToken)
    );
    if ($after) {
        $url .= '&after=' . urlencode($after);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 400 || !$response) {
        progress('warning', 'facebook_list', 0, 'error', ['error' => 'Failed to fetch Facebook video list']);
        break;
    }
    $data = json_decode($response, true);
    foreach ($data['data'] ?? [] as $video) {
        if (!empty($video['title'])) {
            $existingTitles[] = $video['title'];
        }
    }
    $after = $data['paging']['cursors']['after'] ?? null;
} while ($after);

foreach ($files as $f) {
    $filename = $f['name'];
    // Strip extension for title comparison
    $baseName = pathinfo($filename, PATHINFO_FILENAME);

    // Skip if already on Facebook
    if (in_array($baseName, $existingTitles, true)) {
        progress('skipped', $filename, 100, 'success', ['message' => 'already_exists']);
        continue;
    }

    try {
        // 1. Download from Drive → /tmp
        $tmp = downloadDriveFile($f['id'], $filename, $params['googleApiKey']);
        progress('download', $filename, 100);

        // 2. Upload with title = baseName
        $videoId = fbUploadVideo($tmp, $fbToken, $fbAccount, $baseName);
        progress('upload', $filename, 100);

        // 3. Done
        progress('done', $filename, 100, 'success', ['video_id' => $videoId]);
        @unlink($tmp);
    } catch (Throwable $e) {
        progress('done', $filename, 100, 'error', ['error' => $e->getMessage()]);
    }
}

touch("$jobDir/$jobId.done");

// Helpers

function progress(string $phase, string $file, int $pct, string $status = 'running', array $extra = []): void {
    global $progressFile;
    $entry = array_merge([
        'phase'    => $phase,
        'filename' => $file,
        'pct'      => $pct,
        'status'   => $status
    ], $extra);
    file_put_contents($progressFile, json_encode($entry) . "\n", FILE_APPEND);
}

function downloadDriveFile(string $id, string $name, string $apiKey): string {
    $url  = "https://www.googleapis.com/drive/v3/files/{$id}?alt=media&key={$apiKey}";
    $dest = sys_get_temp_dir() . '/' . $name;
    $ch   = curl_init($url);
    $fp   = fopen($dest, 'w');
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 0]);
    curl_exec($ch);
    fclose($fp);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400) {
        throw new RuntimeException('Google Drive download failed');
    }
    curl_close($ch);
    return $dest;
}

function fbUploadVideo(string $path, string $token, string $account, string $title): string {
    $ch = curl_init("https://graph-video.facebook.com/v19.0/act_{$account}/advideos");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => [
            'access_token' => $token,
            'source'       => new CURLFile($path),
            'title'        => $title,
        ],
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['id'])) {
        throw new RuntimeException('Facebook upload failed: ' . json_encode($res));
    }
    return $res['id'];
}
