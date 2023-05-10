<?php

ignore_user_abort(true);
@set_time_limit(0);

$override = dirname(__FILE__) . "/reporting.override.php";
if (file_exists($override)) {
    include($override);
}

if (!defined("ERROR_TYPES_IGNORED")) {
    define("ERROR_TYPES_IGNORED", array(E_NOTICE, E_WARNING, E_STRICT, E_DEPRECATED));
}

$level = E_ALL;
foreach(ERROR_TYPES_IGNORED as $error_type) {
    $level = $level & ~$error_type;
}

error_reporting($level);
if (empty($ignore_register)) {
    register_shutdown_function(['\LundiMatin\EDI\LmbEdi\LmbEdi', 'edi_exit']);
}
set_error_handler(['\LundiMatin\EDI\LmbEdi\LmbEdi', 'edi_error']);
set_exception_handler(['\LundiMatin\EDI\LmbEdi\LmbEdi', 'edi_exception']);
