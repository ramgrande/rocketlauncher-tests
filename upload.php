--- upload.php
+++ upload.php
@@ -1,18 +1,28 @@
 <?php
+declare(strict_types=1);

 // (1) … your existing includes, auth checks, parameter parsing, etc.
 
 // e.g. you might have:
 // $token = $_POST['accessToken'] ?? '';
 // $files = $_FILES['videos'];
 
-// (2) Heavy lifting: looping over $files, downloading from Drive, pushing to FB…
-// foreach ($files as $f) {
-//    … curl–download …
-//    … curl–upload …
-//    echo json for each row …
-// }
+// (2) Instead of doing all that here, we fire off a background job:
+
+// generate a unique job ID (will also be used for progress tracking)
+$jobId = bin2hex(random_bytes(8));
+
+// launch the worker in the background; it’ll pick up the same $_POST/$_FILES
+$cmd = sprintf(
+    'php %s/cli_upload.php %s > /dev/null 2>&1 &',
+    __DIR__,
+    escapeshellarg($jobId)
+);
+exec($cmd);
+
+// immediately return the jobId for front-end polling
+header('Content-Type: application/json');
+echo json_encode(['jobId' => $jobId]);
+exit;
