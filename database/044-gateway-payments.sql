CREATE TABLE IF NOT EXISTS jingo_gateway_attempts (
 id VARCHAR(27) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 order_id CHAR(36) CHARACTER SET ascii NULL,
 provider VARCHAR(20) CHARACTER SET ascii NOT NULL,
 mode VARCHAR(4) CHARACTER SET ascii NOT NULL,
 amount BIGINT NOT NULL,
 config_version INT NOT NULL,
 config LONGTEXT NOT NULL,
 status VARCHAR(12) CHARACTER SET ascii NOT NULL DEFAULT 'PENDING',
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 expires_at DATETIME(6) NOT NULL,
 INDEX gateway_attempt_order(order_id,provider),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_gateway_events (
 id VARCHAR(200) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 attempt_id VARCHAR(27) CHARACTER SET ascii COLLATE ascii_bin NULL,
 order_id CHAR(36) CHARACTER SET ascii NULL,
 provider VARCHAR(20) CHARACTER SET ascii NOT NULL,
 mode VARCHAR(4) CHARACTER SET ascii NOT NULL,
 amount BIGINT NOT NULL,
 status VARCHAR(12) CHARACTER SET ascii NOT NULL,
 reason TEXT NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX gateway_event_status_date(status,created_at),
 FOREIGN KEY(attempt_id) REFERENCES jingo_gateway_attempts(id),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
