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

// If they just want a count, list and return it immediately
if ($isCountOnly) {
    $count = 0;
    $pageToken = null;
    do {
        $url = "https://www.googleapis.com/drive/v3/files"
             . "?q='".urlencode($folderId)."' in parents"
             . "&fields=nextPageToken,files(id)"
             . ($pageToken ? "&pageToken=".urlencode($pageToken) : '');
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

// Otherwise fall through to your existing “spawn the worker” logic:
$jobId = bin2hex(random_bytes(8));
exec(sprintf(
    'php %s/cli_upload.php %s > /dev/null 2>&1 &',
    __DIR__,
    escapeshellarg($jobId)
));

header('Content-Type: application/json');
echo json_encode(['jobId'=>$jobId]);
exit;
