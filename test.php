<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Basic PHP test
echo "PHP is working!<br>";

try {
    require_once 'config.php';
    require_once 'VimeoService.php';
    require_once 'GeminiService.php';
    require_once 'WorkshopBot.php';

    // Test database connection
    echo "<h2>Testing Database Connection</h2>";
    if (isset($connect) && $connect) {
        echo "Database connection successful<br>";
    } else {
        echo "Database connection failed<br>";
        echo "Error: " . mysqli_connect_error() . "<br>";
    }

    // Create an instance of WorkshopBot
    $bot = new WorkshopBot();

    // Test workshop ID (replace with an actual workshop ID from your database)
    $workshopId = 182; // Change this to a valid workshop ID

    // Verify workshop exists
    echo "<h2>Verifying Workshop</h2>";
    $query = "SELECT id, name, rlink, trainer_id, trainer_name FROM workshops WHERE id = ? AND is_deleted = 0";
    $stmt = mysqli_prepare($connect, $query);
    mysqli_stmt_bind_param($stmt, "i", $workshopId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $workshop = mysqli_fetch_assoc($result);

    if ($workshop) {
        echo "Workshop found:<br>";
        echo "ID: " . htmlspecialchars($workshop['id']) . "<br>";
        echo "Name: " . htmlspecialchars($workshop['name']) . "<br>";
        echo "Video Link (raw): " . htmlspecialchars($workshop['rlink']) . "<br>";
        
        // Test Vimeo ID extraction
        $vimeoService = new VimeoService();
        $extractedId = $vimeoService->extractVideoId($workshop['rlink']);
        echo "Extracted Vimeo ID: " . ($extractedId ? $extractedId : 'Could not extract ID') . "<br>";
        
        echo "Trainer: " . htmlspecialchars($workshop['trainer_name']) . "<br>";
        
        if (empty($workshop['rlink'])) {
            echo "<strong>Warning: No video link found for this workshop!</strong><br>";
        }
    } else {
        echo "Workshop not found with ID: " . $workshopId . "<br>";
        echo "Please check if the workshop exists and is not deleted.<br>";
    }

    // Test processing a workshop
    echo "<h2>Processing Workshop</h2>";
    $processResult = $bot->processWorkshop($workshopId);
    echo "<pre>";
    print_r($processResult);
    echo "</pre>";

    // Test asking a question
    echo "<h2>Asking Question</h2>";
    $question = "What are the main topics covered in this workshop?";
    $answerResult = $bot->answerQuestion($workshopId, $question);
    echo "<pre>";
    print_r($answerResult);
    echo "</pre>";

} catch (Exception $e) {
    echo "<h2>Error Occurred</h2>";
    echo "Error: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
}
?> 