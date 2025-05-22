<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'WorkshopBot.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user'])) {
    echo json_encode(['error' => 'User not logged in']);
    exit;
}

// Check if workshop ID is provided
if (!isset($_GET['workshop_id'])) {
    echo json_encode(['error' => 'Workshop ID is required']);
    exit;
}

$workshopId = (int)$_GET['workshop_id'];
$questionType = isset($_GET['type']) ? $_GET['type'] : null;

$bot = new WorkshopBot();

// Get workshop questions
$questions = $bot->getWorkshopQuestions($workshopId, $questionType);

// Group questions by type for better organization
$groupedQuestions = [];
foreach ($questions as $question) {
    $type = $question['question_type'];
    if (!isset($groupedQuestions[$type])) {
        $groupedQuestions[$type] = [];
    }
    $groupedQuestions[$type][] = [
        'question' => $question['question'],
        'answer' => $question['answer']
    ];
}

// Return the result
echo json_encode([
    'success' => true,
    'workshop_id' => $workshopId,
    'question_count' => count($questions),
    'questions_by_type' => $groupedQuestions
]);
?> 