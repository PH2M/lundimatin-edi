<?php

use LundiMatin\EDI\LmbEdi;

/* * *****************************************************************************
 * Page d'écoute SOAP, appelée par le point d'entrée des communications LMB
 * (distant.php)
 */

if (realpath(__FILE__) == realpath($_SERVER['SCRIPT_FILENAME'])) {
    // tell people trying to access this file directly goodbye...
    exit('This file can not be accessed directly...');
}

$options = array(
    'uri' => 'urn:Liaison'
);
$server = new SoapServer(NULL, $options);
$server->addFunction("Receiver");

function Receiver($id, $str) {
    $bdd = LmbEdi\LmbEdi::getBDD();

    if (!is_object($bdd)) {
        return "connexion bdd nok";
    }

    if ("" == $str) {
        $bdd = null;
        return "message vide";
    }

    LmbEdi\logme("********************** RECEPTION D'UN MESSAGE **********************");


    //test si le message a déjà été recu
    /* @fixme à perfectionner... pb si archivage puis reactivation article par exemple... (recréation ne se fait pas) */
    $query = "SELECT sig FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_recu_queue
                    WHERE sig='$id'";
    $res = $bdd->query($query);
    if (is_object($res) && $res->fetchObject()) {
        //?$res->closeCursor();
        LmbEdi\logme("Le message avait déja été recu");
        return true;
    }

    if (is_object($res)) {
        $res->closeCursor();
    }

    //sauvegarde du message recu
    $query = "INSERT INTO " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_recu_queue 
                    (chaine,date,etat,sig)
                    VALUES(" . $bdd->quote($str) . ",  NOW(), " . lmbedi_queue::OK_CODE . ",'$id');";
    LmbEdi\logme($query);

    if (($bdd->exec($query)) != 1) {
        $error = "insertion bdd erreur " . print_r($res, true) . "\n" . print_r($bdd->errorInfo(), true);
        $bdd = null;
        return $error;
    }
    $tentative = 0;
    while (!LmbEdi\new_process("reception", lmbedi_message_recu::$process)) {
        sleep(2);
		        if ($tentative++ > 3) {
                    LmbEdi\trace_error('create_process'," n'a pas pu être relancé après 3 tentatives");
					break;
                }
    }
    $bdd = null;
    return true;
}
