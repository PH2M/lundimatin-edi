<?php

require_once __DIR__ . '/../../../../../autoload.php';

ob_start();

include(dirname(__DIR__) . "/reporting.inc.php");

$GLOBALS['log_file'] = 'mess_envoi.log';

lmbedi_queue::start_queue('lmbedi_message_envoi', 5);
