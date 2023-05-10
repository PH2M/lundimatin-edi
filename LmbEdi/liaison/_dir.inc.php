<?php

//            define('LMBEDI_PLUGIN_FILE', __FILE__);
//            define('LMBEDI_PLUGIN_BASENAME', plugin_basename(__FILE__));
//            define('LMBEDI_PLUGIN_DIR', plugin_dir_path(__FILE__) . "/");
//            define('LMBEDI_LOG_DIR', $upload_dir['basedir'] . '/mg2-logs/lmbedi/');
            

$DIR = $GLOBALS['DIR'] = realpath(dirname(__FILE__).'/../../../')."/";//GENERAL PLUGIN path
echo('should be the general plugin path'.$DIR);
LmbEdi\trace('should be the general plugin path'.$DIR);

$DIR_MODULE = $GLOBALS['DIR_MODULE'] = $DIR."plugins/lmbedi/";

$DIR_CLASS = $GLOBALS['DIR_CLASS'] = $DIR."classes/";
defined('__DIR__') or define('__DIR__', dirname(__FILE__));
