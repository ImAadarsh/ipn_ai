<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'WorkshopBot.php';

$workshopId = 182;
$bot = new WorkshopBot();

echo "<h2>Testing Question Answering</h2>";
echo "Workshop ID: {$workshopId}<br><br>";

// First, check if we need to process the workshop
echo "<h3>Checking Workshop Status</h3>";
$result = $bot->processWorkshop($workshopId);
if ($result['success']) {
    echo "<div style='color: green;'>";
    echo "Status: " . htmlspecialchars($result['message']) . "<br>";
    if (isset($result['details']['chunk_count'])) {
        echo "Existing chunks: " . $result['details']['chunk_count'] . "<br>";
    }
    echo "</div>";
} else {
    echo "<div style='color: red;'>";
    echo "Error: " . htmlspecialchars($result['error']);
    echo "</div>";
}

// Ask the specific question
echo "<h3>Question About Vegetable Vendor</h3>";
$question = "explain the vegetable vendor explain in detail.";
echo "<div style='margin: 20px 0; padding: 10px; border: 1px solid #ccc;'>";
echo "<strong>Question:</strong> " . htmlspecialchars($question) . "<br><br>";

$answer = $bot->answerQuestion($workshopId, $question);

if (isset($answer['error'])) {
    echo "<div style='color: red;'>";
    echo "Error: " . htmlspecialchars($answer['error']);
    echo "</div>";
} else {
    echo "<div style='color: blue;'>";
    echo "<strong>Answer:</strong><br>";
    echo htmlspecialchars($answer['answer']);
    echo "</div>";
    
    echo "<div style='color: green; margin-top: 10px;'>";
    echo "<strong>Workshop Details:</strong><br>";
    echo "Name: " . htmlspecialchars($answer['workshop']['name']) . "<br>";
    echo "Trainer: " . htmlspecialchars($answer['workshop']['trainer']);
    echo "</div>";
}
echo "</div>";

// Display relevant chunks used for the answer
echo "<h3>Relevant Chunks Used</h3>";
$query = "SELECT id, content 
          FROM workshop_chunks 
          WHERE workshop_id = ? 
          ORDER BY id";
$stmt = mysqli_prepare($GLOBALS['connect'], $query);
mysqli_stmt_bind_param($stmt, "i", $workshopId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

echo "<div style='margin: 20px 0;'>";
while ($row = mysqli_fetch_assoc($result)) {
    if (stripos($row['content'], 'vendor') !== false || 
        stripos($row['content'], 'vegetable') !== false) {
        echo "<div style='margin: 10px 0; padding: 10px; border: 1px solid #eee;'>";
        echo "<strong>Chunk ID:</strong> " . $row['id'] . "<br>";
        echo "<strong>Content:</strong><br>";
        echo htmlspecialchars($row['content']);
        echo "</div>";
    }
}
echo "</div>";
?> 