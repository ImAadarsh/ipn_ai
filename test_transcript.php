<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'VimeoService.php';

$vimeoService = new VimeoService();
$videoId = '1072752559';

echo "<h2>Testing Video Transcript Retrieval</h2>";
echo "Video ID: {$videoId}<br><br>";

// Get video details
echo "<h3>Video Details</h3>";
$details = $vimeoService->getVideoDetails($videoId);
if ($details) {
    echo "Name: " . htmlspecialchars($details['name']) . "<br>";
    echo "Duration: " . htmlspecialchars($details['duration']) . " seconds<br>";
    echo "Link: " . htmlspecialchars($details['link']) . "<br><br>";
}

// Get transcript
echo "<h3>Transcript</h3>";
$transcript = $vimeoService->getVideoTranscript($videoId);
if ($transcript) {
    echo "<pre>" . htmlspecialchars($transcript) . "</pre>";
} else {
    echo "Could not retrieve transcript";
}
?> 