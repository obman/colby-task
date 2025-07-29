<?php

include_once 'constants.php';
include_once 'MediaType.php';

function handleError(string $msg, bool $throwException = true) {
    echo $msg;
    error_log($msg);
    if ($throwException) throw new RuntimeException($msg);
    else return;
}

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

$productTitle = $incomingApiData['data']['product']['title'];
if (empty($productTitle)) {
    $msg = 'Product data is empty or missing.';
    handleError($msg);
}
$productStmt = $conn->prepare("SELECT id FROM " . DBPRODUCTSTABLE . " WHERE title = ? LIMIT 1");
$productStmt->execute([$productTitle]);
$product = $productStmt->fetch();
if (empty($product)) {
    $msg = 'No product with given title in database.';
    handleError($msg);
}
$productId = $product['id'];

$edges = $incomingApiData['data']['product']['media']['edges'] ?? null;
if (empty($edges)) {
    $msg = 'Api data is empty or missing.';
    handleError($msg);
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
        $videos[] = [$id, $productId, $mediaType, $src]; // Using flat array in order to spare loop later
    } else {
        $src = $node['image']['originalSrc'] ?? null;
        if (!$src) continue;
        $media[] = [$id, $productId, $mediaType, $src]; // Using flat array in order to spare loop later
    }
}

if (empty($media) && empty($videos)) {
    $msg = 'No data to process.';
    handleError($msg);
}

$deleteStms = $conn->prepare("DELETE FROM " . DBMEDIATABLE . " WHERE product_id = ?");
$query = $deleteStms->execute([$productId]);

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
$sql = "INSERT INTO " . DBMEDIATABLE . " (node_id, product_id, type, src, position) VALUES {$preparedValues}";
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
        handleError($msg);
    }
} catch (PDOException $e) {
    if ($transactionStarted) {
        $conn->rollBack();
    }
    $msg = '[DB ERROR]: ' . $e->getMessage();
    handleError($msg, false);
}
catch (RuntimeException $e) {
    $msg = '[APP ERROR]: ' . $e->getMessage();
    handleError($msg, false);
}
$conn = null;