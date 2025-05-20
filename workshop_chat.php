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
    <style>
        :root {
            --primary-color: #2563eb;
            --secondary-color: #1d4ed8;
            --accent-color: #3b82f6;
            --success-color: #10b981;
            --background-color: #f8fafc;
            --card-background: rgba(255, 255, 255, 0.9);
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --border-color: rgba(148, 163, 184, 0.2);
            --sidebar-width: 320px;
            --header-height: 70px;
            --gradient-primary: linear-gradient(135deg, #2563eb, #1d4ed8);
            --glass-background: rgba(255, 255, 255, 0.95);
            --glass-border: rgba(226, 232, 240, 0.8);
            --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.1);
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            position: relative;
            overflow: hidden;
            color: var(--text-primary);
        }

        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><circle cx="50" cy="50" r="1" fill="%232563eb" opacity="0.1"/></svg>') repeat;
            z-index: -1;
            opacity: 0.5;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        .app-container {
            display: flex;
            height: 100vh;
            position: relative;
        }

        /* Header Styles */
        .header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--header-height);
            background: var(--glass-background);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            z-index: 1000;
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
        }

        .header-logo {
            height: 40px;
            margin-right: 1rem;
        }

        .header-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .nav-buttons {
            margin-left: auto;
            display: flex;
            gap: 1rem;
        }

        .nav-button {
            padding: 0.5rem 1.25rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: var(--text-primary);
            background: var(--glass-background);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(5px);
        }

        .nav-button:hover {
            transform: translateY(-1px);
            box-shadow: var(--glass-shadow);
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .nav-button.primary {
            background: var(--gradient-primary);
            color: white;
            border: none;
        }

        .nav-button.primary:hover {
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.2);
        }

        /* Sidebar Styles */
        .sidebar {
            width: var(--sidebar-width);
            height: calc(100vh - var(--header-height));
            position: fixed;
            top: var(--header-height);
            left: 0;
            background: var(--glass-background);
            backdrop-filter: blur(10px);
            box-shadow: var(--glass-shadow);
            border-right: 1px solid var(--glass-border);
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
            z-index: 100;
        }

        .workshop-search {
            padding: 1.25rem;
            border-bottom: 1px solid var(--glass-border);
            background: rgba(255, 255, 255, 0.5);
        }

        .search-input {
            width: 100%;
            padding: 0.875rem 1.25rem;
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            background: white;
            transition: all 0.2s ease;
            color: var(--text-primary);
            font-size: 0.925rem;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .workshop-categories {
            padding: 1rem;
            display: flex;
            gap: 0.75rem;
            overflow-x: auto;
            background: rgba(255, 255, 255, 0.3);
            border-bottom: 1px solid var(--glass-border);
        }

        .category-pill {
            padding: 0.625rem 1.25rem;
            background: white;
            border: 1px solid var(--glass-border);
            border-radius: 25px;
            font-size: 0.875rem;
            color: var(--text-primary);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .category-pill:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-1px);
        }

        .category-pill.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .workshops-list {
            flex: 1;
            overflow-y: auto;
            padding: 1.25rem;
            background: rgba(255, 255, 255, 0.2);
        }

        .workshop-card {
            padding: 1.25rem;
            border-radius: 12px;
            background: white;
            border: 1px solid var(--glass-border);
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .workshop-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            border-color: var(--primary-color);
        }

        .workshop-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-primary);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .workshop-card:hover::before {
            opacity: 1;
        }

        .workshop-card.selected {
            background: var(--gradient-primary);
            border: none;
            color: white;
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.25);
        }

        .workshop-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-primary);
        }

        .workshop-card.selected .workshop-title {
            color: white;
        }

        .workshop-meta {
            font-size: 0.875rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .workshop-card.selected .workshop-meta {
            color: rgba(255, 255, 255, 0.9);
        }

        .time-ago, .new-badge {
            font-size: 0.75rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            margin-left: auto;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .time-ago {
            color: var(--primary-color);
            border: 1px solid rgba(37, 99, 235, 0.2);
        }

        .new-badge {
            background: var(--success-color);
            color: white;
            border: none;
        }

        .workshop-card.selected .time-ago {
            background: white;
            color: var(--primary-color);
        }

        .workshop-actions {
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid var(--glass-border);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .workshop-card.selected .workshop-actions {
            border-top-color: rgba(255, 255, 255, 0.2);
        }

        .certificate-btn {
            padding: 0.625rem 1.25rem;
            background: white;
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            font-size: 0.875rem;
            color: var(--primary-color);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .certificate-btn:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .workshop-card.selected .certificate-btn {
            background: white;
            color: var(--primary-color);
            border-color: white;
        }

        .workshop-card.selected .certificate-btn:hover {
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-1px);
        }

        .certificate-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
        }

        /* Main Chat Area */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            height: calc(100vh - var(--header-height));
            display: flex;
            flex-direction: column;
            background: var(--background-color);
        }

        .chat-container {
            flex: 1;
            overflow-y: auto;
            padding: 2rem;
        }

        .message {
            max-width: 80%;
            margin-bottom: 1.5rem;
            animation: messageSlide 0.3s ease-out;
        }

        @keyframes messageSlide {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .user-message {
            margin-left: auto;
            background: var(--gradient-primary);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.2);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 12px 12px 0 12px;
        }

        .bot-message {
            background: var(--glass-background);
            backdrop-filter: blur(5px);
            border: 1px solid var(--glass-border);
            padding: 1.5rem;
            border-radius: 12px 12px 12px 0;
        }

        .bot-message .response-section {
            background: rgb(248, 243, 205);
            border-left: 4px solid var(--accent-color);
            border-radius: 8px;
            padding: 1rem;
            margin: 0.75rem 0;
        }

        .bot-message .section-header {
            color: var(--accent-color);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
        }

        .bot-message .section-content {
            color: var(--text-primary);
            line-height: 1.6;
        }

        .bot-message .highlight {
            background: linear-gradient(120deg, rgba(59, 130, 246, 0.2) 0%, rgba(59, 130, 246, 0.2) 100%);
            background-repeat: no-repeat;
            background-size: 100% 0.2em;
            background-position: 0 88%;
            transition: background-size 0.25s ease-in;
            color: var(--accent-color);
        }

        .bot-message .highlight:hover {
            background-size: 100% 100%;
        }

        .bot-message .bold-text {
            color: var(--accent-color);
            font-weight: 600;
        }

        .input-container {
            padding: 1.5rem;
            background: var(--glass-background);
            backdrop-filter: blur(10px);
            border-top: 1px solid var(--glass-border);
        }

        .input-wrapper {
            display: flex;
            gap: 1rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .chat-input {
            flex: 1;
            padding: 1rem 1.5rem;
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.2s ease;
            background: rgba(255, 255, 255, 0.5);
            color: var(--text-primary);
        }

        .chat-input::placeholder {
            color: var(--text-secondary);
        }

        .send-button {
            padding: 1rem 2rem;
            background: var(--gradient-primary);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.2);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .send-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3);
        }

        /* Empty State Styles */
        .empty-state {
            /* max-width: 100vh; */
            margin: 2rem auto;
            padding: 3rem;
            text-align: center;
            background: var(--glass-background);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            color: var(--text-primary);
        }

        .empty-state-icon {
            font-size: 4rem;
            color: var(--primary-color);
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .empty-state-title {
            font-size: 2rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
        }

        .empty-state-text {
            color: var(--text-primary);
            font-size: 1.1rem;
            margin-bottom: 2.5rem;
            line-height: 1.6;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
            margin-top: 2rem;
            padding: 0 1rem;
        }

        .feature-card {
            padding: 2rem;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 12px;
            border: 1px solid var(--glass-border);
            text-align: left;
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--glass-shadow);
            background: rgba(255, 255, 255, 0.9);
        }

        .feature-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
        }

        .feature-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1rem;
        }

        .feature-description {
            color: var(--text-primary);
            font-size: 1rem;
            line-height: 1.6;
        }

        .typing-indicator {
            display: none;
            padding: 1rem;
            text-align: center;
            color: var(--text-primary);
            font-size: 0.9rem;
            position: absolute;
            bottom: 80px;
            left: 0;
            right: 0;
            background: rgba(255, 255, 255, 0.9);
            border-top: 1px solid var(--glass-border);
                overflow-y: auto;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 100;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-button {
                display: block;
            }

            .header-title {
                font-size: 1rem;
            }

            .message {
                max-width: 90%;
            }

            .input-wrapper {
                flex-direction: column;
            }

            .send-button {
                width: 100%;
            }

            .features-grid {
                grid-template-columns: 1fr;
            }

            .empty-state {
                margin: 1rem;
                padding: 2rem;
            }

            .empty-state-title {
                font-size: 1.5rem;
            }

            .feature-card {
                padding: 1.5rem;
            }
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.6);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }

        /* Mobile Menu Button */
        .mobile-menu-button {
            display: none;
            background: none;
            border: none;
            color: var(--text-primary);
            font-size: 1.5rem;
            cursor: pointer;
            margin-right: 1rem;
        }

        /* Workshop Categories */
        .workshop-categories {
            display: flex;
            gap: 0.5rem;
            padding: 0 1rem;
            margin-bottom: 1rem;
            margin-top: 0.4rem;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .workshop-categories::-webkit-scrollbar {
            display: none;
        }

        .category-pill {
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.5);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(5px);
            border-radius: 20px;
            font-size: 0.875rem;
            color: var(--text-secondary);
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.2s ease;
        }

        .category-pill.active {
            background: var(--gradient-primary);
            border: none;
            color: white;
        }

        /* Animations */
        @keyframes glow {
            0% { box-shadow: 0 0 5px rgba(59, 130, 246, 0.2); }
            50% { box-shadow: 0 0 20px rgba(59, 130, 246, 0.4); }
            100% { box-shadow: 0 0 5px rgba(59, 130, 246, 0.2); }
        }

        .workshop-card.selected {
            animation: glow 2s infinite;
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
        }

        .empty-workshops {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
            background: rgba(255, 255, 255, 0.5);
            border-radius: 8px;
            margin: 1rem 0;
        }

        .notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            background: var(--glass-background);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            z-index: 1000;
            animation: slideIn 0.3s ease-out;
        }

        .notification.success {
            border-left: 4px solid var(--success-color);
        }

        .notification.error {
            border-left: 4px solid #ef4444;
        }

        .notification i {
            font-size: 1.25rem;
        }

        .notification.success i {
            color: var(--success-color);
        }

        .notification.error i {
            color: #ef4444;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <button class="mobile-menu-button">
            <i class="fas fa-bars"></i>
        </button>
        <img src="https://ipnacademy.in/new_assets/img/ipn/ipn.png" alt="IPN Academy" class="header-logo">
        <h1 class="header-title">IPN AI Teacher</h1>
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
                <i class="fas fa-circle-notch fa-spin"></i> AI Teacher is thinking...
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
                let formattedText = text;

                if (type === 'bot') {
                    formattedText = formatBotResponse(text);
                }

                const message = `<div class="message ${messageClass}">${formattedText}</div>`;
                $('#chat-container').append(message);
                $('#chat-container').scrollTop($('#chat-container')[0].scrollHeight);
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

            // Handle workshop selection
            $(document).on('click', '.workshop-card', function() {
                $('.workshop-card').removeClass('selected');
                $(this).addClass('selected');
                currentWorkshopId = $(this).data('id');
                loadHistory(currentWorkshopId);
                
                // Close sidebar on mobile after selection
                if (window.innerWidth <= 768) {
                    $('.sidebar').removeClass('active');
                }
            });

            // Handle send button click
            $('#send-button').click(sendQuestion);

            // Handle enter key
            $('#question-input').keypress(function(e) {
                if (e.which === 13) {
                    sendQuestion();
                }
            });

            // Send question to server
            function sendQuestion() {
                const question = $('#question-input').val().trim();
                if (!question) {
                    alert('Please enter a question');
                    return;
                }
                if (!currentWorkshopId) {
                    alert('Please select a workshop first');
                    return;
                }

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

            // Initial load
            loadWorkshops();
            $('#typing-indicator').hide(); // Hide typing indicator initially
        });
    </script>
</body>
</html>