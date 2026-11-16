CREATE TABLE IF NOT EXISTS jingo_sepay_payloads (
 id VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 payload_encrypted LONGTEXT NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX sepay_payloads_date(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
