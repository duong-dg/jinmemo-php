CREATE TABLE IF NOT EXISTS jingo_area_source_cache (
 id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 data JSON NULL,
 fetched_at BIGINT NOT NULL DEFAULT 0,
 next_attempt BIGINT NOT NULL DEFAULT 0,
 INDEX (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO jingo_catalog_locks(id) VALUES ('area-source');
