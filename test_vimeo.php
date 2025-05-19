<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// Test Vimeo API token
$url = "https://api.vimeo.com/me";
$headers = [
    "Authorization: Bearer " . VIMEO_PERSONAL_TOKEN,
    "Content-Type: application/json"
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For debugging only

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

echo "<h2>Testing Vimeo API Token</h2>";
echo "HTTP Response Code: " . $httpCode . "<br>";
echo "Response: <pre>" . htmlspecialchars($response) . "</pre>";

if (curl_errno($ch)) {
    echo "Curl Error: " . curl_error($ch) . "<br>";
}

curl_close($ch);
?> 