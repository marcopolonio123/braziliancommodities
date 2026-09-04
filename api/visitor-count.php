<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$storageDir = dirname(__DIR__).'/private';
$storageFile = $storageDir.'/visitor-count.txt';
if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

$handle = fopen($storageFile, 'c+');
if (!$handle || !flock($handle, LOCK_EX)) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

rewind($handle);
$current = max(0, (int)trim((string)stream_get_contents($handle)));
$current++;
rewind($handle);
ftruncate($handle, 0);
fwrite($handle, (string)$current);
fflush($handle);

flock($handle, LOCK_UN);
fclose($handle);
echo json_encode(['ok' => true, 'count' => $current]);
