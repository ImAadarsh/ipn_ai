<?php
require_once 'config.php';

class VimeoService {
    private $accessToken;
    private $baseUrl = 'https://api.vimeo.com';

    public function __construct() {
        $this->accessToken = VIMEO_PERSONAL_TOKEN;
    }

    public function extractVideoId($videoLink) {
        // If it's already just the ID, return it
        if (is_numeric($videoLink)) {
            return $videoLink;
        }

        // If it's empty, return null
        if (empty($videoLink)) {
            return null;
        }

        // Try to extract ID from various Vimeo URL formats
        $patterns = [
            '/vimeo\.com\/(\d+)/',  // https://vimeo.com/123456789
            '/player\.vimeo\.com\/video\/(\d+)/',  // https://player.vimeo.com/video/123456789
            '/\/(\d+)$/',  // Just the ID at the end
            '/\?v=(\d+)/',  // ?v=123456789
            '/\&v=(\d+)/',  // &v=123456789
            '/video\/(\d+)/',  // video/123456789
            '/embed\/(\d+)/'  // embed/123456789
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $videoLink, $matches)) {
                return $matches[1];
            }
        }

        // If the input looks like a number but isn't numeric, try to clean it
        if (preg_match('/[0-9]+/', $videoLink, $matches)) {
            return $matches[0];
        }

        return null;
    }

    public function getVideoDetails($videoId) {
        // Extract the numeric ID from the video link if needed
        $videoId = $this->extractVideoId($videoId);
        
        if (!$videoId) {
            echo "Invalid video ID format<br>";
            return null;
        }

        echo "Using video ID: {$videoId}<br>";
        
        $url = "{$this->baseUrl}/videos/{$videoId}";
        $headers = [
            "Authorization: Bearer {$this->accessToken}",
            "Content-Type: application/json"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For debugging only

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            echo "Curl Error in getVideoDetails: " . curl_error($ch) . "<br>";
        }
        
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        
        echo "Video Details API Error - HTTP Code: {$httpCode}<br>";
        echo "Response: " . $response . "<br>";
        return null;
    }

    public function getVideoTranscript($videoId) {
        echo "Attempting to get transcript for video ID: {$videoId}<br>";
        
        // Extract the numeric ID from the video link if needed
        $videoId = $this->extractVideoId($videoId);
        
        if (!$videoId) {
            echo "Invalid video ID format<br>";
            return null;
        }
        
        // First, get video details to verify the video exists
        $videoDetails = $this->getVideoDetails($videoId);
        if (!$videoDetails) {
            echo "Could not get video details<br>";
            return null;
        }
        
        echo "Video found: " . ($videoDetails['name'] ?? 'Unknown') . "<br>";

        // Get available text tracks
        $url = "{$this->baseUrl}/videos/{$videoId}/texttracks";
        $headers = [
            "Authorization: Bearer {$this->accessToken}",
            "Content-Type: application/json"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            echo "Curl Error in getVideoTranscript: " . curl_error($ch) . "<br>";
        }
        
        curl_close($ch);

        echo "Transcript API Response Code: {$httpCode}<br>";
        echo "Transcript API Response: " . $response . "<br>";

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (!empty($data['data'])) {
                // Get the first available transcript
                $transcriptUri = $data['data'][0]['link'];
                echo "Found transcript URI: {$transcriptUri}<br>";
                
                // Download the transcript
                $transcript = $this->downloadTranscript($transcriptUri);
                if ($transcript) {
                    // Convert VTT to plain text
                    return $this->convertVttToText($transcript);
                }
            } else {
                echo "No transcript data found in response<br>";
            }
        }
        return null;
    }

    private function downloadTranscript($transcriptUri) {
        echo "Downloading transcript from: {$transcriptUri}<br>";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $transcriptUri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            echo "Curl Error in downloadTranscript: " . curl_error($ch) . "<br>";
        }
        
        curl_close($ch);

        echo "Transcript Download Response Code: {$httpCode}<br>";
        
        if ($httpCode === 200) {
            echo "Successfully downloaded transcript<br>";
            return $response;
        }
        
        echo "Failed to download transcript<br>";
        return null;
    }

    private function convertVttToText($vttContent) {
        // Remove WEBVTT header and any metadata
        $lines = explode("\n", $vttContent);
        $textLines = [];
        $inText = false;
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines and metadata
            if (empty($line) || $line === 'WEBVTT' || strpos($line, '-->') !== false) {
                continue;
            }
            
            // Skip lines that are just numbers (cue numbers)
            if (is_numeric($line)) {
                continue;
            }
            
            $textLines[] = $line;
        }
        
        return implode(' ', $textLines);
    }
}
?> 