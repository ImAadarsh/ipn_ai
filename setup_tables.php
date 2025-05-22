<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';

function executeSQL($sql) {
    global $connect;
    $result = mysqli_query($connect, $sql);
    if (!$result) {
        echo "Error executing SQL: " . mysqli_error($connect) . "<br>";
        echo "SQL: " . $sql . "<br><br>";
        return false;
    }
    return true;
}

// Create workshop_chunks table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS `workshop_chunks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `workshop_id` INT NOT NULL,
    `content` TEXT NOT NULL,
    `embedding` JSON NOT NULL,
    `priority` TINYINT NOT NULL DEFAULT 2,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_workshop_id (workshop_id),
    INDEX idx_priority (priority)
)";
executeSQL($sql);

// Create workshop_processing table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS `workshop_processing` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `workshop_id` INT NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    `chunks_count` INT DEFAULT 0,
    `last_processed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_workshop` (workshop_id)
)";
executeSQL($sql);

// Create workshop_questions table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS `workshop_questions` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `workshop_id` INT(11) NOT NULL,
    `question` TEXT NOT NULL,
    `answer` TEXT NOT NULL,
    `question_type` VARCHAR(50) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `workshop_id` (`workshop_id`),
    KEY `question_type` (`question_type`)
)";
executeSQL($sql);

// Create conversation_history table if it doesn't exist
$sql = "CREATE TABLE IF NOT EXISTS `conversation_history` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `workshop_id` INT(11) NOT NULL,
    `user_id` INT(11) NOT NULL,
    `question` TEXT NOT NULL,
    `answer` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `workshop_id` (`workshop_id`),
    KEY `user_id` (`user_id`),
    KEY `created_at` (`created_at`)
)";
executeSQL($sql);

// Now check if tables exist and report
$tables = [
    'workshop_chunks',
    'workshop_processing', 
    'workshop_questions',
    'conversation_history'
];

echo "<h1>Database Table Setup</h1>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>Table Name</th><th>Status</th><th>Row Count</th></tr>";

foreach ($tables as $table) {
    $result = mysqli_query($connect, "SHOW TABLES LIKE '$table'");
    $exists = mysqli_num_rows($result) > 0;
    
    $status = $exists ? "EXISTS" : "MISSING";
    $rowCount = $exists ? mysqli_fetch_row(mysqli_query($connect, "SELECT COUNT(*) FROM $table"))[0] : "N/A";
    
    $statusColor = $exists ? "green" : "red";
    
    echo "<tr>";
    echo "<td>$table</td>";
    echo "<td style='color: $statusColor; font-weight: bold;'>$status</td>";
    echo "<td>$rowCount</td>";
    echo "</tr>";
}

echo "</table>";

echo "<h2>Workshop Chunks Breakdown</h2>";
$result = mysqli_query($connect, "SELECT workshop_id, COUNT(*) as count FROM workshop_chunks GROUP BY workshop_id");
if (mysqli_num_rows($result) > 0) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Workshop ID</th><th>Chunk Count</th></tr>";
    
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>{$row['workshop_id']}</td>";
        echo "<td>{$row['count']}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p>No workshop chunks found.</p>";
}

echo "<h2>Workshop Questions Breakdown</h2>";
$result = mysqli_query($connect, "SELECT workshop_id, COUNT(*) as count FROM workshop_questions GROUP BY workshop_id");
if (mysqli_num_rows($result) > 0) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Workshop ID</th><th>Question Count</th></tr>";
    
    while ($row = mysqli_fetch_assoc($result)) {
        echo "<tr>";
        echo "<td>{$row['workshop_id']}</td>";
        echo "<td>{$row['count']}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p>No workshop questions found.</p>";
}

echo "<p>Database setup complete!</p>";
?> 