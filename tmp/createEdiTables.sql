CREATE TABLE `mg2_edi_events_queue` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type_event` varchar(32) NOT NULL,
  `param` varchar(255) NOT NULL,
  `date` datetime NOT NULL,
  `etat` tinyint(4) NOT NULL,
   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `mg2_edi_messages_envoi_queue` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sig` varchar(32) NOT NULL,
  `chaine` mediumblob NOT NULL,
  `date` datetime NOT NULL,
  `etat` tinyint(4) NOT NULL,
   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `mg2_edi_messages_recu_queue` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sig` varchar(32) NOT NULL,
  `chaine` mediumblob NOT NULL,
  `date` datetime NOT NULL,
  `etat` tinyint(4) NOT NULL,
   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE `mg2_edi_pid` (
  `name` varchar(15) COLLATE utf8_unicode_ci NOT NULL,
  `etat` varchar(15) COLLATE utf8_unicode_ci NOT NULL,
  `sys_pid` int(11) DEFAULT NULL,
   PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;