# Conversation History Feature

This update adds conversation history to the Workshop Bot, allowing it to remember past interactions with users and provide more contextual responses.

## Database Setup

1. Start your MySQL server through XAMPP:
   - Open XAMPP Control Panel
   - Start the MySQL service

2. Create the conversation history table by executing the SQL script:
   ```
   mysql -u [username] -p < conversation_history_table.sql
   ```
   (Replace [username] with your MySQL username, typically 'root')

## How It Works

1. The bot now keeps track of the last 5 conversations for each user per workshop
2. When a user asks a question, the bot includes their conversation history in the context
3. This allows the bot to maintain context across multiple questions

## Usage

The feature is automatically enabled. The `workshop_chat.php` file has been updated to pass the user ID to the `answerQuestion` method.

If you want to manually use this feature in your code:

```php
// Initialize the bot
$bot = new WorkshopBot();

// Call with user ID to enable conversation history
$response = $bot->answerQuestion($workshopId, $question, $userId);
```

## Troubleshooting

If the conversation history doesn't appear to be working:

1. Make sure the conversation_history table exists in your database
2. Check that the user ID is being passed correctly
3. Verify that the database connection in config.php is working 