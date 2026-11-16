CREATE TABLE IF NOT EXISTS jingo_gateway_resolutions (
 event_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 target_code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 admin_id TEXT NOT NULL,
 note TEXT NOT NULL,
 original_reason TEXT NOT NULL,
 original_order_id CHAR(36) CHARACTER SET ascii NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX gateway_resolution_target(target_code),
 FOREIGN KEY(event_id) REFERENCES jingo_gateway_events(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
