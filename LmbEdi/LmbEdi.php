<?php

/*
  Plugin Name: EDI LMB
  Plugin URI:  http://www.lundimatin.fr
  Description: Echange de données entre Magento 2 et LMB
  Version:     1.1
  Author:      LundiMatin S.A.S.
  Author URI:  http://www.lundimatin.fr
  Text Domain: lmbedi
 */

namespace LundiMatin\EDI\LmbEdi;

use \PDO;

require_once __DIR__ . '/classes/config.php';

//de Mg2 vers LMB
define('EVENEMENTS_POSSIBLES', serialize(
                [
                    'create_order',
                    'create_payment',
                    'create_categorie',
                    'recup_product',
                    'recup_image'
                ]
));


define('LMBEDI_PLUGIN_FILE', __FILE__);
define('LMBEDI_PLUGIN_BASENAME', basename(__FILE__));
define('LMBEDI_PLUGIN_DIR', dirname(__FILE__) . '/../'); //$directory::APP 
define('LMBEDI_LOG_DIR', __DIR__ . '/../../../../../var/log/LmbEdi/'); /* BP . '/' . $directory::getDefaultConfig()['log']['path'] . '/LmbEdi/'); //define('LMBEDI_LOG_DIR', BP . '/' . $directory::getDefaultConfig()['log']['path'] . '/LmbEdi/'); */

final class LmbEdi {

    /**
     * @var string
     */
    public $version = '1.0';
    protected static $_instance = null;
    private static $bdd;

    /**
     * /etc/env.php , Mg2 configuration
     * @var type 
     */
    protected static $mg2env;

    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Mg2 Constructor.
     */
    public function __construct() {
        $this->includes();
        $this->define_constants();
    }

    public function create_order($id_order) {
        $this->create_event('create_order', $id_order);
    }

    public function create_payment($id_order) {
        $this->create_event('create_payment', $id_order);
    }

    public function create_event($event_name, $id_order = null) {
        //uniquement ces événements sont pour le moment gérés via cette fonction:
        if (!in_array($event_name, unserialize(EVENEMENTS_POSSIBLES))) {
            trace_error('edi_event', 'edi event non crée : ' . $event_name . ' non géré');
        }

        $event_name_upper_case = strtoupper($event_name);

        trace('edi_event', '***************DEBUT $event_name_upper_case ' . ($id_order ? '[' . $id_order . ']' : '') . '****************');

        require_once(__DIR__ . '/classes/event.php');
        require_once(__DIR__ . '/classes/edi_process.php');

        \lmbedi_event::create($event_name, $id_order);

        trace('edi_event', '***************FIN $event_name_upper_case ' . ($id_order ? ' [' . $id_order . ']' : '') . '****************');
    }

    /**
     * Define Mg2 Constants
     */
    private function define_constants() {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        /*$directory = $objectManager->get('\Magento\Framework\Filesystem\DirectoryList');*/
        $storeManager = $objectManager->get('\Magento\Store\Model\StoreManagerInterface');
        define('LMBEDI_PLUGIN_URL', $storeManager->getStore()->getBaseUrl());

        if (!is_dir(LMBEDI_LOG_DIR)) {
            mkdir(LMBEDI_LOG_DIR, 0755, true);
        }
    }

    /**
     * What type of request is this?
     * string $type ajax, frontend or admin
     * @return bool
     */
    private function is_request($type) {
        switch ($type) {
            case 'admin' :
//                    Magento\Model\RoleFactory:: getUserType //[[TODO]]
//                    return is_admin();
            case 'ajax' :
                return defined('DOING_AJAX');
            case 'cron' :
                return defined('DOING_CRON');
            case 'frontend' :
//                    return (!is_admin() || defined('DOING_AJAX') ) && !defined('DOING_CRON'); //[[TODO]]
        }
    }

    /**
     * Include required core files used in admin and on the frontend.
     */
    public function includes() {
        require_once __DIR__ . '/function.lib.php';
        spl_autoload_register([$this, 'autoload']);

        self::$mg2env = require \Magento\Framework\App\ObjectManager::getInstance()
                        ->get('\Magento\Framework\Filesystem\DirectoryList')
                        ->getPath('app')
                . '/etc/env.php';

        //trouver la première DB active dans la configuration
        // si aucune n'a l'attribut 'active', on prend la dernière DB de la liste
        foreach (self::$mg2env['db']['connection'] as $db) {
            if (@$db['active'])
                break;
        }
        define('DB_HOST', $db['host']);
        define('DB_NAME', $db['dbname']);
        define('DB_USER', $db['username']);
        define('DB_PASSWORD', $db['password']);

        if ($this->is_request('admin')) {
            
        }

        if ($this->is_request('ajax')) {
            
        }
    }

    public function autoload($className) {
        if (class_exists($className))
            return true;

        if ($className == 'Recepteur' || $className == 'Emeteur') {
            $dir = '/spec/';
        } else {
            $dir = '/classes/';
        }

        $include_path = LMBEDI_PLUGIN_DIR . '/LmbEdi/' . $dir . substr($className, strlen('lmbedi_')) . '.php';

        if (is_file($include_path)) {
            require_once $include_path;
        }
    }

