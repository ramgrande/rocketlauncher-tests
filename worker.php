<?php
/**
 *  worker.php  – Detached job executor
 *  -----------------------------------
 *  Called from upload.php:   php worker.php <jobId>
 *  Writes newline‑separated JSON to jobs/<jobId>.progress
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

foreach ($files as $i => $f) {
    $name = $f['name'];
    try {
        /* 1. Download from Drive → /tmp */
        $tmp = downloadDriveFile($f['id'], $name, $params['googleApiKey']);
        progress('download', $name, 100);

        /* 2. Upload single‑chunk (≤25 MB) to Facebook */
        $videoId = fbUploadVideo($tmp, $params['accessToken'], $params['accountId']);
        progress('upload', $name, 100);

        /* 3. Done! */
        progress('done', $name, 100, 'success', ['video_id'=>$videoId]);
        @unlink($tmp);
    } catch (Throwable $e) {
        progress('done', $name, 100, 'error', ['error'=>$e->getMessage()]);
    }
}

touch("$jobDir/$jobId.done");

/*───────────────────────── helper + progress() ───────────────────────*/

function progress(string $phase, string $file, int $pct,
                  string $status='running', array $extra=[]): void {
    global $progressFile;
    $p = json_encode(array_merge([
        'phase'=>$phase,'filename'=>$file,'pct'=>$pct,'status'=>$status
    ], $extra));
    file_put_contents($progressFile, $p."\n", FILE_APPEND);
}

function downloadDriveFile(string $id, string $name, string $apiKey): string {
    $url  = "https://www.googleapis.com/drive/v3/files/$id?alt=media&key=$apiKey";
    $dest = sys_get_temp_dir().'/'.$name;
    $ch   = curl_init($url);
    $fp   = fopen($dest, 'w');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 0
    ]);
    curl_exec($ch);
    fclose($fp);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400) {
        throw new RuntimeException('Google Drive download failed');
    }
    curl_close($ch);
    return $dest;
}

function fbUploadVideo(string $path, string $token, string $account): string {
    /* one‑shot upload (≤25 MB). For larger files implement chunked transfer */
    $ch = curl_init("https://graph-video.facebook.com/v19.0/$account/videos");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => [
            'access_token' => $token,
            'source'       => new CURLFile($path)
        ]
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['id'])) {
        throw new RuntimeException('Facebook upload failed: '.json_encode($res));
    }
    return $res['id'];        // video_id
}
