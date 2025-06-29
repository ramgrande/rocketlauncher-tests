<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/php-error.log');

$debug  = __DIR__.'/jobs/debug.log';
$prefix = date('c').' | ';

// 1) Did we even start?
file_put_contents($debug, $prefix."START worker.php\n", FILE_APPEND);

// 2) Determine jobId
if (php_sapi_name() === 'cli') {
    $jobId = $argv[1] ?? exit(file_put_contents($debug, $prefix."ERROR: Missing jobId in CLI\n", FILE_APPEND));
} else {
    $jobId = $_GET['jobId'] ?? exit(file_put_contents($debug, $prefix."ERROR: Missing jobId in \$_GET\n", FILE_APPEND));
}
file_put_contents($debug, $prefix."Using jobId: $jobId\n", FILE_APPEND);

$jobDir      = __DIR__ . '/jobs';
$metaFile    = "$jobDir/{$jobId}.json";
$progressFile= "$jobDir/{$jobId}.progress";

// 3) Validate meta
if (!is_file($metaFile)) {
    file_put_contents($debug, $prefix."ERROR: Meta file not found: $metaFile\n", FILE_APPEND);
    exit;
}
file_put_contents($debug, $prefix."Found meta file\n", FILE_APPEND);

// 4) Load meta & files
$meta    = json_decode(file_get_contents($metaFile), true);
$files   = $meta['files']  ?? [];
$params  = $meta['params'] ?? [];
file_put_contents($debug, $prefix."Files to process: ".count($files)."\n", FILE_APPEND);

// 5) Build completed set
$completed = [];
if (is_file($progressFile)) {
    $lines = file($progressFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $m = json_decode($line, true);
        if (!empty($m['phase'], $m['status'], $m['filename'])
            && $m['phase']==='done' && $m['status']==='success'
        ) {
            $completed[$m['filename']] = true;
        }
    }
}
file_put_contents($debug, $prefix."Already completed: ".count($completed)."\n", FILE_APPEND);

// 6) Truncate progress
file_put_contents($debug, $prefix."Truncating progress file\n", FILE_APPEND);
file_put_contents($progressFile, '');

// 7) Loop
foreach ($files as $i => $f) {
    $name = $f['name'];
    file_put_contents($debug, $prefix."[$i] Processing: $name\n", FILE_APPEND);

    // Skip?
    if (isset($completed[$name])) {
        file_put_contents($debug, $prefix."[$i] Skipping (already done)\n", FILE_APPEND);
        progress('skip',$name,100,'skipped');
        continue;
    }

    // Download
    progress('download',$name,0);
    file_put_contents($debug, $prefix."[$i] Starting download\n", FILE_APPEND);
    $tmp = downloadDriveFile($f['id'],$name,$params['googleApiKey']);
    progress('download',$name,100);
    file_put_contents($debug, $prefix."[$i] Downloaded to $tmp\n", FILE_APPEND);

    // Upload
    progress('upload',$name,0);
    file_put_contents($debug, $prefix."[$i] Starting upload\n", FILE_APPEND);
    $vid = fbUploadVideo($tmp,$params['accessToken'],$params['accountId']);
    progress('upload',$name,100);
    file_put_contents($debug, $prefix."[$i] Uploaded, got video_id=$vid\n", FILE_APPEND);

    // Done
    progress('done',$name,100,'success',['video_id'=>$vid]);
    @unlink($tmp);
    file_put_contents($debug, $prefix."[$i] Done\n", FILE_APPEND);
}

// 8) Signal end
touch("$jobDir/$jobId.done");
file_put_contents($debug, $prefix."FINISHED\n\n", FILE_APPEND);

// ───────────────────────────────────────────────────────────
function progress(string $phase, string $file, int $pct, string $status='running', array $extra=[]): void
{
    global $progressFile;
    $data = array_merge([
        'phase'=>$phase,'filename'=>$file,'pct'=>$pct,'status'=>$status
    ], $extra);
    file_put_contents($progressFile, json_encode($data)."\n", FILE_APPEND);
}

function downloadDriveFile(string $id, string $name, string $apiKey): string
{
    $dest = sys_get_temp_dir().'/'.basename($name);

    $url1  = "https://www.googleapis.com/drive/v3/files/{$id}?alt=media&key={$apiKey}";
    $code1 = curlDownload($url1,$dest);
    if ($code1 < 400) return $dest;

    $url2  = "https://drive.google.com/uc?export=download&id={$id}";
    $code2 = curlDownload($url2,$dest);
    if ($code2 < 400) return $dest;

    throw new RuntimeException("Drive download failed (API {$code1}, uc {$code2})");
}

function curlDownload(string $url, string $outPath): int
{
    $ch = curl_init($url); $fp = fopen($outPath,'w');
    curl_setopt_array($ch,[
        CURLOPT_FILE=>$fp,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_TIMEOUT=>0,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch); fclose($fp);
    return $code;
}

function fbUploadVideo(string $path, string $token, string $account): string
{
    $endpoint = "https://graph-video.facebook.com/v19.0/{$account}/advideos";
    $ch = curl_init($endpoint);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POSTFIELDS=>[
            'access_token'=>$token,
            'source'=>new CURLFile($path),
        ],
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($res,true)?:[];
    if (empty($json['id'])) {
        throw new RuntimeException('FB upload error: '.($res?:'no response'));
    }
    return $json['id'];
}
