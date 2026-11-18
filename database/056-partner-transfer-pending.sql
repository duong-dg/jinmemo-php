SET @jm_partner_pending_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_partners' AND COLUMN_NAME='transfer_pending')=0,'ALTER TABLE jingo_partners ADD COLUMN transfer_pending TINYINT(1) NOT NULL DEFAULT 0','SET @jm_partner_pending_noop=1');
PREPARE jm_partner_pending_statement FROM @jm_partner_pending_sql;
EXECUTE jm_partner_pending_statement;
DEALLOCATE PREPARE jm_partner_pending_statement;
