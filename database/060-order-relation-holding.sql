CREATE TABLE IF NOT EXISTS jingo_transfer_partner_orders (
 order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 partner_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 FOREIGN KEY(order_id) REFERENCES jingo_transfer_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_transfer_pos_orders (
 order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 counter_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 admin_id VARCHAR(64) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES jingo_transfer_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_transfer_order_actions (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 admin_id VARCHAR(64) NOT NULL,
 action VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 note TEXT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 INDEX(order_id,created_at),
 FOREIGN KEY(order_id) REFERENCES jingo_transfer_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
