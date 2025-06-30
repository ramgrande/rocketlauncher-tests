<?php
/**
 *  worker.php  – Detached job executor with chunked Facebook upload
 *  ---------------------------------------------------------------
 *  Called from upload.php:   php worker.php <jobId>
 *  Writes newline-separated JSON to jobs/<jobId>.progress
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('log_errors','1');
ini_set('display_errors','0');

$jobId  = $argv[1] ?? '';
$jobDir = __DIR__ . '/jobs';
$meta   = json_decode(file_get_contents("$jobDir/$jobId.json"), true);
$params = $meta['params'];   // folderId, googleApiKey, accessToken, accountId
$files  = $meta['files'];

$progressFile = "$jobDir/$jobId.progress";
file_put_contents($progressFile, ''); // truncate

$fbToken   = $params['accessToken'];
$fbAccount = $params['accountId']; // ad account ID
$GOOGLE_API_KEY = $params['googleApiKey'];
$FB_VER = 'v19.0';

// 1. Build map of existing FB videos: title => id
$existing = [];
$after = null;
do {
    $url = sprintf(
        'https://graph.facebook.com/%s/act_%s/advideos?fields=title,id&limit=100&access_token=%s',
        $FB_VER, urlencode($fbAccount), urlencode($fbToken)
    );
    if ($after) {
        $url .= '&after=' . urlencode($after);
    }
    $resp = curl_exec(curl_init($url));
    $info = curl_getinfo(curl_init($url), CURLINFO_HTTP_CODE);
    if ($info >= 400 || !$resp) {
        progress('warning','facebook_list',0,'error',['error'=>'FB list fail']);
        break;
    }
    $data = json_decode($resp, true);
    foreach ($data['data'] ?? [] as $v) {
        if (!empty($v['title'])) {
            $existing[$v['title']] = $v['id'];
        }
    }
    $after = $data['paging']['cursors']['after'] ?? null;
} while ($after);

foreach ($files as $f) {
    $fileName = $f['name'];
    $base = pathinfo($fileName, PATHINFO_FILENAME);
    if (isset($existing[$base])) {
        progress('skipped',$fileName,100,'success',['video_id'=>$existing[$base]]);
        continue;
    }
    try {
        $tmp = downloadDriveFile($f['id'], $fileName, $GOOGLE_API_KEY);
        progress('download',$fileName,100);
        [$vid, $err] = fbChunkedUpload($tmp, $fileName, $fbAccount, $fbToken, $FB_VER);
        if ($err) throw new RuntimeException($err);
        progress('upload',$fileName,100);
        progress('done',$fileName,100,'success',['video_id'=>$vid]);
        @unlink($tmp);
    } catch (Throwable $e) {
        progress('done',$fileName,100,'error',['error'=>$e->getMessage()]);
    }
}

touch("$jobDir/$jobId.done");

// --- Helpers ---
function fbChunkedUpload(string $filePath, string $fname, string $account, string $token, string $ver): array {
    $size = filesize($filePath);
    // start
    $start = graphJson("https://graph-video.facebook.com/{$ver}/act_{$account}/advideos", [
        'access_token' => $token,
        'upload_phase' => 'start',
        'file_size'    => $size
    ]);
    if (!empty($start['error'])) return [null, $start['error']['message'] ?? 'start_fail'];
    $session = $start['upload_session_id'];
    $startOffset = (int)$start['start_offset'];
    $endOffset = (int)$start['end_offset'];
    $videoId = $start['video_id'] ?? null;
    $fh = fopen($filePath, 'rb');
    // transfer
    while ($startOffset < $endOffset) {
        fseek($fh, $startOffset);
        $chunk = fread($fh, $endOffset - $startOffset);
        $transfer = graphJson("https://graph-video.facebook.com/{$ver}/act_{$account}/advideos", [
            'access_token'      => $token,
            'upload_phase'      => 'transfer',
            'upload_session_id' => $session,
            'start_offset'      => $startOffset,
            'video_file_chunk'  => new CURLFile('data://video/mp4;base64,' . base64_encode($chunk), 'video/mp4', $fname)
        ]);
        if (!empty($transfer['error'])) { fclose($fh); return [null, $transfer['error']['message']]; }
        $startOffset = (int)$transfer['start_offset'];
        $endOffset = (int)$transfer['end_offset'];
    }
    fclose($fh);
    // finish
    $finish = graphJson("https://graph-video.facebook.com/{$ver}/act_{$account}/advideos", [
        'access_token'      => $token,
        'upload_phase'      => 'finish',
        'upload_session_id' => $session,
        'title'             => $base = pathinfo($fname, PATHINFO_FILENAME)
    ]);
    if (!empty($finish['error'])) return [null, $finish['error']['message'] ?? 'finish_fail'];
    return [$finish['video_id'] ?? $videoId, null];
}

function graphJson(string $url, array $post = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $post ?: []
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($raw, true) ?: [];
    return $json;
}

function downloadDriveFile(string $id, string $name, string $apiKey): string {
    $url = "https://www.googleapis.com/drive/v3/files/{$id}?alt=media&key={$apiKey}";
    $dest = sys_get_temp_dir() . '/' . $name;
    $ch = curl_init($url);
    $fp = fopen($dest, 'w');
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 0]);
    curl_exec($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 400) throw new RuntimeException('Google Drive download failed');
    curl_close($ch);
    fclose($fp);
    return $dest;
}

function progress(string $phase, string $file, int $pct, string $status = 'running', array $extra = []): void {
    global $progressFile;
    $entry = array_merge(['phase'=>$phase,'filename'=>$file,'pct'=>$pct,'status'=>$status], $extra);
    file_put_contents($progressFile, json_encode($entry) . "\n", FILE_APPEND);
}
