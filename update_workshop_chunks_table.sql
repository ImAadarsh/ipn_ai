-- Add priority column to workshop_chunks table
ALTER TABLE workshop_chunks ADD COLUMN priority TINYINT NOT NULL DEFAULT 2 AFTER embedding;

-- Add index on priority column to improve query performance
ALTER TABLE workshop_chunks ADD INDEX idx_priority (priority);

-- Add a comment explaining the priority values
-- 1 = Workshop summary (highest priority)
-- 2 = Regular content chunks
-- 3+ = Other/additional content (lower priority) 