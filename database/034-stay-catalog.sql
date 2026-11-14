CREATE TABLE IF NOT EXISTS jingo_stays (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 slug VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 data JSON NOT NULL,
 version INT NOT NULL DEFAULT 1,
 updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_stay_aliases (
 lang VARCHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 slug VARCHAR(150) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 hotel_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(lang,slug),
 FOREIGN KEY(hotel_id) REFERENCES jingo_stays(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS jingo_stay_bookings (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 code VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 hotel_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 room_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 check_in CHAR(10) CHARACTER SET ascii NOT NULL,
 check_out CHAR(10) CHARACTER SET ascii NOT NULL,
 rooms INT NOT NULL CHECK (rooms>0),
 status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'PENDING',
 access_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 secret TEXT NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 data JSON NOT NULL,
 expires_at DATETIME(6) NULL,
 version INT NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX stay_dates(hotel_id,room_id,check_in,check_out),
 INDEX stay_booking_recent(created_at),
 FOREIGN KEY(hotel_id) REFERENCES jingo_stays(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
