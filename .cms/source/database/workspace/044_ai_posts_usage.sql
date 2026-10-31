ALTER TABLE ai_usage_events
    MODIFY COLUMN purpose ENUM('builder','chat','verification','posts') NOT NULL,
    ALGORITHM=INPLACE, LOCK=NONE;
