DROP TABLE IF EXISTS `wp_syncs`;
CREATE TABLE `wp_syncs` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `source_id` int(11) unsigned NOT NULL,
  `source_site_id` int(11) unsigned NOT NULL,
  `clone_id` int(11) unsigned NOT NULL,
  `clone_site_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;

