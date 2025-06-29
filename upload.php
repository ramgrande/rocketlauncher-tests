<?php
declare(strict_types=1);

// Read JSON payload
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// Pull variables out
$folderId     = $input['folderId']     ?? '';
$accessToken  = $input['accessToken']  ?? '';
$accountId    = $input['accountId']    ?? '';
$googleApiKey = $input['googleApiKey'] ?? '';
$isCountOnly  = !empty($input['count']);

// If they just want a count, fetch and return it immediately:
if ($isCountOnly) {
    // Example: call Google Drive to list files in $folderId and count them.
    // You already have your Drive-listing code—just use it here and do:
    //   $files = listDriveFiles($folderId, $accessToken, $googleApiKey);
    //   echo json_encode(['count'=>count($files)]);
    //   exit;

    // For demo, let’s stub it out:
    $count = 0;
    $pageToken = null;
    do {
      $url = "https://www.googleapis.com/drive/v3/files"
           . "?q='".urlencode($folderId)."' in parents"
           . "&fields=nextPageToken,files(id)"
           . "&pageToken=".urlencode($pageToken);
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$accessToken}",
        "key: {$googleApiKey}"
      ]);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      $resp = json_decode(curl_exec($ch), true);
      curl_close($ch);

      if (isset($resp['files'])) {
        $count += count($resp['files']);
      }
      $pageToken = $resp['nextPageToken'] ?? null;
    } while ($pageToken);

    header('Content-Type: application/json');
    echo json_encode(['count'=>$count]);
    exit;
}

// --- Otherwise proceed to spawn your background job as before ---
$jobId = bin2hex(random_bytes(8));
$cmd = sprintf(
    'php %s/cli_upload.php %s > /dev/null 2>&1 &',
    __DIR__,
    escapeshellarg($jobId)
);
exec($cmd);

header('Content-Type: application/json');
echo json_encode(['jobId'=>$jobId]);
exit;
