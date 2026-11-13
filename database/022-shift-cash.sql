CREATE TABLE IF NOT EXISTS jingo_pos_shift_receipts (
 payment_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 shift_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 INDEX(shift_id),
 FOREIGN KEY(payment_id) REFERENCES jingo_order_payments(id),
 FOREIGN KEY(shift_id) REFERENCES jingo_pos_shifts(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS jingo_pos_cash_movements (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 shift_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 kind VARCHAR(8) NOT NULL,
 amount BIGINT UNSIGNED NOT NULL,
 reference TEXT NOT NULL,
 note TEXT NOT NULL,
 actor_id TEXT NOT NULL,
 actor_name TEXT NOT NULL,
 request_hash VARCHAR(128) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(shift_id,created_at),
 FOREIGN KEY(shift_id) REFERENCES jingo_pos_shifts(id),
 CHECK(kind IN ('IN','OUT')),
 CHECK(amount>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
