<?php

namespace LundiMatin\EDI\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;
use LundiMatin\EDI\LmbEdi;

class InstallSchema implements InstallSchemaInterface {

    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context) {
        $installer = $setup;
        $installer->startSetup();

        // Get edi_events_queue table
        $tableName = $installer->getTable('edi_events_queue');
        // Check if the table already exists
        if ($installer->getConnection()->isTableExists($tableName) != true) {
            // Create tutorial_simplenews table
            $conn = $installer->getConnection();

            $conn->query('CREATE TABLE `' . LmbEdi\LmbEdi::getPrefix() . 'edi_events_queue` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `type_event` varchar(32) NOT NULL,
  `param` varchar(255) NOT NULL,
  `date` datetime NOT NULL,
  `etat` tinyint(4) NOT NULL,
   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;');

            $conn->query('CREATE TABLE `' . LmbEdi\LmbEdi::getPrefix() . 'edi_messages_envoi_queue` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sig` varchar(32) NOT NULL,
  `chaine` mediumblob NOT NULL,
  `date` datetime NOT NULL,
  `etat` tinyint(4) NOT NULL,
   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;');
            
            $conn->query('CREATE TABLE `' . LmbEdi\LmbEdi::getPrefix() . 'edi_messages_recu_queue` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sig` varchar(32) NOT NULL,
  `chaine` mediumblob NOT NULL,
  `date` datetime NOT NULL,
  `etat` tinyint(4) NOT NULL,
   PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;');
            
            $conn->query('CREATE TABLE `' . LmbEdi\LmbEdi::getPrefix() . 'edi_pid` (
  `name` varchar(15) COLLATE utf8_unicode_ci NOT NULL,
  `etat` varchar(15) COLLATE utf8_unicode_ci NOT NULL,
  `sys_pid` int(11) DEFAULT NULL,
   PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;');
        }
        $installer->endSetup();
    }

}
