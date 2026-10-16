-- Migration 009: AI Chat Support for i-Cadet (v1)
-- Run ONCE in phpMyAdmin: select the `unitsync` database > SQL tab > paste > Go

CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `role` ENUM('user','assistant') NOT NULL,
    `content` TEXT NOT NULL,
    `page_context` VARCHAR(100) DEFAULT NULL,
    `is_unanswered` TINYINT(1) NOT NULL DEFAULT 0,
    `is_cleared` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_chat_user` (`user_id`, `created_at`),
    CONSTRAINT `fk_chat_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_feedback` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `rating` TINYINT NOT NULL COMMENT '1 = helpful, -1 = not helpful',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_chat_fb_msg` (`message_id`),
    KEY `idx_chat_fb_user` (`user_id`),
    CONSTRAINT `fk_chat_fb_msg` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_fb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_rate_limits` (
    `user_id` INT NOT NULL,
    `window_minute` VARCHAR(20) NOT NULL,
    `request_count` INT NOT NULL DEFAULT 1,
    PRIMARY KEY (`user_id`, `window_minute`),
    CONSTRAINT `fk_ai_rate_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key_name`, `value`, `description`)
VALUES 
    ('ai_chat_enabled', '1', 'Master switch for the i-Cadet assistant (1 = on, 0 = off)'),
    ('ai_name', 'i-Cadet', 'Name of the friendly AI assistant'),
    ('ai_model', 'claude-haiku-5-5', 'Model identifier for Claude AI API')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;
