<?php
/**
 * TEMPORARY ADMIN SETUP SCRIPT
 * Run this once in your browser to set valid password hashes, then DELETE IT.
 */
require_once __DIR__ . '/../config/database.php';

// The plaintext credentials you want to use
$plain_password = 'Admin@123';
$plain_pin = '1234';
$email = 'admin@crm.local';

// Generate valid bcrypt hashes
$password_hash = password_hash($plain_password, PASSWORD_DEFAULT);
$pin_hash = password_hash($plain_pin, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("UPDATE users SET password = ?, pin_hash = ? WHERE email = ?");
    $stmt->execute([$password_hash, $pin_hash, $email]);
    
    echo "<h3>Success!</h3>";
    echo "<p>Super Admin credentials updated.</p>";
    echo "<ul>";
    echo "<li><strong>Email:</strong> " . htmlspecialchars($email) . "</li>";
    echo "<li><strong>Password:</strong> " . htmlspecialchars($plain_password) . "</li>";
    echo "<li><strong>PIN:</strong> " . htmlspecialchars($plain_pin) . "</li>";
    echo "</ul>";
    echo "<p style='color:red; font-weight:bold;'>CRITICAL: Delete this reset-admin.php file immediately to secure your system!</p>";
    echo "<a href='/public/login.php'>Go to Login</a>";
    
} catch (PDOException $e) {
    echo "Error updating database: " . $e->getMessage();
}
?>