CREATE TABLE IF NOT EXISTS jingo_checkout_bundles (
 id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 code VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 access_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 total BIGINT NOT NULL,
 status VARCHAR(8) CHARACTER SET ascii NOT NULL DEFAULT 'PENDING',
 data LONGTEXT NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_checkout_members (
 kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 bundle_id CHAR(36) CHARACTER SET ascii NOT NULL,
 PRIMARY KEY(kind,entity_id),
 INDEX checkout_members_bundle(bundle_id),
 FOREIGN KEY(bundle_id) REFERENCES jingo_checkout_bundles(id)
) ENGINE=InnoDB;
