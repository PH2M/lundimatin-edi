<?php

namespace LundiMatin\EDI\LmbEdi\Spec;

//
/**
 * Abstract class de l'emmeteur et du recepteur - regroupe les constantes et méthodes communes
 */
abstract class Connecteur {

    const LMB_PRODUCT_TYPE_SIMPLE = "0"; //simple
    const LMB_PRODUCT_TYPE_VARIABLE = "2"; //parent
    const LMB_PRODUCT_TYPE_VARIATION = "1"; //enfant
    
    const LMB_CORRESPONDANCE_CODE_ARTICLE_VARIATION = 8;
    const LMB_CORRESPONDANCE_CODE_ARTICLE_VARIABLE = 11;
    const LMB_CORRESPONDANCE_CODE_ARTICLE_SIMPLE = 1;
    
    protected function shopInit() {
        require_once __DIR__ . '/../../../../autoload.php';
    }

    protected function getObjectManager() {
        return \Magento\Framework\App\ObjectManager::getInstance();
    }
}