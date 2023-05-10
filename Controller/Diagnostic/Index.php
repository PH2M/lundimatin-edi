<?php

namespace LundiMatin\EDI\Controller\Diagnostic;

use LundiMatin\EDI\Model\LmbAction;

class Index extends LmbAction {

    public function doAction($params) {
        $GLOBALS['alert_time'] = true;

        $compteur = 0;
        $limite = 5;

        $appel_curl = \LundiMatin\EDI\LmbEdi\new_process("Diagnostic", "diagnostic.pid");
        while (!$appel_curl && $compteur < $limite) {
            $compteur++;
            echo "Echec de tentative $compteur d'appel cURL<br/>";
            sleep(2);
            $appel_curl = \LundiMatin\EDI\LmbEdi\new_process("Diagnostic", "diagnostic.pid");
        }

        if ($appel_curl) {
            $etat = "OK";
            if (!empty($appel_curl["errno"])) {
                if ($appel_curl["errno"]) {
                    $etat = "en timeout";
                } else {
                    $etat = "en erreur " . $appel_curl["errno"];
                }
            }
            echo "Appel cURL $etat<br/>";
            echo "Réponse serveur => <br/>";
            echo "<pre>" . print_r($appel_curl, true) . "</pre><br/>";
        } else {
            echo "Echec de l'appel cURL<br/>";
        }

        die();
    }
}
