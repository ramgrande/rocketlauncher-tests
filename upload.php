<?php
/**
 * Drive → Facebook bulk uploader (chunked, low-RAM, Facebook-side duplicate detection, optional NDJSON stream)
 *
 * POST JSON
 * {
 *   "folderId"      : "<Google-Drive folder ID>",
 *   "googleApiKey"  : "<Google API key>",
 *   "accessToken"   : "<FB long-lived user/system-user token>",
 *   "accountId"     : "act_<ad-account ID>",
 *   "stream"        : true | false   // default false – if true, rows flushed as NDJSON
 * }
 */

@ini_set('display_errors', 0);
set_error_handler(fn($s, $m) => exit(json_encode(['error' => 'php_error', 'detail' => $m])));
set_exception_handler(fn($e)   => exit(json_encode(['error' => 'exception', 'detail' => $e->getMessage()])));
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && ($e['type'] & E_ERROR)) {
    echo json_encode(['error' => 'fatal', 'detail' => $e['message']]);
  }
});

$req = json_decode(file_get_contents('php://input'), true) ?: [];

$folderId     = trim($req['folderId']     ?? '');
$googleApiKey = trim($req['googleApiKey'] ?? '');
$fbToken      = trim($req['accessToken']  ?? '');
$adAccount    = trim($req['accountId']    ?? '');
$stream       = !empty($req['stream']);

if (!$folderId || !$googleApiKey || !$fbToken || !$adAccount) {
  exit(json_encode(['error' => 'missing_param']));
}

$FB_VER = 'v19.0';

function curl_json(string $url, array $post = null): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_FOLLOWLOCATION => 1,
    CURLOPT_SSL_VERIFYPEER => 1,
  ]);
  if ($post !== null) {
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
  }
  $raw  = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = json_decode($raw, true);
  if ($json === null) $json = ['raw' => $raw];
  $json['_http_code'] = $http;
  return $json;
}

function listDriveVideos(string $folderId, string $apiKey): array {
  $q   = urlencode("'$folderId' in parents and mimeType contains 'video/' and trashed=false");
  $url = "https://www.googleapis.com/drive/v3/files?q={$q}&fields=files(id,name)&key={$apiKey}";
  $j   = curl_json($url);
  if ($j['_http_code'] !== 200) {
    return ['error' => 'drive_' . $j['_http_code']];
  }

  $out = [];
  foreach ($j['files'] ?? [] as $f) {
    if (preg_match('/\.mp4$/i', $f['name'])) {
      $out[] = ['id' => $f['id'], 'name' => $f['name']];
    }
  }
  return $out;
}

function downloadTmp(string $fileId, string $apiKey): ?string {
  $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&key={$apiKey}";
  $tmp = tempnam(sys_get_temp_dir(), 'vid_') . '.mp4';
  $fh  = fopen($tmp, 'w');

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_FILE           => $fh,
    CURLOPT_FOLLOWLOCATION => 1,
  ]);
  $ok   = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  fclose($fh);

  if (!$ok || $code !== 200) {
    @unlink($tmp);
    return null;
  }
  return $tmp;
}

function listFBVideos(string $act, string $token, string $ver): array {
  $url = "https://graph.facebook.com/{$ver}/{$act}/advideos?fields=id,title&limit=5000&access_token=" . urlencode($token);
  $map = [];

  do {
    $j = curl_json($url);
    if (isset($j['error'])) {
      return ['_error' => $j['error']];
    }
    foreach ($j['data'] ?? [] as $v) {
      if (isset($v['title'])) {
        $map[$v['title']] = $v['id'];
      }
    }
    $url = $j['paging']['next'] ?? null;
  } while ($url);

  return $map;
}

