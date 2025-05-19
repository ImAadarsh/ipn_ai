<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'WorkshopBot.php';

// Set test user session
$_SESSION['user'] = [
    'id' => 36,
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
            $query = "SELECT w.*, wp.status, wp.chunks_count 
                     FROM workshops w 
                     INNER JOIN workshop_processing wp ON w.id = wp.workshop_id 
                     WHERE w.is_deleted = 0 
                     AND wp.status = 'completed'
                     ORDER BY w.id DESC";
            $result = mysqli_query($GLOBALS['connect'], $query);
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

            // Get answer from bot
            $answer = $bot->answerQuestion($workshopId, $question);

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
                'workshop' => $answer['workshop']
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
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workshop AI Assistant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4895ef;
            --success-color: #4cc9f0;
            --background-color: #f0f2f5;
            --card-background: #ffffff;
            --text-primary: #2b2d42;
            --text-secondary: #8d99ae;
        }

        body {
            background-color: var(--background-color);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-primary);
        }

        .main-container {
            max-width: 1200px;
            margin: 2rem auto;
            background: var(--card-background);
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        .main-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 6px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
        }

        h2 {
            color: var(--primary-color);
            font-weight: 700;
            margin-bottom: 2rem;
            text-align: center;
            position: relative;
            padding-bottom: 1rem;
        }

        h2::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 4px;
            background: var(--accent-color);
            border-radius: 2px;
        }

        .chat-container {
            height: 500px;
            overflow-y: auto;
            border: 1px solid rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 15px;
            background: var(--card-background);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.05);
        }

        .message {
            margin-bottom: 20px;
            padding: 15px 20px;
            border-radius: 15px;
            max-width: 80%;
            position: relative;
            animation: messageAppear 0.3s ease-out;
        }

        @keyframes messageAppear {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .user-message {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            margin-left: auto;
            border-bottom-right-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .bot-message {
            background: #f8f9fa;
            color: var(--text-primary);
            margin-right: auto;
            border-bottom-left-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .workshop-select {
            margin-bottom: 20px;
        }

        .workshop-info {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.05);
        }

        .input-group {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            border-radius: 15px;
            overflow: hidden;
            background: white;
            transition: all 0.3s ease;
        }

        .input-group:focus-within {
            box-shadow: 0 4px 20px rgba(67, 97, 238, 0.15);
        }

        .form-control {
            border: none;
            padding: 15px 20px;
            font-size: 1rem;
        }

        .form-control:focus {
            box-shadow: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            padding: 15px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }

        .workshop-card {
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            position: relative;
            overflow: hidden;
        }

        .workshop-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-color);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .workshop-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
        }

        .workshop-card:hover::before {
            opacity: 1;
        }

        .workshop-card.selected {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.1);
        }

        .workshop-card.selected::before {
            opacity: 1;
        }

        .workshop-title {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-primary);
            font-size: 1.1rem;
        }

        .workshop-meta {
            font-size: 0.9rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .loading-spinner {
            display: none;
            text-align: center;
            padding: 20px;
            color: var(--primary-color);
        }

        .typing-indicator {
            display: none;
            padding: 15px;
            color: var(--text-secondary);
            font-style: italic;
            background: #f8f9fa;
            border-radius: 10px;
            margin: 10px 0;
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { opacity: 0.6; }
            50% { opacity: 1; }
            100% { opacity: 0.6; }
        }

        /* Custom Scrollbar */
        .chat-container::-webkit-scrollbar {
            width: 8px;
        }

        .chat-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .chat-container::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 4px;
        }

        .chat-container::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-color);
        }

        /* Empty State Styling */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <h2 class="text-center mb-4">Workshop AI Assistant</h2>
        <div class="row">
            <div class="col-md-4">
                <div class="workshop-select">
                    <h5 class="mb-3">Available Workshops</h5>
                    <div id="workshops-list"></div>
                </div>
                <div class="workshop-info">
                    <h5>Workshop Details</h5>
                    <div id="workshop-details"></div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="chat-container" id="chat-container">
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <p>Select a workshop to start asking questions</p>
                    </div>
                </div>
                <div class="typing-indicator" id="typing-indicator">
                    AI is thinking...
                </div>
                <div class="input-group">
                    <input type="text" id="question-input" class="form-control" placeholder="Type your question here...">
                    <button class="btn btn-primary" id="send-button">
                        <i class="fas fa-paper-plane"></i> Send
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            let currentWorkshopId = null;

            // Load workshops
            function loadWorkshops() {
                $.post('workshop_chat.php', { action: 'get_workshops' }, function(response) {
                    if (response.success) {
                        let workshopsHtml = '';
                        response.workshops.forEach(function(workshop) {
                            workshopsHtml += `
                                <div class="workshop-card" data-id="${workshop.id}">
                                    <div class="workshop-title">
                                        <i class="fas fa-graduation-cap me-2"></i>${workshop.name}
                                    </div>
                                    <div class="workshop-meta">
                                        <i class="fas fa-check-circle text-success"></i>
                                        <span>Ready for Q&A</span>
                                    </div>
                                </div>
                            `;
                        });
                        $('#workshops-list').html(workshopsHtml);
                    }
                });
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
                                    <p>No previous conversations. Start asking questions!</p>
                                </div>
                            `);
                        } else {
                            response.history.forEach(function(item) {
                                addMessage(item.question, 'user');
                                addMessage(item.answer, 'bot');
                            });
                        }
                    }
                });
            }

            // Add message to chat
            function addMessage(text, type) {
                const messageClass = type === 'user' ? 'user-message' : 'bot-message';
                const message = `<div class="message ${messageClass}">${text}</div>`;
                $('#chat-container').append(message);
                $('#chat-container').scrollTop($('#chat-container')[0].scrollHeight);
            }

            // Handle workshop selection
            $(document).on('click', '.workshop-card', function() {
                $('.workshop-card').removeClass('selected');
                $(this).addClass('selected');
                
                const workshopId = $(this).data('id');
                currentWorkshopId = workshopId;
                
                // Update workshop details
                const workshopName = $(this).find('.workshop-title').text();
                $('#workshop-details').html(`
                    <div class="workshop-title">${workshopName}</div>
                    <div class="workshop-meta">
                        <i class="fas fa-check-circle text-success"></i> Ready for Q&A
                    </div>
                `);
                
                loadHistory(workshopId);
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

            // Send question to server
            function sendQuestion() {
                const question = $('#question-input').val().trim();
                if (!question || !currentWorkshopId) return;

                addMessage(question, 'user');
                $('#question-input').val('');
                $('#typing-indicator').show();

                $.post('workshop_chat.php', {
                    action: 'ask_question',
                    workshop_id: currentWorkshopId,
                    question: question
                }, function(response) {
                    $('#typing-indicator').hide();
                    if (response.success) {
                        addMessage(response.answer, 'bot');
                    } else {
                        addMessage('Error: ' + response.error, 'bot');
                    }
                });
            }

            // Initial load
            loadWorkshops();
        });
    </script>
</body>
</html> 