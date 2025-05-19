<?php
require_once 'config.php';

class GeminiService {
    private $apiKey;
    private $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct() {
        $this->apiKey = GEMINI_API_KEY;
        if (empty($this->apiKey)) {
            error_log("Gemini API key is not configured");
        }
    }

    public function generateEmbedding($text) {
        if (empty($text)) {
            error_log("Empty text provided for embedding");
            return null;
        }

        $url = "{$this->baseUrl}/models/embedding-001:embedContent?key={$this->apiKey}";
        
        $data = [
            'model' => 'models/embedding-001',
            'content' => [
                'parts' => [
                    ['text' => $text]
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            error_log("Curl Error in generateEmbedding: " . curl_error($ch));
            return null;
        }
        
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['embedding']['values'])) {
                return $result['embedding']['values'];
            }
            error_log("Unexpected embedding response format: " . $response);
            return null;
        }

        error_log("Embedding API Error - HTTP Code: {$httpCode}");
        error_log("Response: " . $response);
        return null;
    }

    public function generateResponse($prompt) {
        if (empty($prompt)) {
            error_log("Empty prompt provided for response generation");
            return null;
        }

        $url = "{$this->baseUrl}/models/gemini-1.5-flash:generateContent?key={$this->apiKey}";
        
        $data = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 1024,
            ],
            'safetySettings' => [
                [
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ],
                [
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            error_log("Curl Error in generateResponse: " . curl_error($ch));
            return null;
        }
        
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            error_log("Gemini API Response: " . $response); // Debug log
            
            if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                return $result['candidates'][0]['content']['parts'][0]['text'];
            }
            error_log("Unexpected response format: " . $response);
            return null;
        }

        error_log("Generation API Error - HTTP Code: {$httpCode}");
        error_log("Response: " . $response);
        return null;
    }

    private function cosineSimilarity($vec1, $vec2) {
        if (empty($vec1) || empty($vec2) || count($vec1) !== count($vec2)) {
            error_log("Invalid vectors for cosine similarity calculation");
            return 0;
        }

        $dotProduct = 0;
        $norm1 = 0;
        $norm2 = 0;
        
        for ($i = 0; $i < count($vec1); $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
            $norm1 += $vec1[$i] * $vec1[$i];
            $norm2 += $vec2[$i] * $vec2[$i];
        }
        
        $norm1 = sqrt($norm1);
        $norm2 = sqrt($norm2);
        
        if ($norm1 == 0 || $norm2 == 0) {
            return 0;
        }
        
        return $dotProduct / ($norm1 * $norm2);
    }
}
?> 