function fbUpload(string $tmp, string $fname, string $act, string $token, string $ver): array {
  $size = filesize($tmp);
  $start = curl_json("https://graph-video.facebook.com/{$ver}/{$act}/advideos", [
    'access_token' => $token,
    'upload_phase' => 'start',
    'file_size'    => $size,
  ]);
  if (isset($start['error'])) {
    return [null, $start['error']['message'] ?? 'start_fail'];
  }

  $session     = $start['upload_session_id'];
  $startOffset = (int)$start['start_offset'];
  $endOffset   = (int)$start['end_offset'];

  $fh = fopen($tmp, 'rb');
  while ($startOffset < $endOffset) {
    $len     = $endOffset - $startOffset;
    fseek($fh, $startOffset);
    $chunk   = fread($fh, $len);
    $transfer = curl_json("https://graph-video.facebook.com/{$ver}/{$act}/advideos", [
      'access_token'      => $token,
      'upload_phase'      => 'transfer',
      'upload_session_id' => $session,
      'start_offset'      => $startOffset,
      'video_file_chunk'  => new CURLFile('data://video/mp4;base64,' . base64_encode($chunk), 'video/mp4', $fname),
    ]);
    if (isset($transfer['error'])) {
      fclose($fh);
      return [null, $transfer['error']['message'] ?? 'transfer_fail'];
    }
    $startOffset = (int)$transfer['start_offset'];
    $endOffset   = (int)$transfer['end_offset'];
  }
  fclose($fh);

  $finish = curl_json("https://graph-video.facebook.com/{$ver}/{$act}/advideos", [
    'access_token'      => $token,
    'upload_phase'      => 'finish',
    'upload_session_id' => $session,
    'title'             => pathinfo($fname, PATHINFO_FILENAME),
  ]);
  if (isset($finish['error'])) {
    return [null, $finish['error']['message'] ?? 'finish_fail'];
  }

  return [$finish['video_id'] ?? null, null];
}

function emitRow(array $row, bool $stream): void {
  if ($stream) {
    echo json_encode($row) . "\n";
    @ob_flush();
    @flush();
  }
}

// Fetch existing FB videos
$fbMap = listFBVideos($adAccount, $fbToken, $FB_VER);
if (isset($fbMap['_error'])) {
  echo json_encode([[ 
    'filename'  => null,
    'video_id'  => null,
    'error'     => 'fb_list_fail',
    'detail'    => $fbMap['_error']['message'] ?? $fbMap['_error'],
    'skipped'   => false
  ]]);
  exit;
}

// List & upload new videos from Drive
$files = listDriveVideos($folderId, $googleApiKey);
if (isset($files['error'])) {
  echo json_encode([[
    'filename' => null,
    'video_id' => null,
    'error'    => $files['error'],
    'skipped'  => false
  ]]);
  exit;
}

if ($stream) {
  header('Content-Type: application/x-ndjson');
  header('X-Accel-Buffering: no');
}

$result = [];
foreach ($files as $f) {
  $base = pathinfo($f['name'], PATHINFO_FILENAME);
  if (isset($fbMap[$base])) {
    $row = [
      'filename' => $f['name'],
      'video_id' => $fbMap[$base],
      'error'    => null,
      'skipped'  => true
    ];
    $result[] = $row;
    emitRow($row, $stream);
    continue;
  }

  $tmp = downloadTmp($f['id'], $googleApiKey);
  if (!$tmp) {
    $row = [
      'filename' => $f['name'],
      'video_id' => null,
      'error'    => 'download_failed',
      'skipped'  => false
    ];
    $result[] = $row;
    emitRow($row, $stream);
    continue;
  }

  [$vid, $err] = fbUpload($tmp, $f['name'], $adAccount, $fbToken, $FB_VER);
  unlink($tmp);

  $row = [
    'filename' => $f['name'],
    'video_id' => $vid,
    'error'    => $err,
    'skipped'  => false
  ];
  $result[] = $row;
  emitRow($row, $stream);
}

// Save for XLSX export
file_put_contents('latest_fb_ids.json', json_encode($result, JSON_PRETTY_PRINT));
if (!$stream) {
  echo json_encode($result);
}
