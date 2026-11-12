CREATE TABLE IF NOT EXISTS jingo_suppliers (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 data JSON NOT NULL,
 version INT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_supply_lots (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 request_hash VARCHAR(128) NOT NULL,
 supplier_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 product_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 variant_id VARCHAR(80) NOT NULL,
 customer_type VARCHAR(40) NOT NULL,
 unit_cost BIGINT NOT NULL,
 total_quantity INT NOT NULL,
 valid_from VARCHAR(10) NOT NULL,
 valid_to VARCHAR(10) NOT NULL,
 priority INT NOT NULL DEFAULT 100,
 enabled BOOLEAN NOT NULL DEFAULT TRUE,
 archived BOOLEAN NOT NULL DEFAULT FALSE,
 unlimited BOOLEAN NOT NULL DEFAULT FALSE,
 data JSON NOT NULL,
 version INT NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX(supplier_id,created_at),
 INDEX(product_id),
 FOREIGN KEY(supplier_id) REFERENCES jingo_suppliers(id),
 FOREIGN KEY(product_id) REFERENCES jingo_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
