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
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

$bot = new WorkshopBot();

// Get suggested questions
$questions = $bot->getSuggestedQuestions($workshopId, $limit);

// Return the result
echo json_encode([
    'success' => true,
    'workshop_id' => $workshopId,
    'questions' => $questions
]);
?> 