    public function setup_environment() {
        // NGINX Proxy
        if (!isset($_SERVER['REMOTE_ADDR']) && isset($_SERVER['HTTP_REMOTE_ADDR'])) {
            $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_REMOTE_ADDR'];
        }

        if (!isset($_SERVER['HTTPS'])) {
            if (!empty($_SERVER['HTTP_HTTPS'])) {
                $_SERVER['HTTPS'] = $_SERVER['HTTP_HTTPS'];
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
                $_SERVER['HTTPS'] = '1';
            }
        }
    }

    /**
     * Get the plugin url.
     * @return string
     */
    public function plugin_url() {
        return LMBEDI_PLUGIN_URL;
    }

    /**
     * Get the plugin path.
     * @return string
     */
    public function plugin_path() {
        return LMBEDI_PLUGIN_FILE;
    }

    /**
     * Return the MG2 API URL for a given request
     *
     * @param string $request
     * @param mixed $ssl (default: null)
     * @return string
     */
    public function api_request_url($request, $ssl = null) {
        if (is_null($ssl)) {
            $scheme = parse_url(home_url(), PHP_URL_SCHEME);
        } elseif ($ssl) {
            $scheme = 'https';
        } else {
            $scheme = 'http';
        }

        if (strstr(get_option('permalink_structure'), '/index.php/')) {
            $api_request_url = trailingslashit(home_url('/index.php/lmbedi-api/' . $request, $scheme));
        } elseif (get_option('permalink_structure')) {
            $api_request_url = trailingslashit(home_url('/lmbedi-api/' . $request, $scheme));
        } else {
            $api_request_url = add_query_arg('lmbedi-api', $request, trailingslashit(home_url('', $scheme)));
        }

        return esc_url_raw($api_request_url);
    }

    /**
     * 
     * @return PDO
     */
    public static function getBDD() {
        self::instance(); //initiliser class/objet si non encore initialisé
        /* global $mg2db;
          return $mg2db; */
        if (!self::$bdd) {
            if (strpos(DB_HOST, '.sock') !== false) {
                $ex = explode(':', DB_HOST);
                $host = 'unix_socket=' . $ex[1];
            } else {
                $host = 'host=' . DB_HOST;
            }
            self::$bdd = new PDO('mysql:' . $host . '; dbname=' . DB_NAME . '', DB_USER, DB_PASSWORD); //, array(PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . DB_CHARSET . '')
//            self::$bdd->exec('SET NAMES "' . DB_CHARSET . '"');
            self::$bdd->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            self::$bdd->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return self::$bdd;
    }

    /**
     * 
     * @return string
     */
    public static function getRacineURL(): string {
        return \lmbedi_config::RACINE_URL();
    }

    /**
     * 
     * @return string
     */
    public static function getDistantURL(): string {
        return \lmbedi_config::SITE_DISTANT();
    }

    /**
     * 
     * @return string
     */
    public static function getPrefix(): string {
        return (string) self::$mg2env['db']['table_prefix'];
    }

    public static function getURLPlugin() {
        return \lmbedi_config::URL_PLUGIN();
//            return self::getRacineURL().'/wp-content/plugins/lmbedi/';
    }

    static function edi_error($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno))
            return true;

        switch ($errno) {
            case E_WARNING:
            case E_USER_WARNING:
                $type = 'ALERTE';
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
                $type = 'NOTICE';
                break;
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                $type = 'DEPRECIATION';
                break;
            case E_STRICT:
                $type = 'ERREUR STRICTE';
                break;
            default:
                $type = 'ERREUR';
                break;
        }
        $erreur = $type . ' : ' . $errstr;


        throw new \ErrorException($erreur, $errno, 0, $errfile, $errline, null);
    }

    static function edi_exception($except) {
        $msg = 'Exception non capturée [' . $except->getCode() . '] : ' . $except->getMessage() . '<br />\n';
        $msg .= 'Erreur sur la ligne ' . $except->getLine() . ' dans le fichier ' . $except->getFile();
        trace_error('PHP', $msg);

        if (substr(phpversion(), 0, 3) != '7.0') {//seulement utiliser debug_print_backtrace si utilisation d'autre version que 7.0.x - https://bugs.php.net/bug.php?id=73916
            ob_start();
            debug_print_backtrace();
            $debug = ob_get_contents();
            ob_end_clean();
            trace_error('PHP', $debug);
        }
        exit();
    }

    static function edi_exit() {
        $error = error_get_last();
        if ($error !== NULL && !empty($error["type"]) && (!defined("ERROR_TYPES_IGNORED") || !in_array($error["type"], ERROR_TYPES_IGNORED))) {
            $info = '[CRITIQUE] fichier:' . $error['file'] . ' | ligne:' . $error['line'] . ' | msg:' . $error['message'] . PHP_EOL;
            trace_error('PHP', $info);
        }

        $buffer = ob_get_contents();
        if ($buffer) {
            trace_error('PHP', 'EXIT : ' . trim($buffer));
            ob_end_clean();
        }
    }

}

function LMBEDI() {
    return LmbEdi::instance();
}

LmbEdi::instance();


