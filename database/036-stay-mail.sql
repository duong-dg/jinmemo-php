CREATE TABLE IF NOT EXISTS jingo_stay_mail (
 id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 booking_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event VARCHAR(40) CHARACTER SET ascii NOT NULL,
 status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'PENDING',
 attempts INT NOT NULL DEFAULT 0,
 next_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 last_error TEXT NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 sent_at DATETIME(6) NULL,
 INDEX stay_mail_due(status,next_at),
 INDEX stay_mail_booking(booking_id,created_at),
 FOREIGN KEY(booking_id) REFERENCES jingo_stay_bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
