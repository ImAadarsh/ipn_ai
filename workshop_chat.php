<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'WorkshopBot.php';

// Set test user session
$_SESSION['user'] = [
    'id' => 37,
    'name' => 'Aadarsh Gupta',
    'email' => 'aadarshkavita@gmail.com',
    'mobile' => '9399380920',
    'designation' => 'Founder',
    'institute_name' => 'Endeavour Digital',
    'city' => 'Bhopal',
    'user_type' => 'admin'
];

$bot = new WorkshopBot();

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'get_workshops':
            $category = isset($_POST['category']) ? $_POST['category'] : 'all';
            $userId = $_SESSION['user']['id'];
            
            switch($category) {
                case 'recent':
                    // Get workshops with recent interactions for the current user
                    $query = "SELECT DISTINCT w.*, wp.status, wp.chunks_count, uwh.last_interaction 
                             FROM workshops w 
                             INNER JOIN workshop_processing wp ON w.id = wp.workshop_id 
                             INNER JOIN user_workshop_history uwh ON w.id = uwh.workshop_id 
                             WHERE w.is_deleted = 0 
                             AND wp.status = 'completed'
                             AND uwh.user_id = ?
                             ORDER BY uwh.last_interaction DESC";
                    $stmt = mysqli_prepare($GLOBALS['connect'], $query);
                    mysqli_stmt_bind_param($stmt, "i", $userId);
                    break;
                    
                case 'new':
                    // Get workshops created in the last 30 days
                    $query = "SELECT w.*, wp.status, wp.chunks_count 
                             FROM workshops w 
                             INNER JOIN workshop_processing wp ON w.id = wp.workshop_id 
                             WHERE w.is_deleted = 0 
                             AND wp.status = 'completed'
                             AND w.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                             ORDER BY w.created_at DESC";
                    $stmt = mysqli_prepare($GLOBALS['connect'], $query);
                    break;
                    
                default: // 'all' case
                    $query = "SELECT w.*, wp.status, wp.chunks_count 
                             FROM workshops w 
                             INNER JOIN workshop_processing wp ON w.id = wp.workshop_id 
                             WHERE w.is_deleted = 0 
                             AND wp.status = 'completed'
                             ORDER BY w.id DESC";
                    $stmt = mysqli_prepare($GLOBALS['connect'], $query);
                    break;
            }
            
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $workshops = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $workshops[] = $row;
            }
            echo json_encode(['success' => true, 'workshops' => $workshops]);
            exit;

        case 'ask_question':
            if (!isset($_POST['workshop_id']) || !isset($_POST['question'])) {
                echo json_encode(['error' => 'Missing parameters']);
                exit;
            }

            $workshopId = (int)$_POST['workshop_id'];
            $question = $_POST['question'];
            $userId = $_SESSION['user']['id'];

            // Get answer from bot - pass userId to enable conversation history
            $answer = $bot->answerQuestion($workshopId, $question, $userId);

            if (isset($answer['error'])) {
                echo json_encode(['error' => $answer['error']]);
                exit;
            }

            // Save interaction
            $query = "INSERT INTO workshop_interactions (user_id, workshop_id, question, answer) 
                     VALUES (?, ?, ?, ?)";
            $stmt = mysqli_prepare($GLOBALS['connect'], $query);
            mysqli_stmt_bind_param($stmt, "iiss", $userId, $workshopId, $question, $answer['answer']);
            mysqli_stmt_execute($stmt);

            // Update user workshop history
            $query = "INSERT INTO user_workshop_history (user_id, workshop_id, interaction_count) 
                     VALUES (?, ?, 1) 
                     ON DUPLICATE KEY UPDATE 
                     interaction_count = interaction_count + 1,
                     last_interaction = CURRENT_TIMESTAMP";
            $stmt = mysqli_prepare($GLOBALS['connect'], $query);
            mysqli_stmt_bind_param($stmt, "ii", $userId, $workshopId);
            mysqli_stmt_execute($stmt);

            echo json_encode([
                'success' => true,
                'answer' => $answer['answer'],
                'workshop' => $answer['workshop'],
                'follow_up_questions' => isset($answer['follow_up_questions']) ? $answer['follow_up_questions'] : []
            ]);
            exit;

        case 'get_history':
            if (!isset($_POST['workshop_id'])) {
                echo json_encode(['error' => 'Missing workshop ID']);
                exit;
            }

            $workshopId = (int)$_POST['workshop_id'];
            $userId = $_SESSION['user']['id'];

            $query = "SELECT question, answer, created_at 
                     FROM workshop_interactions 
                     WHERE user_id = ? AND workshop_id = ? 
                     ORDER BY created_at DESC";
            $stmt = mysqli_prepare($GLOBALS['connect'], $query);
            mysqli_stmt_bind_param($stmt, "ii", $userId, $workshopId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            $history = [];
            while ($row = mysqli_fetch_assoc($result)) {
                $history[] = $row;
            }

            echo json_encode(['success' => true, 'history' => $history]);
            exit;

        case 'verify_certificate':
            if (!isset($_POST['workshop_id'])) {
                echo json_encode(['error' => 'Missing workshop ID']);
                exit;
            }

            $workshopId = (int)$_POST['workshop_id'];
            $userId = $_SESSION['user']['id'];

            // Check if user has paid for this workshop
            $query = "SELECT p.order_id, p.payment_status, p.cpd 
                     FROM payments p 
                     WHERE p.user_id = ? 
                     AND p.workshop_id = ? 
                     AND p.payment_status = 1 
                     LIMIT 1";
            
            $stmt = mysqli_prepare($GLOBALS['connect'], $query);
            mysqli_stmt_bind_param($stmt, "ii", $userId, $workshopId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) > 0) {
                $payment = mysqli_fetch_assoc($result);
                $certificateUrl = "https://ipnacademy.in/user/certificate.php?id=" . $payment['order_id'];
                echo json_encode([
                    'success' => true,
                    'certificate_url' => $certificateUrl,
                    'cpd_hours' => $payment['cpd']
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'No valid payment found for this workshop'
                ]);
            }
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPN AI Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        .typing-indicator {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            display: none;
        }

        .ai-thinking {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
            background: rgba(255, 255, 255, 0.18);
            border-radius: 24px;
            box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.22);
            padding: 48px 56px 40px 56px;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            min-width: 320px;
        }

        .wave-container {
            position: relative;
            width: 160px;
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .wave {
            position: absolute;
            border-radius: 50%;
            background: transparent;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(79, 70, 229, 0.25);
            box-shadow: 0 0 20px rgba(79, 70, 229, 0.08);
            animation: wave 3s infinite;
        }

        .wave-1 { 
            width: 100%; 
            height: 100%; 
            animation-delay: 0s;
            border-width: 1.5px;
        }
        .wave-2 { 
            width: 85%; 
            height: 85%; 
            animation-delay: 0.2s;
            border-width: 1.2px;
        }
        .wave-3 { 
            width: 70%; 
            height: 70%; 
            animation-delay: 0.4s;
            border-width: 1px;
        }
        .wave-4 { 
            width: 55%; 
            height: 55%; 
            animation-delay: 0.6s;
            border-width: 0.8px;
        }
        .wave-5 { 
            width: 40%; 
            height: 40%; 
            animation-delay: 0.8s;
            border-width: 0.6px;
        }

        .processing-core {
            position: relative;
            width: 30%;
            height: 30%;
            z-index: 2;
        }

        .core-inner {
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #2563eb, #4f46e5);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        .core-outer {
            position: absolute;
            width: 100%;
            height: 100%;
            border: 2px solid rgba(79, 70, 229, 0.3);
            border-radius: 50%;
            animation: rotate 3s linear infinite;
        }

        .processing-text {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 18px;
            font-weight: 600;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 4px;
            color: #22223b;
            text-shadow: 0 2px 8px rgba(255,255,255,0.25), 0 1px 2px rgba(0,0,0,0.08);
        }

        .text-gradient {
            background: linear-gradient(135deg, #2563eb, #4f46e5 80%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .dots {
            display: flex;
            gap: 2px;
        }

        .dot {
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            opacity: 0.6;
        }

        .dot:nth-child(1) { animation: pulse 1.4s infinite 0.2s; }
        .dot:nth-child(2) { animation: pulse 1.4s infinite 0.4s; }
        .dot:nth-child(3) { animation: pulse 1.4s infinite 0.6s; }

        @keyframes wave {
            0% {
                transform: scale(1);
                opacity: 0.7;
                border-color: rgba(79, 70, 229, 0.25);
            }
            50% {
                transform: scale(1.1);
                opacity: 0.35;
                border-color: rgba(79, 70, 229, 0.35);
            }
            100% {
                transform: scale(1);
                opacity: 0.7;
                border-color: rgba(79, 70, 229, 0.25);
            }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.6; }
            50% { transform: scale(1.2); opacity: 1; }
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Backdrop with glassmorphism */
        .typing-indicator::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: transparent;
            backdrop-filter: blur(12px);
            z-index: -1;
        }

        /* Add subtle gradient overlay */
        .typing-indicator::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at center, 
                rgba(79, 70, 229, 0.07) 0%,
                rgba(17, 24, 39, 0.08) 100%);
            z-index: -1;
        }
    </style>
