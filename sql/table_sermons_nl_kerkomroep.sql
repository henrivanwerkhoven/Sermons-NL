CREATE TABLE {prefix}sermons_nl_kerkomroep (
  id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id int(10) UNSIGNED NULL,
  dt datetime NULL,
  duration smallint(5) UNSIGNED DEFAULT NULL,
  pastor varchar(255) NULL,
  theme varchar(255) NULL,
  scripture varchar(255) NULL,
  description text(65535) NOT NULL,
  audio_url varchar(255) NULL,
  audio_mimetype varchar(255) NULL,
  video_url varchar(255) NULL,
  video_mimetype varchar(255) NULL,
  live tinyint(1) DEFAULT 0 NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dt  (dt)
  ) {charset_collate};
