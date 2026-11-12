CREATE TABLE IF NOT EXISTS jingo_order_tickets (
 id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 order_id CHAR(36) CHARACTER SET ascii NOT NULL,
 code TEXT NOT NULL,
 code_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
 kind VARCHAR(32) NOT NULL,
 covers INT NOT NULL,
 note TEXT NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE(order_id,id),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS jingo_ticket_files (
 id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 order_id CHAR(36) CHARACTER SET ascii NOT NULL,
 ticket_id CHAR(36) CHARACTER SET ascii NOT NULL,
 name VARCHAR(150) NOT NULL,
 mime VARCHAR(64) NOT NULL,
 bytes INT NOT NULL,
 hash CHAR(64) CHARACTER SET ascii NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 revoked_at DATETIME(6) NULL,
 revoke_reason VARCHAR(1000) NOT NULL DEFAULT '',
 file_kind VARCHAR(16) NOT NULL DEFAULT 'ORIGINAL',
 deleted_at DATETIME(6) NULL,
 partner_shared TINYINT(1) NOT NULL DEFAULT 0,
 sharing_version INT NOT NULL DEFAULT 1,
 INDEX(order_id,hash),
 FOREIGN KEY(order_id,ticket_id) REFERENCES jingo_order_tickets(order_id,id)
) ENGINE=InnoDB;
