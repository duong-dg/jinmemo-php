CREATE TABLE IF NOT EXISTS jingo_ride_payment_attempts (
 id VARCHAR(27) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 ride_id CHAR(36) CHARACTER SET ascii NOT NULL,
 amount BIGINT NOT NULL,
 config_version INT NOT NULL,
 config LONGTEXT NOT NULL,
 status VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'PENDING',
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 expires_at DATETIME(6) NOT NULL,
 INDEX ride_payment_attempt_ride(ride_id),
 FOREIGN KEY(ride_id) REFERENCES jingo_ride_bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_ride_payment_events (
 event_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 ride_id CHAR(36) CHARACTER SET ascii NOT NULL,
 FOREIGN KEY(event_id) REFERENCES jingo_gateway_events(id),
 FOREIGN KEY(ride_id) REFERENCES jingo_ride_bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
