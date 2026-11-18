CREATE TABLE IF NOT EXISTS jingo_transfer_records (
 table_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 row_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 row_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 payload JSON NOT NULL,
 PRIMARY KEY(table_name,row_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
