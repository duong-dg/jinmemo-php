CREATE TABLE IF NOT EXISTS jingo_partner_sessions (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 partner_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at DATETIME(6) NOT NULL,
 INDEX(partner_id),
 INDEX(expires_at),
 FOREIGN KEY(partner_id) REFERENCES jingo_partners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
