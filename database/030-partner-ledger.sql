CREATE TABLE IF NOT EXISTS jingo_partner_ledger (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 partner_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 order_id CHAR(36) CHARACTER SET ascii NULL,
 kind VARCHAR(32) NOT NULL,
 amount BIGINT NOT NULL,
 reference VARCHAR(200) NOT NULL,
 note TEXT NOT NULL,
 actor_id TEXT NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 due_at DATETIME(6) NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(partner_id,created_at,id),
 INDEX(order_id),
 FOREIGN KEY(partner_id) REFERENCES jingo_partners(id),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
