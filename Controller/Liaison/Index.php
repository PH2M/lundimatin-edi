<?php

namespace LundiMatin\EDI\Controller\Liaison;
use \LundiMatin\EDI\LmbEdi;
use LundiMatin\EDI\Model\LmbAction;
use LundiMatin\EDI\Model\Receiver;

class Index extends LmbAction {

	protected $retour = array();
    
    public function doAction($params) {
        
        if (empty($params["serial_code"])) {
            LmbEdi\logme('ERREUR DE CODE DE CONNEXION !');
            exit();
        }
        
        $serial_code = $params["serial_code"];
        include __DIR__ . '/../../LmbEdi/liaison/distant.php';
        die($server->handle());
    }
}
