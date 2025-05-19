<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'WorkshopBot.php';

$workshopId = 182; // Your workshop ID
$bot = new WorkshopBot();

echo "<h2>Testing Question Answering with Workshop Embeddings</h2>";
echo "Workshop ID: {$workshopId}<br><br>";

// Test questions about the workshop
$questions = [
    "What is the main topic of this workshop?",
    "Who is the trainer for this workshop?",
    "What is the purpose of the Holistic Progress Card?",
    "What are the key components of the assessment?",
    "How is the 360-degree assessment implemented?"
];

foreach ($questions as $question) {
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
}

// Display workshop chunks for verification
echo "<h3>Workshop Chunks in Database</h3>";
$query = "SELECT id, content, LENGTH(embedding) as embedding_length 
          FROM workshop_chunks 
          WHERE workshop_id = ? 
          ORDER BY id";
$stmt = mysqli_prepare($GLOBALS['connect'], $query);
mysqli_stmt_bind_param($stmt, "i", $workshopId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

echo "<div style='margin: 20px 0;'>";
echo "Number of chunks found: " . mysqli_num_rows($result) . "<br><br>";

while ($row = mysqli_fetch_assoc($result)) {
    echo "<div style='margin: 10px 0; padding: 10px; border: 1px solid #eee;'>";
    echo "<strong>Chunk ID:</strong> " . $row['id'] . "<br>";
    echo "<strong>Content Length:</strong> " . strlen($row['content']) . " characters<br>";
    echo "<strong>Embedding Length:</strong> " . $row['embedding_length'] . " bytes<br>";
    echo "<strong>Content Preview:</strong><br>";
    echo htmlspecialchars(substr($row['content'], 0, 200)) . "...<br>";
    echo "</div>";
}
echo "</div>";
?> 