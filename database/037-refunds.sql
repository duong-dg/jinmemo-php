CREATE TABLE IF NOT EXISTS jingo_refunds (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 root_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 root_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 root_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount BIGINT NOT NULL CHECK (amount>0),
 refunded_on CHAR(10) CHARACTER SET ascii NOT NULL,
 reference_key VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL UNIQUE,
 data JSON NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX refunds_root(root_kind,root_id),
 INDEX refunds_recent(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
