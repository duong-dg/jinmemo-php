CREATE TABLE IF NOT EXISTS jingo_pos_orders (
 order_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 counter_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 admin_id TEXT NOT NULL,
 INDEX(counter_id),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id),
 FOREIGN KEY(counter_id) REFERENCES jingo_counters(id)
) ENGINE=InnoDB;
