CREATE TABLE IF NOT EXISTS jingo_counters (
 id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 data JSON NOT NULL,
 version INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_pos_shifts (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 counter_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 opened_by TEXT NOT NULL,
 opened_name TEXT NOT NULL,
 counter_name TEXT NOT NULL,
 opening_cash BIGINT UNSIGNED NOT NULL,
 opening_note TEXT NOT NULL,
 open_hash VARCHAR(128) NOT NULL,
 opened_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 closed_at DATETIME(6) NULL,
 closed_by TEXT NULL,
 close_request CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
 close_hash VARCHAR(128) NULL,
 close_note TEXT NULL,
 summary JSON NULL,
 version INT NOT NULL DEFAULT 1,
 INDEX(counter_id,opened_at),
 FOREIGN KEY(counter_id) REFERENCES jingo_counters(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_pos_open_shifts (
 counter_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 shift_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 FOREIGN KEY(counter_id) REFERENCES jingo_counters(id),
 FOREIGN KEY(shift_id) REFERENCES jingo_pos_shifts(id)
) ENGINE=InnoDB;
