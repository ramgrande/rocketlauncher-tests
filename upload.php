<?php
declare(strict_types=1);

// ─── 1) Read JSON or Form-POST ───
$raw = file_get_contents('php://input');
$in  = json_decode($raw, true) ?: $_POST;

// Extract params
$folderId     = $in['folderId']     ?? '';
$googleApiKey = $in['googleApiKey'] ?? '';
$accessToken  = $in['accessToken']  ?? '';
$accountId    = $in['accountId']    ?? '';
$countOnly    = !empty($in['count']);

// ─── 2) Validate ───
if (!$folderId || !$googleApiKey || !$accessToken || !$accountId) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'missing_param']);
    exit;
}

// ─── 3) Count-only mode ───
if ($countOnly) {
    $count = 0;
    $pageToken = null;
    do {
        $url = "https://www.googleapis.com/drive/v3/files"
             . "?q='".urlencode($folderId)."' in parents"
             . "&fields=nextPageToken,files(id)"
             . "&key=".urlencode($googleApiKey);
        if ($pageToken) {
            $url .= "&pageToken=".urlencode($pageToken);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$accessToken}"
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
    echo json_encode(['count' => $count]);
    exit;
}

// ─── 4) Spawn the background worker ───
// Use OpenSSL fallback instead of random_bytes()
$jobId = bin2hex(openssl_random_pseudo_bytes(8));

$cmd = sprintf(
    'php %s/cli_upload.php %s > /dev/null 2>&1 &',
    __DIR__,
    escapeshellarg($jobId)
);
exec($cmd);

header('Content-Type: application/json');
echo json_encode(['jobId' => $jobId]);
exit;
