CREATE TABLE IF NOT EXISTS jingo_inventory_configs (
 product_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 enabled BOOLEAN NOT NULL DEFAULT FALSE,
 version INT NOT NULL DEFAULT 1,
 FOREIGN KEY(product_id) REFERENCES jingo_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_inventory_buckets (
 product_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 variant_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 visit_date CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 slot_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 capacity INT NOT NULL,
 low_stock_threshold INT NOT NULL DEFAULT 5,
 PRIMARY KEY(product_id,variant_id,visit_date,slot_id),
 FOREIGN KEY(product_id) REFERENCES jingo_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS jingo_inventory_allocations (
 order_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 product_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 variant_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 visit_date CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 slot_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 quantity INT NOT NULL,
 released BOOLEAN NOT NULL DEFAULT FALSE,
 version INT NOT NULL DEFAULT 1,
 INDEX(product_id,visit_date),
 FOREIGN KEY(product_id) REFERENCES jingo_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
