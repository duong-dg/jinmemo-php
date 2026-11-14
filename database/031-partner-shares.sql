CREATE TABLE IF NOT EXISTS jingo_partner_shares (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 order_id CHAR(36) CHARACTER SET ascii NOT NULL,
 access_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 revoked_at DATETIME(6) NULL,
 INDEX(order_id),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS jingo_partner_share_actions (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 order_id CHAR(36) CHARACTER SET ascii NOT NULL,
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB;
