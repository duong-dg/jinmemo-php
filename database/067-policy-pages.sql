CREATE TABLE IF NOT EXISTS jingo_policy_pages (
 policy_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 page_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 INDEX policy_page(page_id),
 FOREIGN KEY(page_id) REFERENCES jingo_pages(id)
) ENGINE=InnoDB;
