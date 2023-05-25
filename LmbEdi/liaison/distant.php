<?php

use LundiMatin\EDI\LmbEdi;

/* * *****************************************************************************
 * Page d'entrEe de toutes les communications venant de LMB.
 */

require_once __DIR__ . '/../LmbEdi.php';
require_once __DIR__ . '/../../../../autoload.php';

LmbEdi\LmbEdi::instance();

$GLOBALS['log_file'] = 'transaction_pmrq.log';

$ignore_register = true;
include(dirname(__DIR__) . "/reporting.inc.php");

if ($serial_code != \lmbedi_config::CODE_CONNECTION()) {
    LmbEdi\logme('ERREUR DE CODE DE CONNEXION !');
    die('ERREUR DE CODE DE CONNEXION !');
}

require_once('_receiver.inc.php');

$bdd = null;

