<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// Function to validate Vimeo URL
function validateVimeoUrl($url) {
    $patterns = [
        '/vimeo\.com\/(\d+)/',
        '/player\.vimeo\.com\/video\/(\d+)/',
        '/\/(\d+)$/',
        '/\?v=(\d+)/',
        '/\&v=(\d+)/',
        '/video\/(\d+)/',
        '/embed\/(\d+)/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

// Get workshop details
$workshopId = 182;
$query = "SELECT id, name, rlink, video_link FROM workshops WHERE id = ?";
$stmt = mysqli_prepare($connect, $query);
mysqli_stmt_bind_param($stmt, "i", $workshopId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$workshop = mysqli_fetch_assoc($result);

if ($workshop) {
    echo "<h2>Current Workshop Details</h2>";
    echo "ID: " . htmlspecialchars($workshop['id']) . "<br>";
    echo "Name: " . htmlspecialchars($workshop['name']) . "<br>";
    echo "RLink: " . htmlspecialchars($workshop['rlink']) . "<br>";
    echo "Video Link: " . htmlspecialchars($workshop['video_link']) . "<br>";
    
    echo "<h2>Instructions</h2>";
    echo "1. Please provide the correct Vimeo video URL for this workshop.<br>";
    echo "2. The URL should be in one of these formats:<br>";
    echo "   - https://vimeo.com/123456789<br>";
    echo "   - https://player.vimeo.com/video/123456789<br>";
    echo "   - https://vimeo.com/embed/123456789<br>";
    echo "<br>";
    echo "3. You can find the video URL by:<br>";
    echo "   - Going to the video on Vimeo<br>";
    echo "   - Copying the URL from your browser<br>";
    echo "   - Or using the share button on the video<br>";
    
    echo "<h2>Update Form</h2>";
    echo "<form method='post'>";
    echo "<input type='hidden' name='workshop_id' value='" . htmlspecialchars($workshop['id']) . "'>";
    echo "New Vimeo URL: <input type='text' name='video_url' style='width: 400px;'><br><br>";
    echo "<input type='submit' name='update' value='Update Video URL'>";
    echo "</form>";
    
    // Handle form submission
    if (isset($_POST['update']) && isset($_POST['video_url'])) {
        $videoUrl = trim($_POST['video_url']);
        $videoId = validateVimeoUrl($videoUrl);
        
        if ($videoId) {
            // Update the database
            $updateQuery = "UPDATE workshops SET rlink = ? WHERE id = ?";
            $updateStmt = mysqli_prepare($connect, $updateQuery);
            mysqli_stmt_bind_param($updateStmt, "si", $videoId, $workshopId);
            
            if (mysqli_stmt_execute($updateStmt)) {
                echo "<div style='color: green; margin-top: 20px;'>";
                echo "Video URL updated successfully!<br>";
                echo "New Video ID: " . htmlspecialchars($videoId);
                echo "</div>";
            } else {
                echo "<div style='color: red; margin-top: 20px;'>";
                echo "Error updating video URL: " . mysqli_error($connect);
                echo "</div>";
            }
        } else {
            echo "<div style='color: red; margin-top: 20px;'>";
            echo "Invalid Vimeo URL. Please provide a valid Vimeo video URL.";
            echo "</div>";
        }
    }
} else {
    echo "Workshop not found";
}
?> 