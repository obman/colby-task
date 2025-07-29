<?php

include_once 'constants.php';
include_once 'MediaType.php';

$dsn     = 'mysql:host=' . DBHOST . ';dbname=' . DBNAME . ';charset=' . DBCHARSET;
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
    $conn = new PDO($dsn, DBUSERNAME, DBPASSWORD, $options);
} catch (PDOException $e) {
    throw new PDOException($e->getMessage(), (int) $e->getCode());
}

$json = file_get_contents('data.json');
$incomingApiData = json_decode($json, true);
$edges = $incomingApiData['data']['product']['media']['edges'] ?? null;
if (empty($edges)) {
    $msg = 'Api data is empty or missing.';
    echo $msg;
    error_log($msg);
    throw new RuntimeException($msg);
}

$media = [];
$videos = [];
foreach ($edges as $edge) {
    if (empty($edge['node'])) {
        continue;
    }

    $node = $edge['node'];
    if (empty($node['id']) || empty($node['mediaContentType'])) {
        continue;
    }
    $id = $node['id'];
    $mediaType = $node['mediaContentType'];

    if ($mediaType === MediaType::EXTERNAL_VIDEO->value) {
        $src = $node['embeddedUrl'] ?? null;
        if (!$src) continue;
        $videos[] = [$id, $mediaType, $src]; // Using flat array in order to spare loop later
    } else {
        $src = $node['image']['originalSrc'] ?? null;
        if (!$src) continue;
        $media[] = [$id, $mediaType, $src]; // Using flat array in order to spare loop later
    }
}

if (empty($media) && empty($videos)) {
    $msg = 'No data to process.';
    echo $msg;
    error_log($msg);
    throw new RuntimeException($msg);
}

if (empty($media)) {
    $media = $videos;
}
if (!empty($media) && !empty($videos)) {
    array_splice($media, 1, 0, $videos);
}
foreach ($media as $index => $item) {
    $media[$index][] = $index;
}
$data = array_merge(...$media);

// Insert in bulk
$values = str_repeat('?,', count($media[0]) - 1) . '?';
$preparedValues = implode(',', array_fill(0, count($media), "($values)"));
$sql = "INSERT INTO " . DBMEDIATABLE . " (node_id, type, src, position) VALUES {$preparedValues} " .
    "ON DUPLICATE KEY UPDATE " .
    "type=VALUES(type), " .
    "src=VALUES(src), " .
    "position=VALUES(position)";
$transactionStarted = false;
try {
    $stmt = $conn->prepare($sql);
    $transactionStarted = $conn->beginTransaction();
    $query = $stmt->execute($data);
    if ($query) {
        $conn->commit();
        echo 'Inserted ' . count($media) . ' rows into ' . DBMEDIATABLE . ' table.';
    } else {
        $conn->rollBack();
        $msg = 'Insert failed: could not insert data into ' . DBMEDIATABLE . '.';
        error_log($msg);
        throw new RuntimeException($msg);
    }
} catch (PDOException $e) {
    if ($transactionStarted) {
        $conn->rollBack();
    }
    $msg = '[DB ERROR]: ' . $e->getMessage();
    echo $msg;
    error_log($msg);
}
catch (RuntimeException $e) {
    $msg = '[APP ERROR]: ' . $e->getMessage();
    echo $msg;
    error_log($msg);
}
$conn = null;