ALTER TABLE jingo_checkout_bundles MODIFY COLUMN access_hash CHAR(64) CHARACTER SET ascii NULL;
SET @jm_bundle_pending_sql=IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='jingo_checkout_bundles' AND COLUMN_NAME='lookup_pending')=0,'ALTER TABLE jingo_checkout_bundles ADD COLUMN lookup_pending TINYINT(1) NOT NULL DEFAULT 0','SET @jm_bundle_pending_noop=1');
PREPARE jm_bundle_pending_statement FROM @jm_bundle_pending_sql;
EXECUTE jm_bundle_pending_statement;
DEALLOCATE PREPARE jm_bundle_pending_statement;
ALTER TABLE jingo_payment_references MODIFY COLUMN reference VARCHAR(200) CHARACTER SET utf8mb4 NOT NULL, MODIFY COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);
CREATE TABLE IF NOT EXISTS jingo_bundle_transfers (
 bundle_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 source_sha CHAR(64) CHARACTER SET ascii NOT NULL,
 bundle_sha CHAR(64) CHARACTER SET ascii NOT NULL,
 gift_sha CHAR(64) CHARACTER SET ascii NOT NULL,
 refunds_digest CHAR(64) CHARACTER SET ascii NOT NULL,
 FOREIGN KEY(bundle_id) REFERENCES jingo_checkout_bundles(id)
) ENGINE=InnoDB;
