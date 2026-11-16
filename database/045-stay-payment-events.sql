CREATE TABLE IF NOT EXISTS jingo_stay_payment_events (
    event_id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    booking_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    CONSTRAINT fk_stay_gateway_event FOREIGN KEY (event_id) REFERENCES jingo_gateway_events(id),
    CONSTRAINT fk_stay_gateway_booking FOREIGN KEY (booking_id) REFERENCES jingo_stay_bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
