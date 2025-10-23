<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'db_init.php';

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$nickName = isset($input['nickName']) ? trim($input['nickName']) : '';

if (empty($nickName)) {
    echo json_encode(['success' => false, 'error' => 'نام نمایشی خالی است'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Check if AdminNickName exists
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM Config WHERE ConfigName = 'AdminNickName'");
    $stmt->execute();
    $row = $stmt->fetch();
    
    if ($row && intval($row['cnt']) > 0) {
        // Update existing
        $stmt = $pdo->prepare("UPDATE Config SET ConfigValue = ? WHERE ConfigName = 'AdminNickName'");
        $stmt->execute([$nickName]);
    } else {
        // Insert new
        $stmt = $pdo->prepare("INSERT INTO Config (ConfigName, ConfigValue) VALUES ('AdminNickName', ?)");
        $stmt->execute([$nickName]);
    }
    
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('Save AdminNickName error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'خطا در ذخیره'], JSON_UNESCAPED_UNICODE);
}
?>
