<?php

namespace LundiMatin\EDI\Controller\Decrypt;
use LundiMatin\EDI\Model\LmbAction;
use \LundiMatin\EDI\LmbEdi\LmbEdi;

class Index extends LmbAction {

	protected $retour = array();
	
    public function doAction($params) {
		
        if (isset($_POST['messages_ids'])) {
            LmbEdi::instance();
            
            $this->retour["decryptage"] = "<b>Décryptage de " . $_POST['messages_ids'] . "</b><br/><br/>";
            $ids = explode(";", $_POST['messages_ids']);
            foreach ($ids as $id) {
                $id = trim($id);
                $message = new \lmbedi_message_recu($id);
                
                $retour = "#$id : ";
                $id_verif = $message->getId();
                if (empty($id_verif)) {
                    $retour .= "Message inconnu";
                }
                else {
                    $fonction = $message->getNom_fonction();
                    $params = $message->getParams();
                    if (empty($fonction) || empty($params)) {
                        $retour .= "Échec du décryptage de ".$message->getChaine();
                    }
                    else {
                        if (phpversion() >= "5.3") {
                            $param_temp = json_encode($params, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP |  JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                        }
                        else {
                            $param_temp = json_encode($this->params);
                        }
                        
                        $retour .= $fonction." => <pre>".str_replace("<", "&lt", $param_temp)."</pre>";
                    }
                }
                
                $this->retour["decryptage"] .= $retour."<br/>";
            }
        }

        include __DIR__ . '/../../view/frontend/decrypt.php';
    }
}