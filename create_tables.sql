CREATE TABLE IF NOT EXISTS workshop_chunks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workshop_id INT NOT NULL,
    content TEXT NOT NULL,
    embedding JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workshop_id) REFERENCES workshops(id)
);

-- Table for tracking workshop processing status
CREATE TABLE IF NOT EXISTS `workshop_processing` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `workshop_id` INT NOT NULL,
    `status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    `chunks_count` INT DEFAULT 0,
    `last_processed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Table for tracking user interactions with workshops
CREATE TABLE IF NOT EXISTS `workshop_interactions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `workshop_id` INT NOT NULL,
    `question` TEXT NOT NULL,
    `answer` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table for user's workshop history
CREATE TABLE IF NOT EXISTS `user_workshop_history` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `workshop_id` INT NOT NULL,
    `last_interaction` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `interaction_count` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
); 