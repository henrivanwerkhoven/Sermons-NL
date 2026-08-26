CREATE TABLE {prefix}sermons_nl_youtube (
  id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id int(10) UNSIGNED NULL,
  video_id char(11) DEFAULT '' NOT NULL,
  dt_planned datetime NULL,
  dt_actual datetime NULL,
  dt_end datetime NULL,
  title varchar(255) DEFAULT '' NOT NULL,
  description text NOT NULL,
  planned tinyint(1) DEFAULT 0 NOT NULL,
  live tinyint(1) DEFAULT 0 NOT NULL,
  PRIMARY KEY  (id)
  ) {charset_collate};
