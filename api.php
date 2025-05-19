<?php
require_once 'WorkshopBot.php';

header('Content-Type: application/json');

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$bot = new WorkshopBot();

// Get the request data
$data = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'process':
            if (!isset($data['workshop_id'])) {
                echo json_encode(['error' => 'Workshop ID is required']);
                exit;
            }
            $result = $bot->processWorkshop($data['workshop_id']);
            echo json_encode($result);
            break;
            
        case 'ask':
            if (!isset($data['workshop_id']) || !isset($data['question'])) {
                echo json_encode(['error' => 'Workshop ID and question are required']);
                exit;
            }
            $result = $bot->answerQuestion($data['workshop_id'], $data['question']);
            echo json_encode($result);
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} else {
    echo json_encode(['error' => 'Method not allowed']);
}
?> 