CREATE TABLE IF NOT EXISTS jingo_partner_orders (
 order_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 partner_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 INDEX(partner_id),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id),
 FOREIGN KEY(partner_id) REFERENCES jingo_partners(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
