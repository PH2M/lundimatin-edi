<?php

namespace LundiMatin\EDI\LmbEdi;

/**
 * Mg2 Uninstall
 *
 * Uninstalling Mg2 deletes user roles, pages, tables, and options.
 * 
 * @author      WooThemes
 * @category    Core
 * @package     Mg2/Uninstaller
 * @version     2.3.0
 * 
 * //DROP TABLE IF EXISTS 
 */

/**
 * https://magento.stackexchange.com/questions/124587/is-it-possible-to-delete-the-table-from-the-database-when-uninstall-the-module
You can use the UninstallInterface of your module to drop the tables during uninstall process:
app/code/Vendor/Module/Setup/Uninstall.php
 * 
But this script works only when module has been installed using the Composer:
Hence, it is recommended that for modules NOT installed via composer, manual clean up of the database and filesystem is necessary. In module enable/disable, the code is never removed from the filesystem, so that it can be used if required at a later time. Hence, it is not removed from the setup_module table.
 */

