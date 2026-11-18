CREATE TABLE IF NOT EXISTS jingo_transfer_orders (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 code CHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 access_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot JSON NOT NULL,
 contact JSON NOT NULL,
 booking_status VARCHAR(20) NOT NULL,
 payment_status VARCHAR(20) NOT NULL,
 instructions TEXT NOT NULL,
 version INT NOT NULL,
 created_at DATETIME(6) NOT NULL,
 INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
