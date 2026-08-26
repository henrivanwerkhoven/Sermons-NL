CREATE TABLE {prefix}sermons_nl_kerktijden (
  id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id int(10) UNSIGNED NULL,
  dt datetime DEFAULT '1970-01-01 01:00:00' NOT NULL,
  sermontype varchar(255) DEFAULT '' NOT NULL,
  pastor_id int(10) UNSIGNED NULL,
  cancelled tinyint(1) UNSIGNED DEFAULT 0 NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dt (dt)
  ) {charset_collate};
