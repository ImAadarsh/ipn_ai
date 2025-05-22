<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';
require_once 'VimeoService.php';
require_once 'GeminiService.php';

class WorkshopBot {
    private $vimeoService;
    private $geminiService;
    private $connect;

    public function __construct() {
        $this->vimeoService = new VimeoService();
        $this->geminiService = new GeminiService();
        $this->connect = $GLOBALS['connect'];
    }

    public function processWorkshop($workshopId) {
        // Get workshop details
        $workshop = $this->getWorkshopDetails($workshopId);
        if (!$workshop) {
            return ['error' => 'Workshop not found'];
        }

        // Check if chunks already exist
        $query = "SELECT COUNT(*) as chunk_count FROM workshop_chunks WHERE workshop_id = ?";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "i", $workshopId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);

        if ($row['chunk_count'] > 0) {
            return [
                'success' => true,
                'message' => 'Workshop chunks already exist',
                'details' => [
                    'workshop_id' => $workshopId,
                    'workshop_name' => $workshop['name'],
                    'chunk_count' => $row['chunk_count']
                ],
                'chunks_count' => $row['chunk_count']
            ];
        }

        // Try both video_link and rlink
        $videoId = null;
        if (!empty($workshop['video_link'])) {
            $videoId = $this->vimeoService->extractVideoId($workshop['video_link']);
        }
        if (!$videoId && !empty($workshop['rlink'])) {
            $videoId = $this->vimeoService->extractVideoId($workshop['rlink']);
        }

        if (!$videoId) {
            return ['error' => 'No valid video ID found in workshop data'];
        }

        // Get video transcript
        $transcript = $this->vimeoService->getVideoTranscript($videoId);
        if (!$transcript) {
            return ['error' => 'Could not retrieve video transcript'];
        }
        echo "Retrieved transcript of length: " . strlen($transcript) . " characters<br>";

        // Store transcript chunks with embeddings
        $this->storeTranscriptChunks($workshopId, $transcript);
        
        // Get the final chunk count after processing
        $query = "SELECT COUNT(*) as chunk_count FROM workshop_chunks WHERE workshop_id = ?";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "i", $workshopId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        $chunkCount = $row['chunk_count'];

