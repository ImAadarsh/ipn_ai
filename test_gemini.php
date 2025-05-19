<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'GeminiService.php';

$gemini = new GeminiService();

echo "<h2>Testing Gemini API Integration</h2>";

// Test embedding generation
echo "<h3>Testing Embedding Generation</h3>";
$testText = "This is a test text for embedding generation.";
$embedding = $gemini->generateEmbedding($testText);

if ($embedding) {
    echo "<div style='color: green;'>";
    echo "Embedding generated successfully<br>";
    echo "Vector length: " . count($embedding) . "<br>";
    echo "First few values: " . implode(", ", array_slice($embedding, 0, 5)) . "...<br>";
    echo "</div>";
} else {
    echo "<div style='color: red;'>";
    echo "Failed to generate embedding<br>";
    echo "</div>";
}

// Test response generation with different prompts
echo "<h3>Testing Response Generation</h3>";
$testPrompts = [
    "What is the capital of France?",
    "Explain the concept of machine learning in one sentence.",
    "What is 2+2?"
];

foreach ($testPrompts as $prompt) {
    echo "<div style='margin: 20px 0; padding: 10px; border: 1px solid #ccc;'>";
    echo "<strong>Prompt:</strong> " . htmlspecialchars($prompt) . "<br><br>";
    
    $response = $gemini->generateResponse($prompt);
    
    if ($response) {
        echo "<div style='color: green;'>";
        echo "<strong>Response:</strong><br>";
        echo htmlspecialchars($response);
        echo "</div>";
    } else {
        echo "<div style='color: red;'>";
        echo "Failed to generate response<br>";
        echo "</div>";
    }
    echo "</div>";
}

// Check API key configuration
echo "<h3>API Key Configuration</h3>";
if (defined('GEMINI_API_KEY') && !empty(GEMINI_API_KEY)) {
    echo "<div style='color: green;'>";
    echo "API key is configured<br>";
    echo "Key length: " . strlen(GEMINI_API_KEY) . " characters<br>";
    echo "Key prefix: " . substr(GEMINI_API_KEY, 0, 5) . "...<br>";
    echo "</div>";
} else {
    echo "<div style='color: red;'>";
    echo "API key is not configured<br>";
    echo "</div>";
}

// Test API endpoint
echo "<h3>Testing API Endpoint</h3>";
$testUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . GEMINI_API_KEY;
$ch = curl_init($testUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<div style='margin: 20px 0;'>";
echo "API Endpoint Test:<br>";
echo "HTTP Code: " . $httpCode . "<br>";
echo "Response Headers: " . htmlspecialchars($response) . "<br>";
echo "</div>";

// Test a simple POST request
echo "<h3>Testing Simple POST Request</h3>";
$testUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . GEMINI_API_KEY;
$data = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => 'Hello, how are you?']
            ]
        ]
    ]
];

$ch = curl_init($testUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<div style='margin: 20px 0;'>";
echo "Simple POST Test:<br>";
echo "HTTP Code: " . $httpCode . "<br>";
echo "Response: " . htmlspecialchars($response) . "<br>";
echo "</div>";
?> 