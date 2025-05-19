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
                ]
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

        // Store transcript chunks with embeddings
        $this->storeTranscriptChunks($workshopId, $transcript);

        return [
            'success' => true, 
            'message' => 'Workshop processed successfully',
            'details' => [
                'workshop_id' => $workshopId,
                'workshop_name' => $workshop['name'],
                'video_id' => $videoId,
                'transcript_length' => strlen($transcript)
            ]
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
        // Split transcript into chunks (approximately 500 words each)
        $chunks = $this->splitIntoChunks($transcript, 500);
        
        // Clear existing chunks for this workshop
        $this->clearExistingChunks($workshopId);
        
        foreach ($chunks as $chunk) {
            // Generate embedding for the chunk
            $embedding = $this->geminiService->generateEmbedding($chunk);
            if ($embedding) {
                // Store chunk and embedding
                $this->storeChunk($workshopId, $chunk, json_encode($embedding));
            }
        }
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

    private function storeChunk($workshopId, $chunk, $embedding) {
        $query = "INSERT INTO workshop_chunks (workshop_id, content, embedding) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "iss", $workshopId, $chunk, $embedding);
        mysqli_stmt_execute($stmt);
    }

    public function answerQuestion($workshopId, $question) {
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

        // Construct prompt
        $prompt = $this->constructPrompt($workshop, $relevantChunks, $question);
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

        return [
            'answer' => $response,
            'workshop' => [
                'name' => $workshop['name'],
                'trainer' => $workshop['trainer_name']
            ]
        ];
    }

    private function findRelevantChunks($workshopId, $questionEmbedding) {
        // Get all chunks for the workshop
        $query = "SELECT content, embedding FROM workshop_chunks WHERE workshop_id = ?";
        $stmt = mysqli_prepare($this->connect, $query);
        mysqli_stmt_bind_param($stmt, "i", $workshopId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $chunks = [];
        $similarities = [];
        
        while ($row = mysqli_fetch_assoc($result)) {
            $chunkEmbedding = json_decode($row['embedding'], true);
            if ($chunkEmbedding) {
                $similarity = $this->cosineSimilarity($questionEmbedding, $chunkEmbedding);
                $chunks[] = $row['content'];
                $similarities[] = $similarity;
            }
        }
        
        // Sort chunks by similarity score
        array_multisort($similarities, SORT_DESC, $chunks);
        
        // Return top 3 most relevant chunks
        return array_slice($chunks, 0, 3);
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

    private function constructPrompt($workshop, $chunks, $question) {
        $context = implode("\n\n", $chunks);
        
        return "You are an AI assistant helping users understand workshop content. 
Please provide a clear and concise answer based on the workshop content below.

Workshop Information:
- Title: {$workshop['name']}
- Trainer: {$workshop['trainer_name']}
- Description: {$workshop['description']}

Relevant Workshop Content:
{$context}

User Question: {$question}

Please provide a detailed answer that:
1. Directly addresses the user's question
2. Uses specific information from the workshop content
3. Maintains a professional and educational tone
4. Is clear and easy to understand";
    }
}
?> 