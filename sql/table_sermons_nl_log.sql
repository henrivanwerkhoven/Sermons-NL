CREATE TABLE {prefix}sermons_nl_log (
  id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  dt datetime NULL,
  fun varchar(255) DEFAULT '' NOT NULL,
  log varchar(255) DEFAULT '' NOT NULL,
  PRIMARY KEY  (id)
  ) {charset_collate};
