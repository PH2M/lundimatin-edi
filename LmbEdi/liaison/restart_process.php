<?php

use LundiMatin\EDI\LmbEdi;

$GLOBALS['log_file'] = "transaction_pmrq.log";

include(dirname(__DIR__) . "/reporting.inc.php");

// si code != ==> log(erreur);exit("code diff?rent");
if($_REQUEST['serial_code'] == \lmbedi_config::CODE_CONNECTION())
    if(!empty($_REQUEST['process'])){
		switch($_REQUEST['process']){
			case "events":
				$pid = new lmbedi_pid(event::$process);
				$pid->unsetPid();
				if(!empty($_REQUEST['unlock'])){
					$query = "UPDATE ".LmbEdi::getPrefix()."edi_events_queue SET etat='0' WHERE etat='9'";
					$bdd->exec($query);
				}
				if(!empty($_REQUEST['ignore_last'])){
					$query = "UPDATE ".LmbEdi::getPrefix()."edi_events_queue SET etat='2' WHERE id IN (
						SELECT MAX(id) FROM ".LmbEdi::getPrefix()."edi_events_queue WHERE etat='0')";
					$bdd->exec($query);
				}
				LmbEdi\new_process("event",event::$process);
				break;
			case "messages_recu":
				$pid = new lmbedi_pid(message_recu::$process);
				$pid->unsetPid();
				if(!empty($_REQUEST['unlock'])){
					$query = "UPDATE ".LmbEdi::getPrefix()."edi_messages_recu_queue SET etat='0' WHERE etat='9'";
					$bdd->exec($query);
				}
				if(!empty($_REQUEST['ignore_last'])){
					$query = "UPDATE ".LmbEdi::getPrefix()."edi_messages_recu_queue SET etat='2' WHERE id IN (
						SELECT MAX(id) FROM ".LmbEdi::getPrefix()."edi_messages_recu_queue WHERE etat='0')";
					$bdd->exec($query);
				}
				LmbEdi\new_process("reception",message_recu::$process);
				break;
			case "messages_envoi":
				$pid = new lmbedi_pid(message_envoi::$process);
				$pid->unsetPid();
				if(!empty($_REQUEST['unlock'])){
					$query = "UPDATE ".LmbEdi::getPrefix()."edi_messages_envoi_queue SET etat='0' WHERE etat='9'";
					$bdd->exec($query);
				}
				if(!empty($_REQUEST['ignore_last'])){
					$query = "UPDATE ".LmbEdi::getPrefix()."edi_messages_envoi_queue SET etat='2' WHERE id IN (
						SELECT MAX(id) FROM ".LmbEdi::getPrefix()."edi_messages_envoi_queue WHERE etat='0')";
					$bdd->exec($query);
				}
				LmbEdi\new_process("envoi",message_envoi::$process);
				break;
		}
	}
else {
    LmbEdi\logme("ERREUR DE CODE DE CONNEXION !");
    exit();
}

$bdd = null;

?>