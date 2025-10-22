<?php
/**
 * Migration Script - Add UNIQUE constraint to ConfigName and remove Order field
 * 
 * این اسکریپت برای دیتابیس‌های موجود است
 * فقط یکبار اجرا شود
 */

require_once 'db_init.php';

try {
    echo "🔧 Starting migration...\n";
    
    // 1. بررسی و حذف رکورد Order
    $stmt = $pdo->prepare("DELETE FROM Config WHERE ConfigName = 'Order'");
    $stmt->execute();
    echo "✅ Removed 'Order' config (if existed)\n";
    
    // 2. بررسی وجود UNIQUE constraint
    $stmt = $pdo->query("SHOW CREATE TABLE Config");
    $result = $stmt->fetch();
    $createTable = $result['Create Table'];
    
    if (strpos($createTable, 'UNIQUE') === false) {
        echo "📝 Adding UNIQUE constraint to ConfigName...\n";
        
        // حذف رکوردهای تکراری (نگه داشتن اولین رکورد)
        $pdo->exec("
            DELETE t1 FROM Config t1
            INNER JOIN Config t2 
            WHERE t1.ID > t2.ID AND t1.ConfigName = t2.ConfigName
        ");
        echo "✅ Removed duplicate ConfigName entries\n";
        
        // اضافه کردن UNIQUE constraint
        $pdo->exec("ALTER TABLE Config ADD UNIQUE KEY unique_config_name (ConfigName)");
        echo "✅ Added UNIQUE constraint to ConfigName\n";
    } else {
        echo "✅ UNIQUE constraint already exists\n";
    }
    
    echo "🎉 Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
