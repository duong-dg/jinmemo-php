CREATE TABLE IF NOT EXISTS jingo_admins (
 id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 email VARCHAR(254) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_sessions (
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 admin_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at DATETIME NOT NULL,
 INDEX (expires_at),
 FOREIGN KEY (admin_id) REFERENCES jingo_admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_admin_security (
 id VARCHAR(64) PRIMARY KEY,
 data JSON NOT NULL,
 version INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO jingo_admin_security (id,data) VALUES ('policy','{"privateEntryEnabled":false,"otpEnabled":false,"geoEnabled":false,"entrySlug":""}')
 ON DUPLICATE KEY UPDATE id=id;
CREATE TABLE IF NOT EXISTS jingo_admin_login_limits (
 id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 attempts INT NOT NULL DEFAULT 0,
 until_at DATETIME NOT NULL,
 INDEX (until_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_admin_login_log (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 admin_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 email VARCHAR(254) NOT NULL,
 event VARCHAR(40) NOT NULL,
 data JSON NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
