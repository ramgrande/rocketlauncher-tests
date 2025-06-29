<?php
/**
 * worker.php – background job (CLI only).
 *   argv[1] = jobId
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/php-error.log');

$debug = __DIR__.'/jobs/debug.log';
$ts    = date('c');
file_put_contents($debug, "$ts  START worker.php\n", FILE_APPEND);

// 1) Grab jobId
if (php_sapi_name() !== 'cli' || empty($argv[1])) {
    file_put_contents($debug, "$ts  ERROR: worker must be run via CLI with jobId\n", FILE_APPEND);
    exit(1);
}
$jobId = $argv[1];
file_put_contents($debug, "$ts  jobId = $jobId\n", FILE_APPEND);

$jobDir      = __DIR__.'/jobs';
$metaFile    = "$jobDir/{$jobId}.json";
$progressFile= "$jobDir/{$jobId}.progress";

if (!is_file($metaFile)) {
    file_put_contents($debug, "$ts  ERROR: metaFile not found $metaFile\n", FILE_APPEND);
    exit(1);
}

file_put_contents($debug, "$ts  Loading metadata\n", FILE_APPEND);
$meta   = json_decode(file_get_contents($metaFile), true);
$files  = $meta['files']  ?? [];
$params = $meta['params'] ?? [];

file_put_contents($debug, "$ts  Files to process: ".count($files)."\n", FILE_APPEND);
// clear old progress
file_put_contents($progressFile, '');

foreach ($files as $i => $f) {
    $name = $f['name'];
    file_put_contents($debug, "$ts  [$i] Starting file $name\n", FILE_APPEND);

    try {
        // Download
        progress('download',$name,0);
        $tmp = downloadDriveFile($f['id'],$name,$params['googleApiKey']);
        progress('download',$name,100);
        file_put_contents($debug, "$ts  [$i] Downloaded to $tmp\n", FILE_APPEND);

        // Upload
        progress('upload',$name,0);
        $vid = fbUploadVideo($tmp,$params['accessToken'],$params['accountId']);
        progress('upload',$name,100);
        file_put_contents($debug, "$ts  [$i] Uploaded video_id=$vid\n", FILE_APPEND);

        // Done
        progress('done',$name,100,'success',['video_id'=>$vid]);
        @unlink($tmp);
        file_put_contents($debug, "$ts  [$i] Done\n", FILE_APPEND);

    } catch (Throwable $e) {
        progress('done',$name,100,'error',['error'=>$e->getMessage()]);
        file_put_contents($debug, "$ts  [$i] ERROR: ".$e->getMessage()."\n", FILE_APPEND);
    }
}

// all done
touch("$jobDir/{$jobId}.done");
file_put_contents($debug, "$ts  FINISHED\n\n", FILE_APPEND);


// ─────── Helpers ───────────────────────────────────────

function progress(string $phase, string $file, int $pct, string $status='running', array $extra=[]): void {
    global $progressFile;
    $data = array_merge([
        'phase'=>$phase,'filename'=>$file,'pct'=>$pct,'status'=>$status
    ], $extra);
    file_put_contents($progressFile, json_encode($data)."\n", FILE_APPEND);
}

function downloadDriveFile(string $id, string $name, string $apiKey): string {
    $dest = sys_get_temp_dir().'/'.basename($name);
    $u1 = "https://www.googleapis.com/drive/v3/files/$id?alt=media&key=$apiKey";
    $c1 = curlDownload($u1,$dest);
    if ($c1<400) return $dest;
    $u2 = "https://drive.google.com/uc?export=download&id=$id";
    $c2 = curlDownload($u2,$dest);
    if ($c2<400) return $dest;
    throw new RuntimeException("Drive download failed ($c1,$c2)");
}

function curlDownload(string $url, string $outPath): int {
    $ch=fopen($outPath,'w') ? curl_init($url) : exit;
    $fp=fopen($outPath,'w');
    curl_setopt_array($ch,[
        CURLOPT_FILE=>$fp,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_TIMEOUT=>0
    ]);
    curl_exec($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    return $code;
}

function fbUploadVideo(string $path, string $token, string $account): string {
    $endpoint = "https://graph-video.facebook.com/v19.0/{$account}/advideos";
    $ch = curl_init($endpoint);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POSTFIELDS=>[
            'access_token'=>$token,
            'source'=>new CURLFile($path),
        ],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $j = json_decode($res,true) ?: [];
    if (empty($j['id'])) throw new RuntimeException('FB upload error: '.$res);
    return $j['id'];
}
