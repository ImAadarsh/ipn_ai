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

    /**
     * Generate a concise summary of a workshop transcript
     * 
     * @param string $transcript The full workshop transcript
     * @return string The generated summary
     */
    public function generateWorkshopSummary($transcript) {
        $prompt = "You are an educational content summarizer. Below is a transcript from an educational workshop with timestamps. 
Create a concise but comprehensive summary (300-500 words) of this workshop, highlighting:
1. The main topic and purpose of the workshop
2. Key concepts or methods discussed
3. Important takeaways for attendees
4. Any actionable advice or recommendations

Workshop Transcript:
$transcript

Your summary should be factual, informative, and capture the essence of the workshop content.";

        $data = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 800,
                'topK' => 40,
                'topP' => 0.95
            ]
        ];

        $response = $this->makeGeminiRequest('generateContent', $data);
        
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
        }
        
        return null;
    }

    /**
     * Create better semantic chunks from a transcript
     * 
     * @param string $transcript The full workshop transcript
     * @return array An array of optimized chunks
     */
    public function createSemanticChunks($transcript, $desiredChunkCount = 10) {
        $prompt = "You are an AI assistant specialized in analyzing educational content. I have a workshop transcript that needs to be divided into coherent semantic chunks for better processing.

Your task is to analyze this transcript and divide it into approximately $desiredChunkCount meaningful chunks based on topic shifts, logical breaks, or distinct sections. Each chunk should contain related content that makes sense together.

For each chunk, provide a brief descriptive title that captures its main topic.

Workshop Transcript:
$transcript

Format your response as a JSON array where each item has two fields:
1. 'title': A brief, descriptive title for the chunk
2. 'content': The full text content of that chunk

Do not include any explanation, just return the properly formatted JSON.";

        $data = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 8000,
                'topK' => 40,
                'topP' => 0.95
            ]
        ];

        $response = $this->makeGeminiRequest('generateContent', $data);
        
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            $jsonText = $response['candidates'][0]['content']['parts'][0]['text'];
            
            // Extract JSON from the response if needed
            if (preg_match('/```json(.*?)```/s', $jsonText, $matches)) {
                $jsonText = $matches[1];
            }
            
            // Clean the text and decode the JSON
            $jsonText = trim($jsonText);
            $chunks = json_decode($jsonText, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($chunks)) {
                return $chunks;
            }
        }
        
        // Fallback to simple chunking if smart chunking fails
        return null;
    }

    /**
     * Make a generic request to the Gemini API
     * 
     * @param string $endpoint The API endpoint to call
     * @param array $data The data to send in the request
     * @return array|null The API response as an array, or null on failure
     */
    private function makeGeminiRequest($endpoint, $data) {
        $url = "{$this->baseUrl}/models/gemini-1.5-flash:{$endpoint}?key={$this->apiKey}";
        
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
            error_log("Curl Error in {$endpoint}: " . curl_error($ch));
            return null;
        }
        
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            return $result;
        }

        error_log("Gemini API Error - HTTP Code: {$httpCode} for {$endpoint}");
        error_log("Response: " . $response);
        return null;
    }

    /**
     * Generate common questions that users might ask about a workshop
     * 
     * @param string $transcript The workshop transcript
     * @param string $summary The workshop summary
     * @return array Array of question objects with question text and type
     */
    public function generateWorkshopQuestions($transcript, $summary) {
        $prompt = "You are an AI designed to anticipate questions users might have about educational workshops. 
I'll provide you with a workshop transcript and summary. Your task is to generate 10-15 diverse and realistic questions that users might ask about this workshop.

Include a variety of question types:
1. Summary questions (e.g., 'What is this workshop about?', 'Can you summarize the main points?')
2. Content clarification (e.g., 'Could you explain what the speaker meant by X?')
3. Practical application (e.g., 'How can I implement X in my classroom?')
4. Missed content (e.g., 'I missed the first 10 minutes, what did I miss?')
5. Follow-up resources (e.g., 'Are there additional resources for learning more about X?')
6. Specific details (e.g., 'What was mentioned about X methodology?')
7. Speaker information (e.g., 'What are the speaker's credentials?')

Workshop Summary:
$summary

Workshop Transcript (excerpt):
" . substr($transcript, 0, 5000) . "

Format your response as a JSON array where each item has:
1. 'question': The full text of the question
2. 'type': The category/type of question (summary, content_clarification, practical_application, missed_content, resources, specific_detail, speaker_info)

Do not include any explanation, just return the properly formatted JSON array.";

        $data = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2000,
                'topK' => 40,
                'topP' => 0.95
            ]
        ];

        $response = $this->makeGeminiRequest('generateContent', $data);
        
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            $jsonText = $response['candidates'][0]['content']['parts'][0]['text'];
            
            // Extract JSON from the response if needed
            if (preg_match('/```json(.*?)```/s', $jsonText, $matches)) {
                $jsonText = $matches[1];
            }
            
            // Clean the text and decode the JSON
            $jsonText = trim($jsonText);
            $questions = json_decode($jsonText, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($questions)) {
                return $questions;
            }
        }
        
        return null;
    }

    /**
     * Generate an answer to a specific question about a workshop
     * 
     * @param string $question The question to answer
     * @param string $transcript The workshop transcript
     * @param string $summary The workshop summary
     * @return string The generated answer
     */
    public function generateQuestionAnswer($question, $transcript, $summary) {
        $prompt = "You are an educational assistant that answers questions about workshops. 
I'll provide you with a workshop transcript, summary, and a specific question. 
Your task is to provide a clear, accurate, and helpful answer to the question based on the workshop content.

Workshop Summary:
$summary

Workshop Transcript:
" . substr($transcript, 0, 8000) . "

Question: $question

Your answer should be:
1. Directly relevant to the question
2. Based specifically on information from the workshop
3. Clear and concise (100-250 words)
4. Structured with proper paragraphs and formatting
5. Professional and educational in tone

If the question cannot be fully answered with the provided transcript, acknowledge this limitation but provide the most helpful response possible based on what's available.";

        $data = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.3,
                'maxOutputTokens' => 800,
                'topK' => 40,
                'topP' => 0.95
            ]
        ];

        $response = $this->makeGeminiRequest('generateContent', $data);
        
        if (isset($response['candidates'][0]['content']['parts'][0]['text'])) {
            return $response['candidates'][0]['content']['parts'][0]['text'];
        }
        
        return null;
    }
}
?> 