<?php
/**
 * worker.php – the CLI-spawned worker that does all the heavy lifting
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','0');
ini_set('log_errors','1');
ini_set('error_log', __DIR__.'/php-error.log');

$jobId = $argv[1] ?? exit("Missing jobId\n");
$jobDir = __DIR__.'/jobs';

$metaFile = "$jobDir/$jobId.json";
if (!is_file($metaFile)) exit("No job $jobId\n");
$meta   = json_decode(file_get_contents($metaFile),true);
$files  = $meta['files']  ?? [];
$params = $meta['params'] ?? [];

$progressFile = "$jobDir/$jobId.progress";
file_put_contents($progressFile,''); // reset

foreach ($files as $f) {
    $name = $f['name'];
    try {
        progress('download',$name,0);
        $tmp = downloadDriveFile($f['id'],$name,$params['googleApiKey']);
        progress('download',$name,100);

        progress('upload',$name,0);
        $vid = fbUploadVideo($tmp,$params['accessToken'],$params['accountId']);
        progress('upload',$name,100);

        progress('done',$name,100,'success',['video_id'=>$vid]);
        @unlink($tmp);
    } catch (Throwable $e) {
        progress('done',$name,100,'error',['error'=>$e->getMessage()]);
    }
}

touch("$jobDir/$jobId.done");

// ─── Helpers ──────────────────────────

function progress($phase,$file,$pct,$status='running',$extra=[]){
    global $progressFile;
    $d=array_merge([
      'phase'=>$phase,'filename'=>$file,'pct'=>$pct,'status'=>$status
    ],$extra);
    file_put_contents($progressFile,json_encode($d)."\n",FILE_APPEND);
}

function downloadDriveFile($id,$name,$apiKey){
    $dest=sys_get_temp_dir().'/'.basename($name);
    $u1="https://www.googleapis.com/drive/v3/files/$id?alt=media&key=$apiKey";
    $c1=curlDownload($u1,$dest);
    if($c1<400) return $dest;
    $u2="https://drive.google.com/uc?export=download&id=$id";
    $c2=curlDownload($u2,$dest);
    if($c2<400) return $dest;
    throw new RuntimeException("Download failed ($c1,$c2)");
}

function curlDownload($url,$outPath){
    $ch=curl_init($url); $fp=fopen($outPath,'w');
    curl_setopt_array($ch,[
      CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>0
    ]);
    curl_exec($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch); fclose($fp);
    return $code;
}

function fbUploadVideo($path,$token,$account){
    $url="https://graph-video.facebook.com/v19.0/$account/advideos";
    $ch=curl_init($url);
    curl_setopt_array($ch,[
      CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_POSTFIELDS=>['access_token'=>$token,'source'=>new CURLFile($path)]
    ]);
    $res=curl_exec($ch); curl_close($ch);
    $j=json_decode($res,true)?:[];
    if(empty($j['id'])) throw new RuntimeException("FB upload error: $res");
    return $j['id'];
}
