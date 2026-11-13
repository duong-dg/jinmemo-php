ALTER TABLE jingo_supply_lots MODIFY created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);
CREATE TABLE IF NOT EXISTS jingo_supply_allocations (
 order_id CHAR(36) CHARACTER SET ascii NOT NULL,
 lot_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 quantity INT NOT NULL,
 unit_cost BIGINT NOT NULL,
 released BOOLEAN NOT NULL DEFAULT FALSE,
 PRIMARY KEY(order_id,lot_id),
 INDEX(lot_id),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id),
 FOREIGN KEY(lot_id) REFERENCES jingo_supply_lots(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS jingo_supply_purchases (
 id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 lot_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 supplier_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 order_id CHAR(36) CHARACTER SET ascii NOT NULL,
 customer_type VARCHAR(40) NOT NULL,
 quantity INT NOT NULL,
 unit_cost BIGINT NOT NULL,
 data JSON NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(lot_id,created_at),
 INDEX(supplier_id,created_at),
 FOREIGN KEY(lot_id) REFERENCES jingo_supply_lots(id),
 FOREIGN KEY(supplier_id) REFERENCES jingo_suppliers(id),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS jingo_supply_actions (
 id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 order_id CHAR(36) CHARACTER SET ascii NOT NULL,
 request_hash VARCHAR(128) NOT NULL,
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS jingo_supplier_payments (
 id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 supplier_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 amount BIGINT NOT NULL,
 method VARCHAR(20) NOT NULL,
 reference VARCHAR(200) NOT NULL UNIQUE,
 note TEXT NOT NULL,
 request_hash VARCHAR(128) NOT NULL,
 created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(supplier_id,created_at),
 FOREIGN KEY(supplier_id) REFERENCES jingo_suppliers(id)
) ENGINE=InnoDB;
