CREATE TABLE IF NOT EXISTS jingo_transport_jobs (
 order_id CHAR(36) CHARACTER SET ascii PRIMARY KEY,
 visit_date CHAR(10) CHARACTER SET ascii NOT NULL,
 status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'PENDING',
 data JSON NOT NULL,
 version INT NOT NULL DEFAULT 1,
 INDEX transport_visit(visit_date),
 FOREIGN KEY(order_id) REFERENCES jingo_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
