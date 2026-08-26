CREATE TABLE {prefix}sermons_nl_kerktijdenpastors (
  id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  pastor varchar(255) default '' NOT NULL,
  town varchar(255) default '' NOT NULL,
  PRIMARY KEY  (id)
  ) {charset_collate};
