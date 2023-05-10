<?php

class lmbedi_config {

    public static $ID_ORDER_STATE_CONFIRM = " '1', '10' ";  // id des order_state pour lequel il ne faut pas faire un retour vers LMB
    public static $VERSION = "2.102";

    private static function loadConf() {
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->formatOutput = true;
        if (!is_file(LMBEDI_PLUGIN_DIR . '/LmbEdi/config_module.xml')) {
            touch(LMBEDI_PLUGIN_DIR . '/LmbEdi/config_module.xml');
            $conf = $doc->createElement('config');
            //$doc->firstChild->appendChild($conf);
            $doc->appendChild($conf);
            self::saveConf($doc);
        }
        $doc->load(LMBEDI_PLUGIN_DIR . '/LmbEdi/config_module.xml');
        $conf = $doc->getElementsByTagName('config');
        if (empty($conf) || $conf->length < 1) {
            $conf = $doc->createElement('config');
            //$doc->firstChild->appendChild($conf);
            $doc->appendChild($conf);
        }
        return $doc->saveXML();
    }

    private static function saveConf($doc) {
        $doc->save(LMBEDI_PLUGIN_DIR . '/LmbEdi/config_module.xml');
    }

	public static function GET_PARAM($name) {
        try {
            $doc = new DOMDocument();
            $doc->loadXML(self::loadConf());
            $conf = $doc->getElementsByTagName('config')->item(0);
            return $conf->getAttribute($name);
        } catch (Exception $e) {
            return false;
        }
    }
	
    public static function ID_CANAL($id = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($id) {
            $conf->setAttribute('idcanal', $id);
            self::saveConf($doc);
        }
        return $conf->getAttribute('idcanal');
    }

    public static function ID_LANG($id = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($id) {
            $conf->setAttribute('idlang', $id);
            self::saveConf($doc);
        }
        $lang = $conf->getAttribute('idlang');
        if (!$lang) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $store = $objectManager->get('Magento\Store\Api\Data\StoreInterface');
            $lang = $store->getLocaleCode(); //Langue fr par défaut
        }
        return $lang;
    }

    public static function CODE_CONNECTION($code = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($code) {
            $conf->setAttribute('code', $code);
            self::saveConf($doc);
        }
        return $conf->getAttribute('code');
    }

    public static function MAIL_ALERT($mail = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($mail) {
            $conf->setAttribute('mailalert', $mail);
            self::saveConf($doc);
        }
        $maillu = $conf->getAttribute('mailalert');
        if (empty($maillu))
            $maillu = "alerte_edi@lundimatin.fr";
        //si on ne veut pas envoyer d'email, mettre un espace dans le champs XML
        if (empty(trim(empty($maillu))))
            return false;
        return $maillu;
    }

    public static function LAST_ALERT($time = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($time) {
            $conf->setAttribute('lastalert', $time);
            self::saveConf($doc);
        }
        $alertlu = $conf->getAttribute('lastalert');
        if (empty($alertlu))
            $alertlu = 1;
        return $alertlu;
    }

    public static function HTTP_AUTH($http_auth = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($http_auth) {
            $conf->setAttribute('httpauth', $http_auth);
            self::saveConf($doc);
        }
        $http_authlu = $conf->getAttribute('httpauth');
        return $http_authlu;
    }

    public static function DEBUG_MODE($debug = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($debug) {
            $conf->setAttribute('debugmode', $debug);
            self::saveConf($doc);
        }
        $debuglu = $conf->getAttribute('debugmode');
        return $debuglu;
    }

    public static function DELAY_QUEUE($delay = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($delay) {
            $conf->setAttribute('delay', $delay);
            self::saveConf($doc);
        }
        $delaylu = $conf->getAttribute('delay');
        if (empty($delaylu)) {
            $delaylu = 0;
        }
        return $delaylu;
    }

    public static function GZ_DATAS($gz = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($gz) {
            $conf->setAttribute('gzdatas', $gz);
            self::saveConf($doc);
        }
        $debuglu = $conf->getAttribute('gzdatas');
        return $debuglu;
    }

    public static function GEST_PROMOS($gest = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($gest !== false) {
            $conf->setAttribute('gest_promos', $gest);
            self::saveConf($doc);
        }
        $debuglu = $conf->getAttribute('gest_promos');
        return $debuglu;
    }

    public static function GEST_SEO($gest = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($gest !== false) {
            $conf->setAttribute('gest_seo', $gest);
            self::saveConf($doc);
        }
        $debuglu = $conf->getAttribute('gest_seo');
        return $debuglu;
    }

    public static function FUNCTIONS_TO_LOG($gest = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($gest) {
            $conf->setAttribute('func_to_log', $gest);
            self::saveConf($doc);
        }
        $debuglu = $conf->getAttribute('func_to_log');
        return $debuglu;
    }

    public static function SEND_REGISTERED($gest = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($gest) {
            $conf->setAttribute('send_registered', $gest);
            self::saveConf($doc);
        }
        $send_registered = $conf->getAttribute('send_registered');
        return $send_registered;
    }

    public static function AFF_PRIXBARRE($gest = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($gest) {
            $conf->setAttribute('aff_prixbarre', $gest);
            self::saveConf($doc);
        }
        $aff_prixbarre = $conf->getAttribute('aff_prixbarre');
        return $aff_prixbarre;
    }

    public static function RACINE_URL($url = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($url) {
            $conf->setAttribute('RACINE_URL', $url);
            self::saveConf($doc);
        }
        return $conf->getAttribute('RACINE_URL');
    }

    public static function URL_PLUGIN($url = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($url) {
            $conf->setAttribute('URL_PLUGIN', $url);
            self::saveConf($doc);
        }
        return $conf->getAttribute('URL_PLUGIN');
    }

    public static function SITE_DISTANT($url = false) {
        $doc = new DOMDocument();
        $doc->loadXML(self::loadConf());
        $conf = $doc->getElementsByTagName('config')->item(0);
        if ($url) {
            $conf->setAttribute('SITE_DISTANT', $url);
            self::saveConf($doc);
        }
        return $conf->getAttribute('SITE_DISTANT');
    }

}
