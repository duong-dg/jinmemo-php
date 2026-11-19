CREATE TABLE IF NOT EXISTS jingo_source_order_actions (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_general_ci PRIMARY KEY,
 order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
 request_hash VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 admin_id VARCHAR(64) NOT NULL,
 action VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 note TEXT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 INDEX(order_id,created_at,id),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
