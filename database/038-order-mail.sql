CREATE TABLE IF NOT EXISTS jingo_order_mail_keys (
 order_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 secret TEXT NOT NULL,
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS jingo_mail_outbox (
 id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 order_id CHAR(36) CHARACTER SET ascii NOT NULL,
 event VARCHAR(40) CHARACTER SET ascii NOT NULL,
 status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'PENDING',
 attempts INT NOT NULL DEFAULT 0,
 next_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 last_error TEXT NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 sent_at DATETIME(6) NULL,
 INDEX mail_outbox_due(status,next_at),
 INDEX mail_outbox_order(order_id,created_at),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
