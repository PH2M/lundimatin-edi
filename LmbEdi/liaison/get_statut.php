<?php
/*******************************************************************************
 * Page d'entr?e de toutes les communications venant de LMB.
 */
require('_dir.inc.php');

$GLOBALS['log_file'] = "transaction_pmrq.log";

require_once($DIR_MODULE."config_bdd.inc.php");
require_once($DIR_MODULE.'include.inc.php');
require_once($DIR_MODULE."class/_event.class.php");
require_once($DIR_MODULE."class/_message_envoi.class.php");
require_once($DIR_MODULE."class/_message_recu.class.php");

include(dirname(__DIR__) . "/reporting.inc.php");

// si code != ==> log(erreur);exit("code diff?rent");
if($_REQUEST['serial_code'] == config::CODE_CONNECTION()){
	header("Content-type: application/xml");
	$code = $_REQUEST['serial_code'];
	$doc = new DOMDocument();
	$conf = $doc->createElement('pid_statut');
	
	$etat = 0;
	$etat = get_pid(event::$process);
	$query = "SELECT count(id) nb FROM ".LmbEdi::getPrefix()."edi_events_queue WHERE etat = $TRAITE_CODE";
	$res = $bdd->query($query);
	$c = $res->fetchObject();
	$nb_traite = ($c)?$c->nb:0;
	$query = "SELECT count(id) nb FROM ".LmbEdi::getPrefix()."edi_events_queue WHERE etat = 0";
	$res = $bdd->query($query);
	$c = $res->fetchObject();
	$nb_atraite = ($c)?$c->nb:0;
	$query = "SELECT count(id) nb FROM ".LmbEdi::getPrefix()."edi_events_queue WHERE etat = $ERROR_CODE";
	$res = $bdd->query($query);
	$c = $res->fetchObject();
	$nb_erreur = ($c)?$c->nb:0;
	$events = $doc->createElement('events');
	$events->setAttribute('code',$code);
	$events->setAttribute('nb_traite',$nb_traite);
	$events->setAttribute('nb_atraite',$nb_atraite);
	$events->setAttribute('nb_erreur',$nb_erreur);
	$events->setAttribute('etat',$etat);
	$conf->appendChild($events);
	
	$etat = 0;
	$etat = get_pid(message_recu::$process);
	$query = "SELECT count(id) nb FROM ".LmbEdi::getPrefix()."edi_messages_recu_queue WHERE etat = $TRAITE_CODE";
	$res = $bdd->query($query);
	$c = $res->fetchObject();
	$nb_traite = ($c)?$c->nb:0;
	$query = "SELECT count(id) nb FROM ".LmbEdi::getPrefix()."edi_messages_recu_queue WHERE etat = 0";
	$res = $bdd->query($query);
	$c = $res->fetchObject();
	$nb_atraite = ($c)?$c->nb:0;
	$query = "SELECT count(id) nb FROM ".LmbEdi::getPrefix()."edi_messages_recu_queue WHERE etat = $ERROR_CODE";
	$res = $bdd->query($query);
	$c = $res->fetchObject();
	$nb_erreur = ($c)?$c->nb:0;
	$mess_recu = $doc->createElement('messages_recu');
	$mess_recu->setAttribute('code',$code);
	$mess_recu->setAttribute('nb_traite',$nb_traite);
	$mess_recu->setAttribute('nb_atraite',$nb_atraite);
	$mess_recu->setAttribute('nb_erreur',$nb_erreur);
	$mess_recu->setAttribute('etat',$etat);
	$conf->appendChild($mess_recu);
	
	$etat = 0;
	$etat = get_pid(message_envoi::$process);
	$query = "SELECT count(id) nb FROM ".LmbEdi::getPrefix()."edi_messages_envoi_queue WHERE etat = $TRAITE_CODE";
	$res = $bdd->query($query);
	$c = $res->fetchObject();
	$nb_traite = ($c)?$c->nb:0;
	$query = "SELECT count(id) nb FROM ".LmbEdi::getPrefix()."edi_messages_envoi_queue WHERE etat = 0";
	$res = $bdd->query($query);
	$c = $res->fetchObject();
	$nb_atraite = ($c)?$c->nb:0;
	$query = "SELECT count(id) nb FROM ".LmbEdi::getPrefix()."edi_messages_envoi_queue WHERE etat = $ERROR_CODE";
	$res = $bdd->query($query);
	$c = $res->fetchObject();
	$nb_erreur = ($c)?$c->nb:0;
	$mess_envoi = $doc->createElement('messages_envoi');
	$mess_envoi->setAttribute('code',$code);
	$mess_envoi->setAttribute('nb_traite',$nb_traite);
	$mess_envoi->setAttribute('nb_atraite',$nb_atraite);
	$mess_envoi->setAttribute('nb_erreur',$nb_erreur);
	$mess_envoi->setAttribute('etat',$etat);
	$conf->appendChild($mess_envoi);
	$doc->appendChild($conf);

	echo $doc->saveXML();
} else {
    LmbEdi\trace_error("Auth", "ERREUR DE CODE DE CONNEXION !");
    exit("erreur auth get_statut");
}

$bdd = null;

?>