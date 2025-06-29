<?php
/*  Facebook Rocket‑Launcher – upload.php
    ===============================================================
    v1.2  (2025‑06‑29)  – duplicate‑title check + unlimited runtime
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);  exit('Only POST allowed');
}
header('Content-Type: application/json; charset=utf-8');

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$folderId     = $data['folderId']     ?? '';
$googleKey    = $data['googleApiKey'] ?? '';
$accessToken  = $data['accessToken']  ?? '';
$accountId    = $data['accountId']    ?? '';
$isCountOnly  = !empty($data['count']);

foreach (['folderId','googleApiKey','accessToken','accountId'] as $k) {
    if (!$$k) { http_response_code(400); exit(json_encode(['error'=>"Missing $k"])); }
}

/*─────────── 0. Run‑time ceilings – blow them away ───────────*/
set_time_limit(0);
ini_set('max_execution_time','0');
ini_set('zlib.output_compression',0);
ob_implicit_flush(1);

/*─────────── 1. Helper to fetch existing FB video titles ─────*/
function listFbTitles(string $accountId,string $token): array {
    $url = "https://graph.facebook.com/v19.0/{$accountId}/advideos"
         . "?fields=id,title&limit=5000&access_token={$token}";
    $out = [];
    do{
        $raw = @file_get_contents($url);
        if ($raw === false) break;
        $js  = json_decode($raw,true);
        foreach ($js['data']??[] as $v) $out[$v['title']] = $v['id'];
        $url = $js['paging']['next'] ?? null;
    }while($url);
    return $out;
}

/*─────────── 2. Quick “how many files” branch ────────────────*/
if ($isCountOnly) {
    $g = "https://www.googleapis.com/drive/v3/files"
       . "?q='".urlencode($folderId)."'%20in%20parents"
       . "&fields=files(id)&key={$googleKey}";
    $cnt = 0;
    if ($js = @json_decode(@file_get_contents($g), true))
        $cnt = count($js['files'] ?? []);
    echo $cnt;    // ← **plain integer, not JSON**
    exit;
}

/*─────────── 3. Build job state & spawn worker ───────────────*/
$existingTitles = listFbTitles($accountId,$accessToken);
$tmpDir = sys_get_temp_dir().'/fb-job-'.uniqid();
mkdir($tmpDir,0777,true);

$state = [
  'dir'        => $tmpDir,
  'folderId'   => $folderId,
  'googleKey'  => $googleKey,
  'accessToken'=> $accessToken,
  'accountId'  => $accountId,
  'existing'   => $existingTitles,
];
file_put_contents("$tmpDir/state.json", json_encode($state, JSON_UNESCAPED_SLASHES));

$jobId = basename($tmpDir);
$cmd   = PHP_BINDIR.'/php '.escapeshellarg(__DIR__.'/worker.php').' '.escapeshellarg($jobId).' > /dev/null 2>&1 &';
exec($cmd);

echo json_encode(['jobId'=>$jobId], JSON_UNESCAPED_SLASHES);
