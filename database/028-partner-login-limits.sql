CREATE TABLE IF NOT EXISTS jingo_partner_login_limits (
 id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 attempts INT NOT NULL DEFAULT 0,
 until_at DATETIME NOT NULL,
 INDEX(until_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
