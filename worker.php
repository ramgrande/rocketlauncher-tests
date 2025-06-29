#!/usr/bin/env php
<?php
/*  Facebook Rocket‑Launcher – worker.php
    ===============================================================
    v1.2  (2025‑06‑29)  – duplicate handling, progress pings
*/

if (php_sapi_name() !== 'cli') { fwrite(STDERR,"CLI only\n"); exit(1); }
set_time_limit(0);

$jobId = $argv[1] ?? '';
if (!$jobId) { fwrite(STDERR,"Usage: worker.php JOB_ID\n"); exit(1); }

$dir   = sys_get_temp_dir().'/'.$jobId;
$state = json_decode(@file_get_contents("$dir/state.json"), true);
if (!$state) { fwrite(STDERR,"state.json not found\n"); exit(1); }

/*────────── 0. Helpers ─────────────────────────────────────────*/
$evFile = "$dir/events.sse";
function progress(array $m){ global $evFile;
  file_put_contents($evFile, 'data: '.json_encode($m, JSON_UNESCAPED_SLASHES)."\n\n", FILE_APPEND);
}

function httpGet(string $url, array $opts=[]){
  $ch = curl_init($url);
  curl_setopt_array($ch, $opts + [
    CURLOPT_RETURNTRANSFER=>1,
    CURLOPT_FOLLOWLOCATION=>1,
    CURLOPT_CONNECTTIMEOUT=>30,
    CURLOPT_TIMEOUT=>0,
  ]);
  $data = curl_exec($ch);
  if(curl_errno($ch)) throw new Exception(curl_error($ch));
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  if($code >= 400)     throw new Exception("HTTP $code");
  return $data;
}

/*────────── 1. List files in Drive folder ─────────────────────*/
$gApi = "https://www.googleapis.com/drive/v3/files"
      . "?q='".urlencode($state['folderId'])."'%20in%20parents"
      . "&fields=files(id,name,size,mimeType)"
      . "&key={$state['googleKey']}";
$resp = json_decode(httpGet($gApi), true);
$files= $resp['files'] ?? [];

progress(['init'=>true,'files'=>array_column($files,'name')]);

/*────────── 2. Iterate files ──────────────────────────────────*/
$outJson = [];                  // for latest_fb_ids.json
foreach ($files as $f){

  $title = pathinfo($f['name'], PATHINFO_FILENAME);

  /*== duplicate? =================================================*/
  if (isset($state['existing'][$title])){
    progress([
      'phase'   =>'done',
      'status'  =>'duplicate',
      'filename'=>$f['name'],
      'video_id'=>$state['existing'][$title],
    ]);
    $outJson[] = ['filename'=>$f['name'],'video_id'=>$state['existing'][$title]];
    continue;
  }

  /*== 2‑A  download from Drive ===================================*/
  progress(['phase'=>'download','filename'=>$f['name'],'pct'=>0]);
  $downloadUrl = "https://www.googleapis.com/drive/v3/files/{$f['id']}?alt=media&key={$state['googleKey']}";
  $tmpPath = tempnam(sys_get_temp_dir(), 'dl_');
  $fh = fopen($tmpPath,'w');
  $ch = curl_init($downloadUrl);
  curl_setopt_array($ch,[
    CURLOPT_FILE           => $fh,
    CURLOPT_FOLLOWLOCATION => 1,
    CURLOPT_NOPROGRESS     => 0,
    CURLOPT_PROGRESSFUNCTION => function($dlSize,$dlNow) use ($f){
      if ($dlSize>0){
        $pct = (int)round(($dlNow/$dlSize)*100);
        progress(['phase'=>'download','filename'=>$f['name'],'pct'=>$pct]);
      }
    },
    CURLOPT_CONNECTTIMEOUT => 20,
    CURLOPT_TIMEOUT        => 0,
  ]);
  curl_exec($ch);
  fclose($fh);
  progress(['phase'=>'download','filename'=>$f['name'],'pct'=>100]);

  /*== 2‑B  upload to Facebook ====================================*/
  progress(['phase'=>'upload','filename'=>$f['name'],'pct'=>0]);
  $uploadUrl = "https://graph-video.facebook.com/v19.0/{$state['accountId']}/advideos";
  $ch = curl_init($uploadUrl);
  curl_setopt_array($ch,[
    CURLOPT_POST          => 1,
    CURLOPT_RETURNTRANSFER=> 1,
    CURLOPT_POSTFIELDS    => [
      'access_token' => $state['accessToken'],
      'title'        => $title,
      'source'       => new CURLFile($tmpPath, '', $f['name']),
    ],
    CURLOPT_NOPROGRESS    => 0,
    CURLOPT_PROGRESSFUNCTION => function($uSize,$uNow) use ($f){
      if ($uSize>0){
        $pct = (int)round(($uNow/$uSize)*100);
        progress(['phase'=>'upload','filename'=>$f['name'],'pct'=>$pct]);
      }
    },
  ]);
  $raw = curl_exec($ch);
  $err = curl_errno($ch) ? curl_error($ch) : null;
  $js  = $err ? null : json_decode($raw,true);
  $vid = $js['id'] ?? '';
  progress(['phase'=>'upload','filename'=>$f['name'],'pct'=>100]);

  /*== 2‑C  final status ==========================================*/
  $ok = $vid && !$err;
  progress([
    'phase'   =>'done',
    'status'  =>$ok?'success':'error',
    'filename'=>$f['name'],
    'video_id'=>$vid,
  ]);
  if ($ok) $outJson[] = ['filename'=>$f['name'],'video_id'=>$vid];

  @unlink($tmpPath);
}

/*────────── 3. Write latest_fb_ids.json (for XLSX builder) ─────*/
file_put_contents(__DIR__.'/latest_fb_ids.json', json_encode($outJson,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
