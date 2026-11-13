CREATE TABLE IF NOT EXISTS jingo_ticket_stock (
 id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 lot_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 code TEXT NOT NULL,
 code_hash CHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
 status VARCHAR(20) NOT NULL DEFAULT 'AVAILABLE',
 version INT NOT NULL DEFAULT 1,
 reason VARCHAR(500) NOT NULL DEFAULT '',
 order_id CHAR(36) CHARACTER SET ascii NULL,
 ticket_id CHAR(36) CHARACTER SET ascii NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(lot_id,status),
 FOREIGN KEY(lot_id) REFERENCES jingo_supply_lots(id),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id),
 FOREIGN KEY(ticket_id) REFERENCES jingo_order_tickets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_stock_imports (
 id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 lot_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_hash VARCHAR(128) NOT NULL,
 quantity INT NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY(lot_id) REFERENCES jingo_supply_lots(id)
) ENGINE=InnoDB;
