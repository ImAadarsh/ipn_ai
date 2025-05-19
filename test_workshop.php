<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'WorkshopBot.php';

$workshopId = 182;
$bot = new WorkshopBot();

echo "<h2>Testing Workshop Processing</h2>";
echo "Workshop ID: {$workshopId}<br><br>";

// Process workshop
echo "<h3>Processing Workshop</h3>";
$result = $bot->processWorkshop($workshopId);

if ($result['success']) {
    echo "<div style='color: green;'>";
    echo "Success: " . htmlspecialchars($result['message']) . "<br>";
    echo "Details:<br>";
    echo "- Workshop ID: " . htmlspecialchars($result['details']['workshop_id']) . "<br>";
    echo "- Workshop Name: " . htmlspecialchars($result['details']['workshop_name']) . "<br>";
    echo "- Video ID: " . htmlspecialchars($result['details']['video_id']) . "<br>";
    echo "- Transcript Length: " . htmlspecialchars($result['details']['transcript_length']) . " characters<br>";
    echo "</div>";

    // Test question answering
    echo "<h3>Testing Question Answering</h3>";
    $questions = [
        "What is the main topic of this workshop?",
        "Who is the trainer for this workshop?",
        "What is the purpose of the Holistic Progress Card?"
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
        }
        echo "</div>";
    }
} else {
    echo "<div style='color: red;'>";
    echo "Error: " . htmlspecialchars($result['error']);
    echo "</div>";
}
?> 