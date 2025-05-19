<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'VimeoService.php';

$vimeoService = new VimeoService();

// Get workshop details
$workshopId = 182; // Change this to your workshop ID
$query = "SELECT id, name, rlink, video_link FROM workshops WHERE id = ? AND is_deleted = 0";
$stmt = mysqli_prepare($connect, $query);
mysqli_stmt_bind_param($stmt, "i", $workshopId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$workshop = mysqli_fetch_assoc($result);

if ($workshop) {
    echo "<h2>Workshop Details</h2>";
    echo "ID: " . htmlspecialchars($workshop['id']) . "<br>";
    echo "Name: " . htmlspecialchars($workshop['name']) . "<br>";
    echo "RLink: " . htmlspecialchars($workshop['rlink']) . "<br>";
    echo "Video Link: " . htmlspecialchars($workshop['video_link']) . "<br>";

    echo "<h2>Testing Video ID Extraction</h2>";
    
    // Test RLink
    if (!empty($workshop['rlink'])) {
        echo "<h3>Testing RLink</h3>";
        $id = $vimeoService->extractVideoId($workshop['rlink']);
        echo "Extracted ID: " . ($id ? $id : 'Could not extract ID') . "<br>";
        if ($id) {
            $details = $vimeoService->getVideoDetails($id);
            echo "Video Details: <pre>" . print_r($details, true) . "</pre>";
        }
    }

    // Test Video Link
    if (!empty($workshop['video_link'])) {
        echo "<h3>Testing Video Link</h3>";
        $id = $vimeoService->extractVideoId($workshop['video_link']);
        echo "Extracted ID: " . ($id ? $id : 'Could not extract ID') . "<br>";
        if ($id) {
            $details = $vimeoService->getVideoDetails($id);
            echo "Video Details: <pre>" . print_r($details, true) . "</pre>";
        }
    }
} else {
    echo "Workshop not found";
}
?> 