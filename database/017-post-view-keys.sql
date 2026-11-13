CREATE TABLE IF NOT EXISTS jingo_post_view_keys (
 post_id CHAR(36) CHARACTER SET ascii NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
 view_day CHAR(10) CHARACTER SET ascii NOT NULL,
 PRIMARY KEY(post_id,fingerprint,view_day),
 KEY jingo_post_view_keys_day(view_day),
 FOREIGN KEY(post_id) REFERENCES jingo_posts(id)
) ENGINE=InnoDB;