</head>
<body>
    <header class="header">
        <button id="mobile-menu-button" class="mobile-menu-button">
            <i class="fas fa-bars"></i>
        </button>
        <img src="https://ipnacademy.in/new_assets/img/ipn/ipn.png" alt="IPN Academy" class="header-logo">
        <h1 class="header-title"></h1>
        <div class="nav-buttons">
            <a href="https://ipnacademy.in" target="_blank" class="nav-button">
                <i class="fas fa-external-link-alt"></i>
                Visit IPN Academy
            </a>
            <a href="/dashboard" class="nav-button primary">
                <i class="fas fa-columns"></i>
                Your Dashboard
            </a>
        </div>
    </header>

    <div class="app-container">
        <aside class="sidebar">
            <div class="workshop-search">
                <input type="text" class="search-input" placeholder="Search workshops...">
            </div>
            <div class="workshop-categories">
                <div class="category-pill active">All</div>
                <div class="category-pill">Recent</div>
                <div class="category-pill">New</div>
            </div>
            <div class="workshops-list" id="workshops-list"></div>
        </aside>

        <main class="main-content">
            <div class="chat-container" id="chat-container">
                <div class="empty-state" id="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-robot"></i>
                    </div>
                    <h2 class="empty-state-title">Welcome to IPN AI Teacher</h2>
                    <p class="empty-state-text">
                        Your personal AI assistant for interactive learning. Select a workshop from the sidebar to begin your learning journey.
                    </p>
                    <div class="features-grid">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-comments"></i>
                            </div>
                            <h3 class="feature-title">Interactive Learning</h3>
                            <p class="feature-description">
                                Ask questions, get detailed explanations, and engage in natural conversations about the workshop content.
                            </p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-lightbulb"></i>
                            </div>
                            <h3 class="feature-title">Smart Suggestions</h3>
                            <p class="feature-description">
                                Receive personalized learning suggestions and explore related topics to deepen your understanding.
                            </p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-history"></i>
                            </div>
                            <h3 class="feature-title">Learning History</h3>
                            <p class="feature-description">
                                Track your learning progress and revisit previous conversations to reinforce your knowledge.
                            </p>
                        </div>
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <h3 class="feature-title">Expert Knowledge</h3>
                            <p class="feature-description">
                                Access comprehensive workshop content and get expert-level insights on various topics.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="typing-indicator" id="typing-indicator">
                <div class="ai-thinking">
                    <div class="wave-container">
                        <div class="wave wave-1"></div>
                        <div class="wave wave-2"></div>
                        <div class="wave wave-3"></div>
                        <div class="wave wave-4"></div>
                        <div class="wave wave-5"></div>
                        <div class="processing-core">
                            <div class="core-inner"></div>
                            <div class="core-outer"></div>
                        </div>
                    </div>
                    <div class="processing-text">
                        <span class="text-gradient">IPN Teacher is thinking...</span>
                        <span class="dots">
                            <span class="dot"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                        </span>
                    </div>
                </div>
            </div>
            <div class="input-container">
                <div class="input-wrapper">
                    <input type="text" id="question-input" class="chat-input" placeholder="Ask your question here...">
                    <button class="send-button" id="send-button">
                        <i class="fas fa-paper-plane"></i>
                        <span>Send</span>
                    </button>
                </div>
            </div>
            <div class="suggested-questions" id="suggested-questions">
                <!-- <h3>Suggested Questions</h3> -->
                <div class="questions-container">
                    <!-- <div class="loading">Loading suggestions...</div> -->
                </div>
            </div>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            let currentWorkshopId = null;
            let filteredWorkshops = [];

            // Mobile menu toggle
            $('.mobile-menu-button').click(function() {
                $('.sidebar').toggleClass('active');
            });

            // Close sidebar when clicking outside on mobile
            $('.main-content').click(function() {
                if ($('.sidebar').hasClass('active')) {
                    $('.sidebar').removeClass('active');
                }
            });

            // Workshop search functionality
            $('.search-input').on('input', function() {
                const searchTerm = $(this).val().toLowerCase();
                filterWorkshops(searchTerm);
            });

            function filterWorkshops(searchTerm) {
                $('.workshop-card').each(function() {
                    const title = $(this).find('.workshop-title').text().toLowerCase();
                    if (title.includes(searchTerm)) {
                        $(this).show();
                    } else {
                        $(this).hide();
                    }
                });
            }

            // Category filter
            $('.category-pill').click(function() {
                $('.category-pill').removeClass('active');
                $(this).addClass('active');
                const category = $(this).text().toLowerCase();
                loadWorkshops(category);
            });

            // Load workshops
            function loadWorkshops(category = 'all') {
                $('#workshops-list').html('<div class="loading">Loading workshops...</div>');
                
                $.post('workshop_chat.php', { 
                    action: 'get_workshops',
                    category: category 
                }, function(response) {
                    if (response.success) {
                        let workshopsHtml = '';
                        if (response.workshops.length === 0) {
                            workshopsHtml = `
                                <div class="empty-workshops">
                                    <p>No workshops found for this category</p>
                                </div>
                            `;
                        } else {
                            response.workshops.forEach(function(workshop) {
                                let statusBadge = '';
                                if (category === 'recent') {
                                    const lastInteraction = new Date(workshop.last_interaction);
                                    const timeAgo = timeSince(lastInteraction);
                                    statusBadge = `<span class="time-ago">Last visited ${timeAgo} ago</span>`;
                                } else if (category === 'new') {
                                    const createdAt = new Date(workshop.created_at);
                                    const daysAgo = Math.floor((new Date() - createdAt) / (1000 * 60 * 60 * 24));
                                    statusBadge = `<span class="new-badge">New! ${daysAgo} days ago</span>`;
                                }
                                
                                workshopsHtml += `
                                    <div class="workshop-card" data-id="${workshop.id}">
                                        <div class="workshop-title">
                                            <i class="fas fa-graduation-cap"></i>
                                            ${workshop.name}
                                        </div>
                                        <div class="workshop-meta">
                                            <i class="fas fa-check-circle"></i>
                                            <span>Ready for Learning</span>
                                            ${statusBadge}
                                        </div>
                                        <div class="workshop-actions">
                                            <button type="button" class="certificate-btn" data-workshop-id="${workshop.id}">
                                                <i class="fas fa-certificate"></i> View Certificate
                                            </button>
                                        </div>
                                    </div>
                                `;
                            });
                        }
                        $('#workshops-list').html(workshopsHtml);
                    }
                });
            }

            // Helper function to format time
            function timeSince(date) {
                const seconds = Math.floor((new Date() - date) / 1000);
                let interval = seconds / 31536000;
              
                if (interval > 1) return Math.floor(interval) + " years";
                interval = seconds / 2592000;
                if (interval > 1) return Math.floor(interval) + " months";
                interval = seconds / 86400;
                if (interval > 1) return Math.floor(interval) + " days";
                interval = seconds / 3600;
                if (interval > 1) return Math.floor(interval) + " hours";
                interval = seconds / 60;
                if (interval > 1) return Math.floor(interval) + " minutes";
                return Math.floor(seconds) + " seconds";
            }

            // Load chat history
            function loadHistory(workshopId) {
                $('#chat-container').html('<div class="loading-spinner"><i class="fas fa-spinner fa-spin"></i> Loading chat history...</div>');
                $.post('workshop_chat.php', {
                    action: 'get_history',
                    workshop_id: workshopId
                }, function(response) {
                    if (response.success) {
                        $('#chat-container').empty();
                        if (response.history.length === 0) {
                            $('#chat-container').html(`
                                <div class="empty-state">
                                    <i class="fas fa-comments"></i>
                                    <p>Start your learning journey by asking questions!</p>
                                </div>
                            `);
                        } else {
                            // Reverse the history array to show oldest messages first
                            response.history.reverse().forEach(function(item) {
                                addMessage(item.question, 'user');
                                addMessage(item.answer, 'bot');
                            });
                        }
                    }
                });
            }

            // Add message to chat with smart scrolling
            function addMessage(text, type) {
                const messageClass = type === 'user' ? 'user-message' : 'bot-message';
                let formattedText = text;

                if (type === 'bot') {
                    formattedText = formatBotResponse(text);
                }

                const message = `<div class="message ${messageClass}">${formattedText}</div>`;
                $('#chat-container').append(message);
                
                // Smart scrolling - only scroll if user is near bottom
                const chatContainer = $('#chat-container');
                const isNearBottom = chatContainer[0].scrollHeight - chatContainer.scrollTop() - chatContainer.height() < 100;
                
                if (isNearBottom) {
                    chatContainer.animate({
                        scrollTop: chatContainer[0].scrollHeight
                    }, 300);
                }
            }

            function formatBotResponse(text) {
                // First handle any bold text sections
                text = text.replace(/\*\*(.*?)\*\*/g, '<span class="bold-text">$1</span>');

                // Split into paragraphs while preserving line breaks
                const paragraphs = text.split(/\n\n+/);
                let formattedText = '';

                paragraphs.forEach(paragraph => {
                    const trimmedParagraph = paragraph.trim();
                    if (!trimmedParagraph) return;

                    // Check if it's a numbered list
                    if (/^\d+\./.test(trimmedParagraph)) {
                        // Split into individual list items while preserving numbers
                        const items = trimmedParagraph.split(/\n/);
                        formattedText += `
                            <div class="response-section">
                                <div class="section-content">
                                    ${items.map(item => {
                                        const cleanItem = item.trim();
                                        if (!cleanItem) return '';
                                        const [number] = cleanItem.match(/^\d+\./) || [''];
                                        const content = cleanItem.replace(/^\d+\.\s*/, '');
                                        return `
                                            <div class="list-item">
                                                <span class="number">${number}</span>
                                                <span>${content}</span>
                                            </div>
                                        `;
                                    }).join('')}
                                </div>
                            </div>
                        `;
                    } else {
                        formattedText += `
                            <div class="response-section">
                                <div class="section-content">
                                    ${formatText(trimmedParagraph)}
                                </div>
                            </div>
                        `;
                    }
                });

                return formattedText || text;
            }

            function formatText(text) {
                // Handle bold text
                text = text.replace(/\*\*(.*?)\*\*/g, '<span class="bold-text">$1</span>');
                
                // Handle important terms (capitalized phrases)
                text = text.replace(/\b[A-Z][a-z]+(?:\s+[A-Z][a-z]+)*\b/g, '<span class="highlight">$&</span>');
                
                // Preserve line breaks
                text = text.replace(/\n/g, '<br>');
                
                return text;
            }

            // Replace the existing suggested questions div with a floating design
            $('head').append(`
                <style>
                    /* Floating Suggested Questions Panel */
                    .floating-suggestions {
                        position: fixed;
                        bottom: 80px;
                        right: 20px;
                        width: 300px;
                        background: rgba(255, 255, 255, 0.95);
                        border-radius: 12px;
                        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                        z-index: 100;
                        transition: all 0.3s ease;
                        max-height: 60vh;
                        display: flex;
                        flex-direction: column;
                        backdrop-filter: blur(10px);
                        border: 1px solid rgba(226, 232, 240, 0.8);
                        overflow: hidden;
                    }
                    
                    .suggestions-header {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        padding: 12px 15px;
                        background: rgba(249, 250, 251, 0.8);
                        border-bottom: 1px solid rgba(226, 232, 240, 0.8);
                        cursor: pointer;
                    }
                    
                    .suggestions-header h3 {
                        margin: 0;
                        font-size: 14px;
                        color: #4b5563;
                        font-weight: 600;
                    }
                    
                    .suggestions-toggle {
                        color: #6b7280;
                        font-size: 14px;
                        transition: transform 0.3s;
                    }
                    
                    .suggestions-body {
                        padding: 10px;
                        overflow-y: auto;
                        flex: 1;
                        transition: max-height 0.3s ease;
                    }
                    
                    .suggestions-body.collapsed {
                        max-height: 0;
                        padding: 0 10px;
                    }
                    
                    .question-chip {
                        display: inline-block;
                        background: rgba(224, 231, 255, 0.8);
                        color: #4b5563;
                        padding: 8px 15px;
                        border-radius: 20px;
                        margin: 4px;
                        font-size: 13px;
                        cursor: pointer;
                        transition: all 0.2s;
                        border: 1px solid rgba(199, 210, 254, 0.3);
                    }
                    
                    .question-chip:hover {
                        background: rgba(209, 218, 254, 0.9);
                        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.08);
                        transform: translateY(-1px);
                    }
                    
                    .question-chip.follow-up {
                        background: rgba(224, 242, 254, 0.8);
                        border-left: 3px solid #0ea5e9;
                    }
                    
                    /* Follow-up questions in chat */
                    .follow-up-questions {
                        background: rgba(240, 249, 255, 0.7);
                        border-radius: 8px;
                        padding: 10px 15px;
                        margin-top: 10px;
                        border-left: 3px solid #0ea5e9;
                        backdrop-filter: blur(5px);
                        max-width: 85%;
                        margin-left: auto;
                    }
                    
                    .follow-up-title {
                        font-weight: 600;
                        color: #0369a1;
                        font-size: 13px;
                        margin-bottom: 8px;
                    }
                    
                    .follow-up-container {
                        display: flex;
                        flex-wrap: wrap;
                    }
                    
                    /* Responsive adjustments */
                    @media (max-width: 768px) {
                        .floating-suggestions {
                            width: calc(100% - 40px);
                            right: 20px;
                            bottom: 70px;
                            max-height: 40vh;
                        }
                    }
                    
                    /* For smaller screens, make the floating panel more compact */
                    @media (max-width: 480px) {
                        .floating-suggestions {
                            width: calc(100% - 20px);
                            right: 10px;
                            max-height: 30vh;
                        }
                    }

                    .educator-badge {
                        display: inline-block;
                        background-color: #10B981;
                        color: white;
                        font-size: 10px;
                        padding: 2px 6px;
                        border-radius: 10px;
                        margin-left: 8px;
                        font-weight: 500;
                    }
                </style>
            `);
            
            // Add the floating suggestions panel
            $('body').append(`
                <div class="floating-suggestions" id="floating-suggestions">
                    <div class="suggestions-header" id="suggestions-header">
                        <h3><i class="fas fa-lightbulb"></i> Suggested Questions</h3>
                        <span class="suggestions-toggle"><i class="fas fa-chevron-up"></i></span>
                    </div>
                    <div class="suggestions-body" id="suggestions-body">
                        <div class="loading">Loading suggestions...</div>
                    </div>
                </div>
            `);
            
            // Toggle suggestions panel
            $('#suggestions-header').click(function() {
                $('#suggestions-body').toggleClass('collapsed');
                const icon = $('.suggestions-toggle i');
                if ($('#suggestions-body').hasClass('collapsed')) {
                    icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
                } else {
                    icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
                }
            });

            // Load suggested questions
            function loadSuggestedQuestions(workshopId) {
                $('#suggestions-body').html('<div class="loading">Loading suggestions...</div>');
                
                $.get('get_suggested_questions.php', { 
                    workshop_id: workshopId,
                    limit: 3,
                    random: true
                }, function(response) {
                    if (response.success && response.questions.length > 0) {
                        const questionsHtml = response.questions.map(q => 
                            `<div class="question-chip">${q.text}</div>`
                        ).join('');
                        
                        $('#suggestions-body').html(questionsHtml);
                        
                        // Add click handlers for suggested questions
                        $('.question-chip').click(function() {
                            const question = $(this).text();
                            sendQuestion(question);
                        });
                    } else {
                        $('#suggestions-body').html('<div class="no-suggestions">No suggested questions available</div>');
                    }
                }).fail(function() {
                    $('#suggestions-body').html('<div class="error">Failed to load suggestions</div>');
                });
            }

            // Add follow-up questions with a more space-efficient design
            function addFollowUpQuestions(followUpQuestions) {
                if (!followUpQuestions || followUpQuestions.length === 0) return;
                
                const followUpHtml = `
                    <div class="follow-up-questions">
                        <div class="follow-up-title">
                            <i class="fas fa-lightbulb"></i> You might want to ask:
                        </div>
                        <div class="follow-up-container">
                            ${followUpQuestions.map((q, index) => 
                                `<div class="question-chip follow-up">
                                    ${q}
                                    ${index === 0 ? '<span class="educator-badge">95% educators ask this</span>' : ''}
                                </div>`
                            ).join('')}
                        </div>
                    </div>
                `;
                $('#chat-container').append(followUpHtml);
                
                // Add click handlers for follow-up questions
                $('.follow-up-questions .question-chip').click(function() {
                    const question = $(this).text();
                    sendQuestion(question, true);
                });
                
                // Scroll to bottom
                $('#chat-container').scrollTop($('#chat-container')[0].scrollHeight);
            }

            // Send question to server - updated to use new follow-up display
            function sendQuestion(question, isFollowUp = false) {
                const currentQuestion = question || $('#question-input').val().trim();
                if (!currentQuestion) {
                    alert('Please enter a question');
                    return;
                }
                if (!currentWorkshopId) {
                    alert('Please select a workshop first');
                    return;
                }

                addMessage(currentQuestion, 'user');
                $('#question-input').val('');
                $('#typing-indicator').show();

                $.post('workshop_chat.php', {
                    action: 'ask_question',
                    workshop_id: currentWorkshopId,
                    question: currentQuestion
                }, function(response) {
                    $('#typing-indicator').hide();
                    if (response.success) {
                        addMessage(response.answer, 'bot');
                        
                        // Add follow-up questions with the new function
                        if (response.follow_up_questions) {
                            addFollowUpQuestions(response.follow_up_questions);
                        }
                    } else {
                        addMessage('Error: ' + response.error, 'bot');
                    }
                });
            }

            // Handle workshop selection
            $(document).on('click', '.workshop-card', function() {
                $('.workshop-card').removeClass('selected');
                $(this).addClass('selected');
                currentWorkshopId = $(this).data('id');
                loadHistory(currentWorkshopId);
                loadSuggestedQuestions(currentWorkshopId);
                
                // Close sidebar on mobile after selection
                if (window.innerWidth <= 768) {
                    $('.sidebar').removeClass('active');
                }
            });

            // Handle send button click
            $('#send-button').click(function() {
                sendQuestion();
            });

            // Handle enter key
            $('#question-input').keypress(function(e) {
                if (e.which === 13) {
                    sendQuestion();
                }
            });

            // Handle certificate button click
            $(document).on('click', '.certificate-btn', function(e) {
                e.stopPropagation();
                const workshopId = $(this).data('workshop-id');
                const btn = $(this);
                
                btn.prop('disabled', true)
                   .html('<i class="fas fa-spinner fa-spin"></i> Checking...');
                
                $.post('workshop_chat.php', {
                    action: 'verify_certificate',
                    workshop_id: workshopId
                }, function(response) {
                    btn.prop('disabled', false)
                       .html('<i class="fas fa-certificate"></i> View Certificate');
                    
                    if (response.success) {
                        window.open(response.certificate_url, '_blank');
                        if (response.cpd_hours) {
                            showNotification(`Certificate available with ${response.cpd_hours} CPD hours!`);
                        }
                    } else {
                        showNotification(response.message || 'Certificate not available', 'error');
                    }
                }).fail(function() {
                    btn.prop('disabled', false)
                       .html('<i class="fas fa-certificate"></i> View Certificate');
                    showNotification('Error checking certificate status', 'error');
                });
            });

            // Notification function
            function showNotification(message, type = 'success') {
                const notification = $(`
                    <div class="notification ${type}">
                        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                        ${message}
                    </div>
                `);
                
                $('body').append(notification);
                
                setTimeout(() => {
                    notification.fadeOut(() => notification.remove());
                }, 3000);
            }

            // Add smooth scrolling to chat container
            $('#chat-container').css({
                'scroll-behavior': 'smooth',
                'overflow-y': 'auto'
            });

            // Initial load
            loadWorkshops();
            $('#typing-indicator').hide(); // Hide typing indicator initially

            function activateNodes() {
                const nodes = document.querySelectorAll('.node');
                nodes.forEach((node, index) => {
                    setTimeout(() => {
                        node.classList.add('active');
                        setTimeout(() => {
                            node.classList.remove('active');
                        }, 500);
                    }, index * 100);
                });
            }

            // Call this when showing the typing indicator
            $('#typing-indicator').on('show', function() {
                activateNodes();
                setInterval(activateNodes, 2000);
            });
        });
    </script>
</body>
</html>