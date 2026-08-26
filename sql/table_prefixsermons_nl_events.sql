CREATE TABLE {prefix}sermons_nl_events (
  id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  dt_from enum('auto','manual','kerktijden','kerkomroep','kerkdienstgemist','youtube') DEFAULT 'auto' NOT NULL,
  dt_manual datetime NULL,
  dt_min datetime NULL,
  dt_max datetime NULL,
  pastor_from enum('auto','manual','kerktijden','kerkomroep','kerkdienstgemist') DEFAULT 'auto' NOT NULL,
  pastor_manual varchar(255) NULL,
  sermontype_from enum('auto','manual','kerktijden','kerkdienstgemist') DEFAULT 'auto' NOT NULL,
  sermontype_manual varchar(255) NULL,
  description_from enum('auto','manual','kerkomroep','kerkdienstgemist','youtube') DEFAULT 'auto' NOT NULL,
  description_manual varchar(65535) NULL,
  include tinyint(1) DEFAULT 1 NOT NULL,
  protected TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY  (id)
  ) {charset_collate};
