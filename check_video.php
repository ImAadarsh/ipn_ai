<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'VimeoService.php';

$vimeoService = new VimeoService();
$videoId = '1072752559';

echo "<h2>Checking Video: {$videoId}</h2>";

// Try different Vimeo API endpoints
$endpoints = [
    "/videos/{$videoId}",
    "/me/videos/{$videoId}",
    "/users/me/videos/{$videoId}"
];

foreach ($endpoints as $endpoint) {
    $url = "https://api.vimeo.com" . $endpoint;
    $headers = [
        "Authorization: Bearer " . VIMEO_PERSONAL_TOKEN,
        "Content-Type: application/json"
    ];

    echo "<h3>Trying endpoint: {$endpoint}</h3>";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    echo "HTTP Code: {$httpCode}<br>";
    echo "Response: <pre>" . htmlspecialchars($response) . "</pre>";
    
    if (curl_errno($ch)) {
        echo "Curl Error: " . curl_error($ch) . "<br>";
    }
    
    curl_close($ch);
    echo "<hr>";
}

// Check if the video exists in the database with a different ID
echo "<h2>Checking Database for Similar Videos</h2>";
$query = "SELECT id, name, rlink, video_link FROM workshops WHERE rlink LIKE ? OR video_link LIKE ? LIMIT 5";
$searchPattern = "%{$videoId}%";
$stmt = mysqli_prepare($connect, $query);
mysqli_stmt_bind_param($stmt, "ss", $searchPattern, $searchPattern);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

echo "Found " . mysqli_num_rows($result) . " similar videos in database:<br>";
while ($row = mysqli_fetch_assoc($result)) {
    echo "ID: " . htmlspecialchars($row['id']) . "<br>";
    echo "Name: " . htmlspecialchars($row['name']) . "<br>";
    echo "RLink: " . htmlspecialchars($row['rlink']) . "<br>";
    echo "Video Link: " . htmlspecialchars($row['video_link']) . "<br>";
    echo "<hr>";
}

// Check Vimeo API token permissions
echo "<h2>Checking Vimeo API Token Permissions</h2>";
$url = "https://api.vimeo.com/me";
$headers = [
    "Authorization: Bearer " . VIMEO_PERSONAL_TOKEN,
    "Content-Type: application/json"
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "HTTP Code: {$httpCode}<br>";
echo "Response: <pre>" . htmlspecialchars($response) . "</pre>";

if (curl_errno($ch)) {
    echo "Curl Error: " . curl_error($ch) . "<br>";
}

curl_close($ch);
?> 