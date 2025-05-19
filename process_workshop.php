<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'WorkshopBot.php';

// Set headers for API response
header('Content-Type: application/json');

// Check if workshop ID is provided
if (!isset($_GET['workshop_id'])) {
    echo json_encode([
        'success' => false,
        'error' => 'Workshop ID is required'
    ]);
    exit;
}

$workshopId = (int)$_GET['workshop_id'];

// Initialize WorkshopBot
$bot = new WorkshopBot();

// Check if workshop exists and is not deleted
$query = "SELECT id, name, rlink FROM workshops WHERE id = ? AND is_deleted = 0";
$stmt = mysqli_prepare($GLOBALS['connect'], $query);
mysqli_stmt_bind_param($stmt, "i", $workshopId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Workshop not found or has been deleted'
    ]);
    exit;
}

$workshop = mysqli_fetch_assoc($result);

// Check if workshop is already processed
$query = "SELECT status, chunks_count FROM workshop_processing WHERE workshop_id = ?";
$stmt = mysqli_prepare($GLOBALS['connect'], $query);
mysqli_stmt_bind_param($stmt, "i", $workshopId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$processing = mysqli_fetch_assoc($result);

if ($processing && $processing['status'] === 'completed') {
    echo json_encode([
        'success' => true,
        'message' => 'Workshop is already processed',
        'workshop' => [
            'id' => $workshop['id'],
            'name' => $workshop['name'],
            'chunks_count' => $processing['chunks_count']
        ]
    ]);
    exit;
}

// Update processing status to 'processing'
$query = "INSERT INTO workshop_processing (workshop_id, status) 
          VALUES (?, 'processing') 
          ON DUPLICATE KEY UPDATE 
          status = 'processing',
          updated_at = CURRENT_TIMESTAMP";
$stmt = mysqli_prepare($GLOBALS['connect'], $query);
mysqli_stmt_bind_param($stmt, "i", $workshopId);
mysqli_stmt_execute($stmt);

try {
    // Process the workshop
    $result = $bot->processWorkshop($workshopId);
    
    if (isset($result['error'])) {
        // Update status to failed
        $query = "UPDATE workshop_processing 
                 SET status = 'failed', 
                     updated_at = CURRENT_TIMESTAMP 
                 WHERE workshop_id = ?";
        $stmt = mysqli_prepare($GLOBALS['connect'], $query);
        mysqli_stmt_bind_param($stmt, "i", $workshopId);
        mysqli_stmt_execute($stmt);
        
        echo json_encode([
            'success' => false,
            'error' => $result['error']
        ]);
        exit;
    }
    
    // Update status to completed
    $query = "UPDATE workshop_processing 
             SET status = 'completed', 
                 chunks_count = ?, 
                 last_processed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP 
             WHERE workshop_id = ?";
    $stmt = mysqli_prepare($GLOBALS['connect'], $query);
    mysqli_stmt_bind_param($stmt, "ii", $result['chunks_count'], $workshopId);
    mysqli_stmt_execute($stmt);
    
    echo json_encode([
        'success' => true,
        'message' => 'Workshop processed successfully',
        'workshop' => [
            'id' => $workshop['id'],
            'name' => $workshop['name'],
            'chunks_count' => $result['chunks_count']
        ]
    ]);
    
} catch (Exception $e) {
    // Update status to failed
    $query = "UPDATE workshop_processing 
             SET status = 'failed', 
                 updated_at = CURRENT_TIMESTAMP 
             WHERE workshop_id = ?";
    $stmt = mysqli_prepare($GLOBALS['connect'], $query);
    mysqli_stmt_bind_param($stmt, "i", $workshopId);
    mysqli_stmt_execute($stmt);
    
    echo json_encode([
        'success' => false,
        'error' => 'Error processing workshop: ' . $e->getMessage()
    ]);
} 