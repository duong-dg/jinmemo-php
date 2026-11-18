SET @jm_media_trash_sql = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_media' AND COLUMN_NAME='deleted_at')=0,'ALTER TABLE jingo_media ADD COLUMN deleted_at DATETIME(6) NULL','SET @jm_media_trash_noop = 1');
PREPARE jm_media_trash_statement FROM @jm_media_trash_sql;
EXECUTE jm_media_trash_statement;
DEALLOCATE PREPARE jm_media_trash_statement;