        return [
            'success' => true, 
            'message' => 'Workshop processed successfully',
            'details' => [
                'workshop_id' => $workshopId,
                'workshop_name' => $workshop['name'],
                'rlink' => $videoId,
                'transcript_length' => strlen($transcript),
                'chunk_count' => $chunkCount
            ],
            'chunks_count' => $chunkCount
        ];
    }

    private function getWorkshopDetails($workshopId) {
        $query = "SELECT w.*, t.name as trainer_name, t.about as trainer_description 
                 FROM workshops w 
                 LEFT JOIN trainers t ON w.trainer_id = t.id 
                 WHERE w.id = ? AND w.is_deleted = 0";
        
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "i", $workshopId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        return mysqli_fetch_assoc($result);
    }

    private function storeTranscriptChunks($workshopId, $transcript) {
        // Clear existing chunks for this workshop
        $this->clearExistingChunks($workshopId);
        
        // First, generate a summary of the workshop
        $summary = $this->geminiService->generateWorkshopSummary($transcript);
        
        // Store the summary as the first chunk with a special tag
        if ($summary) {
            $summaryWithTag = "[WORKSHOP_SUMMARY]\n" . $summary;
            $summaryEmbedding = $this->geminiService->generateEmbedding($summaryWithTag);
            if ($summaryEmbedding) {
                $this->storeChunk($workshopId, $summaryWithTag, json_encode($summaryEmbedding), 1); // Priority 1 for summary
            }
            
            // Generate and store common questions about the workshop
            $this->generateAndStoreWorkshopQuestions($workshopId, $transcript, $summary);
        }
        
        // Try to create semantic chunks
        $semanticChunks = $this->geminiService->createSemanticChunks($transcript);
        
        if ($semanticChunks && is_array($semanticChunks)) {
            // We have semantic chunks with titles
            foreach ($semanticChunks as $index => $chunk) {
                $chunkTitle = isset($chunk['title']) ? $chunk['title'] : "Chunk " . ($index + 1);
                $chunkContent = isset($chunk['content']) ? $chunk['content'] : $chunk;
                
                // Add the title as a header
                $formattedChunk = "[SECTION: {$chunkTitle}]\n" . $chunkContent;
                
                // Generate embedding for the chunk
                $embedding = $this->geminiService->generateEmbedding($formattedChunk);
                if ($embedding) {
                    // Store chunk and embedding with priority 2 (after summary)
                    $this->storeChunk($workshopId, $formattedChunk, json_encode($embedding), 2);
                }
            }
        } else {
            // Fall back to the original word-count based chunking method
            $chunks = $this->splitIntoChunks($transcript, 500);
            
            foreach ($chunks as $index => $chunk) {
            // Generate embedding for the chunk
            $embedding = $this->geminiService->generateEmbedding($chunk);
            if ($embedding) {
                    // Store chunk and embedding with standard priority (2)
                    $this->storeChunk($workshopId, $chunk, json_encode($embedding), 2);
                }
            }
        }
    }

    /**
     * Generate and store common questions users might ask about a workshop
     * 
     * @param int $workshopId The workshop ID
     * @param string $transcript The workshop transcript
     * @param string $summary The workshop summary
     */
    private function generateAndStoreWorkshopQuestions($workshopId, $transcript, $summary) {
        // Clear any existing questions for this workshop
        $this->clearExistingWorkshopQuestions($workshopId);
        
        // Generate potential questions
        $questions = $this->geminiService->generateWorkshopQuestions($transcript, $summary);
        
        if (!$questions || !is_array($questions)) {
            error_log("Failed to generate workshop questions for workshop: {$workshopId}");
            return;
        }
        
        // Generate answers and store each question
        foreach ($questions as $questionData) {
            if (isset($questionData['question']) && isset($questionData['type'])) {
                $question = $questionData['question'];
                $type = $questionData['type'];
                
                // Generate answer to this question
                $answer = $this->geminiService->generateQuestionAnswer($question, $transcript, $summary);
                
                if ($answer) {
                    // Store the question and answer
                    $this->storeWorkshopQuestion($workshopId, $question, $answer, $type);
                }
            }
        }
    }
    
    /**
     * Store a workshop question with its answer
     * 
     * @param int $workshopId The workshop ID
     * @param string $question The question text
     * @param string $answer The answer text
     * @param string $type The question type/category
     */
    private function storeWorkshopQuestion($workshopId, $question, $answer, $type) {
        $query = "INSERT INTO workshop_questions (workshop_id, question, answer, question_type) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "isss", $workshopId, $question, $answer, $type);
        mysqli_stmt_execute($stmt);
    }
    
    /**
     * Clear existing workshop questions
     * 
     * @param int $workshopId The workshop ID
     */
    private function clearExistingWorkshopQuestions($workshopId) {
        $query = "DELETE FROM workshop_questions WHERE workshop_id = ?";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "i", $workshopId);
        mysqli_stmt_execute($stmt);
    }
    
    /**
     * Get common questions for a workshop
     * 
     * @param int $workshopId The workshop ID
     * @param string $type Optional question type to filter by
     * @return array Array of question and answer pairs
     */
    public function getWorkshopQuestions($workshopId, $type = null) {
        if ($type) {
            $query = "SELECT question, answer, question_type FROM workshop_questions 
                      WHERE workshop_id = ? AND question_type = ? 
                      ORDER BY id ASC";
            $stmt = mysqli_prepare($this->connect, $query);
            mysqli_stmt_bind_param($stmt, "is", $workshopId, $type);
        } else {
            $query = "SELECT question, answer, question_type FROM workshop_questions 
                      WHERE workshop_id = ? 
                      ORDER BY question_type, id ASC";
            $stmt = mysqli_prepare($this->connect, $query);
            mysqli_stmt_bind_param($stmt, "i", $workshopId);
        }
        
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $questions = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $questions[] = $row;
        }
        
        return $questions;
    }

    private function clearExistingChunks($workshopId) {
        $query = "DELETE FROM workshop_chunks WHERE workshop_id = ?";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "i", $workshopId);
        mysqli_stmt_execute($stmt);
    }

    private function splitIntoChunks($text, $wordsPerChunk) {
        $words = str_word_count($text, 1);
        $chunks = [];
        $currentChunk = [];
        $wordCount = 0;

        foreach ($words as $word) {
            $currentChunk[] = $word;
            $wordCount++;

            if ($wordCount >= $wordsPerChunk) {
                $chunks[] = implode(' ', $currentChunk);
                $currentChunk = [];
                $wordCount = 0;
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = implode(' ', $currentChunk);
        }

        return $chunks;
    }

    private function storeChunk($workshopId, $chunk, $embedding, $priority = 2) {
        $query = "INSERT INTO workshop_chunks (workshop_id, content, embedding, priority) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "issi", $workshopId, $chunk, $embedding, $priority);
        mysqli_stmt_execute($stmt);
    }

    public function answerQuestion($workshopId, $question, $userId = null) {
        // Get workshop details
        $workshop = $this->getWorkshopDetails($workshopId);
        if (!$workshop) {
            error_log("Workshop not found: {$workshopId}");
            return ['error' => 'Workshop not found'];
        }

        // Generate embedding for the question
        $questionEmbedding = $this->geminiService->generateEmbedding($question);
        if (!$questionEmbedding) {
            error_log("Failed to generate embedding for question: {$question}");
            return ['error' => 'Failed to process question'];
        }

        // Find most relevant chunks
        $relevantChunks = $this->findRelevantChunks($workshopId, $questionEmbedding);
        if (empty($relevantChunks)) {
            error_log("No relevant chunks found for workshop: {$workshopId}");
            return ['error' => 'No relevant content found'];
        }

        // Get conversation history if userId is provided
        $conversationHistory = [];
        if ($userId) {
            $conversationHistory = $this->getConversationHistory($workshopId, $userId);
        }

        // Extract workshop summary if available
        $workshopSummary = '';
        foreach ($relevantChunks as $chunk) {
            if (strpos($chunk, '[WORKSHOP_SUMMARY]') === 0) {
                $workshopSummary = str_replace('[WORKSHOP_SUMMARY]', '', $chunk);
                break;
            }
        }

        // Construct prompt with conversation history
        $prompt = $this->constructPrompt($workshop, $relevantChunks, $question, $conversationHistory);
        if (empty($prompt)) {
            error_log("Failed to construct prompt for question: {$question}");
            return ['error' => 'Failed to process question'];
        }

        // Generate response
        $response = $this->geminiService->generateResponse($prompt);
        if (empty($response)) {
            error_log("Failed to generate response for question: {$question}");
            return ['error' => 'Failed to generate answer'];
        }

        // Store the conversation if userId is provided
        if ($userId) {
            $this->storeConversation($workshopId, $userId, $question, $response);
        }

        // Generate follow-up questions
        $followUpQuestions = $this->geminiService->generateFollowUpQuestions($question, $response, $conversationHistory, $workshopSummary);

        return [
            'answer' => $response,
            'workshop' => [
                'name' => $workshop['name'],
                'trainer' => $workshop['trainer_name']
            ],
            'follow_up_questions' => $followUpQuestions
        ];
    }

    private function findRelevantChunks($workshopId, $questionEmbedding, $limit = 3) {
        // Get all chunks for the workshop
        $query = "SELECT content, embedding, priority FROM workshop_chunks WHERE workshop_id = ? ORDER BY priority ASC";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "i", $workshopId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $chunks = [];
        $similarities = [];
        $priorities = [];
        
        // First, check if we have a summary and always include it
        $summaryFound = false;
        
        while ($row = mysqli_fetch_assoc($result)) {
            $chunkEmbedding = json_decode($row['embedding'], true);
            $priority = $row['priority'];
            
            // Always include summary
            if (strpos($row['content'], '[WORKSHOP_SUMMARY]') === 0) {
                $chunks[] = $row['content'];
                $similarities[] = 2.0; // Ensure it's at the top
                $priorities[] = 0;     // Override priority to be first
                $summaryFound = true;
                continue;
            }
            
            if ($chunkEmbedding) {
                $similarity = $this->cosineSimilarity($questionEmbedding, $chunkEmbedding);
                // Weight by priority (higher priority gets slightly higher score)
                $adjustedSimilarity = $similarity * (1 + (3 - $priority) * 0.05);
                
                $chunks[] = $row['content'];
                $similarities[] = $adjustedSimilarity;
                $priorities[] = $priority;
            }
        }
        
        // Sort chunks by similarity score but keep priorities
        array_multisort($similarities, SORT_DESC, $priorities, SORT_ASC, $chunks);
        
        // If we found a summary, ensure it's in the results regardless of limit
        if ($summaryFound) {
            $limit = max(1, $limit - 1);
            return array_slice($chunks, 0, $limit + 1);
        }
        
        // Return top N most relevant chunks
        return array_slice($chunks, 0, $limit);
    }

    private function cosineSimilarity($vec1, $vec2) {
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

    private function constructPrompt($workshop, $chunks, $question, $conversationHistory = []) {
        $context = implode("\n\n", $chunks);
        
        $prompt = "You are an AI assistant helping users understand workshop content. 
Please provide a clear and concise answer based on the workshop content below.

Workshop Information:
- Title: {$workshop['name']}
- Trainer: {$workshop['trainer_name']}
- Description: {$workshop['description']}

Relevant Workshop Content:
{$context}";

        // Add conversation history if available
        if (!empty($conversationHistory)) {
            $historyText = "\nRecent Conversation History:\n";
            foreach ($conversationHistory as $index => $exchange) {
                $historyText .= "User: {$exchange['question']}\n";
                $historyText .= "Assistant: {$exchange['answer']}\n\n";
            }
            $prompt .= "\n" . $historyText;
        }

        $prompt .= "\nUser Question: {$question}

Please provide a detailed answer that:
1. Directly addresses the user's question
2. Uses specific information from the workshop content
3. Maintains a professional and educational tone
        4. Is clear and easy to understand
        5. Takes into account the conversation history for context (if provided)
        6. Every workshop starts at 5:00 PM and ends around at 6:30 PM IST";

        return $prompt;
    }

    private function storeConversation($workshopId, $userId, $question, $answer) {
        $query = "INSERT INTO conversation_history (workshop_id, user_id, question, answer, created_at) 
                  VALUES (?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "iiss", $workshopId, $userId, $question, $answer);
        mysqli_stmt_execute($stmt);
    }

    private function getConversationHistory($workshopId, $userId, $limit = 5) {
        $query = "SELECT question, answer, created_at 
                  FROM conversation_history 
                  WHERE workshop_id = ? AND user_id = ? 
                  ORDER BY created_at DESC 
                  LIMIT ?";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "iii", $workshopId, $userId, $limit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $history = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $history[] = $row;
        }
        
        // Return in chronological order (oldest first)
        return array_reverse($history);
    }

    public function refreshWorkshopTranscript($workshopId) {
        // Get workshop details
        $workshop = $this->getWorkshopDetails($workshopId);
        if (!$workshop) {
            return ['error' => 'Workshop not found'];
        }

        // Try both video_link and rlink
        $videoId = null;
        if (!empty($workshop['video_link'])) {
            $videoId = $this->vimeoService->extractVideoId($workshop['video_link']);
        }
        if (!$videoId && !empty($workshop['rlink'])) {
            $videoId = $this->vimeoService->extractVideoId($workshop['rlink']);
        }

        if (!$videoId) {
            return ['error' => 'No valid video ID found in workshop data'];
        }

        // Get video transcript with timestamps
        $transcript = $this->vimeoService->getVideoTranscript($videoId);
        if (!$transcript) {
            return ['error' => 'Could not retrieve video transcript'];
        }

        // Clear existing chunks for this workshop
        $this->clearExistingChunks($workshopId);
        
        // Store transcript chunks with embeddings and summary
        $this->storeTranscriptChunks($workshopId, $transcript);

        return [
            'success' => true, 
            'message' => 'Workshop transcript refreshed with timestamps and semantic chunking',
            'details' => [
                'workshop_id' => $workshopId,
                'workshop_name' => $workshop['name'],
                'rlink' => $videoId,
                'transcript_length' => strlen($transcript)
            ]
        ];
    }

    /**
     * Get suggested questions for a workshop
     * 
     * @param int $workshopId The workshop ID
     * @param int $limit The maximum number of questions to return
     * @return array Array of suggested questions
     */
    public function getSuggestedQuestions($workshopId, $limit = 10) {
        // Get questions from different categories for variety
        $categories = ['summary', 'missed_content', 'practical_application', 'content_clarification'];
        $questions = [];
        
        foreach ($categories as $category) {
            $categoryQuestions = $this->getWorkshopQuestions($workshopId, $category);
            if (!empty($categoryQuestions)) {
                // Take 1-3 questions from each category
                $questionsFromCategory = array_slice($categoryQuestions, 0, min(3, count($categoryQuestions)));
                $questions = array_merge($questions, $questionsFromCategory);
                
                // If we have enough questions, stop adding more
                if (count($questions) >= $limit) {
                    break;
                }
            }
        }
        
        // If we still need more questions, get some from any category
        if (count($questions) < $limit) {
            $moreQuestions = $this->getWorkshopQuestions($workshopId);
            // Filter out questions we already have
            $existingIds = array_map(function($q) { return $q['id']; }, $questions);
            $newQuestions = array_filter($moreQuestions, function($q) use ($existingIds) {
                return !in_array($q['id'] ?? 0, $existingIds);
            });
            
            $questions = array_merge($questions, array_slice($newQuestions, 0, $limit - count($questions)));
        }
        
        // Shuffle to mix up the categories
        shuffle($questions);
        
        // Return only the question text for the UI
        return array_map(function($q) {
            return [
                'text' => $q['question'],
                'type' => $q['question_type']
            ];
        }, array_slice($questions, 0, $limit));
    }
}
?> 