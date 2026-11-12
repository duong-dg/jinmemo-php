CREATE TABLE IF NOT EXISTS jingo_order_actions (
 id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 order_id CHAR(36) CHARACTER SET ascii NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 admin_id VARCHAR(64) NOT NULL,
 action VARCHAR(24) NOT NULL,
 order_version INT NOT NULL,
 data JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(order_id,created_at),
 UNIQUE(order_id,order_version),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB;
