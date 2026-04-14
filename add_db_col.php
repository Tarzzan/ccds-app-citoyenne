<?php
require __DIR__ . '/admin/includes/bootstrap.php';

// Instance the protected database singleton just like the main application does
$db = Database::getInstance();

if (admin_db_has_table($db, 'services')) {
    if (!admin_db_has_column($db, 'services', 'theme_variant')) {
        try {
            $db->exec("ALTER TABLE services ADD COLUMN theme_variant VARCHAR(255) DEFAULT NULL AFTER description");
            echo "Success: Column theme_variant added to services table.\n";
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    } else {
        echo "Info: Column theme_variant already exists.\n";
    }
} else {
    echo "Error: Table services does not exist.\n";
}
