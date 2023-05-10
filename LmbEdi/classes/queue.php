<?php

use LundiMatin\EDI\LmbEdi;

date_default_timezone_set("Europe/Paris");

class lmbedi_queue {

    const OK_CODE = 0;
    const TRAITE_CODE = 1;
    const IGNORE_CODE = 2;
    const ERROR_CODE = 9;

    static function start_queue($class, $max_relance = 0) {

        $pid = new lmbedi_pid(call_user_func(array($class, "getProcess")));
        if ($pid->issetPid()) {
            return;
        }
        $pid->majDatePid();
        //Si plusieurs process démarrent exactement en même temps, tous vons mettre à jour le PID, mais un seul sera "l'élu" !
        //On attends 1s que tout le monde ai fini d'ecrire.
        sleep(1);
        if ($pid->getSysPid() != getmypid()) {
            exit("Tentative d'exécution de process simultané !!! (" . $pid->getName() . ")");
        }
        LmbEdi\logme("***********************************************");
        LmbEdi\logme("Début de traitement de la file d'attente");

        $nb_execution = 0;
        $start_time = time();
        $wait = true;
        $return = false;
        $restart = false;
        try {
            while (!$return) {
                if (lmbedi_pid::stopIsset()) {
                    LmbEdi\logme("Arrét forcé de la file par fichier pid/stop !");
                    break;
                }
                if ($pid->getSysPid() != getmypid()) {
                        exit("Tentative d'exécution de process simultané manuel !!! (" . $pid->getName() . ")");
                }
                $pid->majDatePid();
                $event = call_user_func(array($class, "loadNext"));
                if ($event) {
                    if ($event->getEtat() == self::ERROR_CODE) {
                        $return = true;
                        LmbEdi\logme("Il y a une erreur dans la file $class sur l'event : " . $event->getId());
                        LmbEdi\logme(print_r($event, 1));
                        self::stop_queue($class);
                    } else if ($event->getEtat() == self::OK_CODE) {
                        LmbEdi\logme("Traitement du message " . $event->getId());
                        $wait = true;
                        if ($event->exec() === true) {
                            LmbEdi\logme("OK");
                            $event->remove();
                            $nb_execution = 0;
                        } else {
                            LmbEdi\logme("Il y a une erreur de traitement");
                            if ($nb_execution < $max_relance) {
                                $nb_execution++;
                                LmbEdi\logme("Attente 5s");
                                sleep(5);
                                LmbEdi\logme("Nouvelle tentative après échec $nb_execution...");
                            } else {
                                $return = true;
                                self::stop_queue($class);
                            }
                        }
                    } else {
                        $return = true;
                        LmbEdi\logme("Il y a un état inconnu dans la file $class sur l'event : " . $event->getId());
                        self::stop_queue($class);
                    }
                    $event = null;
                } else {
                    LmbEdi\trace($class, "Pas de message à traiter");
                    if ($wait) {
                        LmbEdi\trace($class, "Attente de nouvel évenement 5s...");
                        sleep(5);
                        $wait = false;
                    } else
                        $return = true;
                }
                sleep(\lmbedi_config::DELAY_QUEUE());
                if ((time() - $start_time) > min(55, (ini_get("max_execution_time") ? ini_get("max_execution_time") - 5 : 60))) //Temps d'execution max 55s
                    $restart = $return = true;
            }
        } catch (Exception $e) {
            LmbEdi\logme("EXCEPTION :" . $e->getMessage());
            self::stop_queue($class);
            throw $e;
        }

        self::cleanQueues();
        $pid->unsetPid();
        LmbEdi\logme("Fin de traitement de la file d'attente!\n****************************************************");

        if ($restart) {
            $process = call_user_func(array($class, "getProcess"));
            $fin_url = "";
			if($process == 'pmeq.pid'){
				$fin_url = 'envoi';
			}else if($process == 'pmrq.pid'){
				$fin_url = 'reception';
			}else{
				$fin_url = 'event';
			}
            
			LmbEdi\trace('process', "restart $fin_url / $process");
            LmbEdi\new_process($fin_url, $process);
        }
    }

    static private function cleanQueues() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        //Purge des messages traités (complètements ou ignorés) inutiles au delà de 4 jours
        $bdd->exec("DELETE FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_events_queue 
                    WHERE DATE_SUB(NOW(), INTERVAL 4 DAY) > date and (etat = " . self::TRAITE_CODE . " OR etat = " . self::IGNORE_CODE . ");");
        $bdd->exec("DELETE FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_envoi_queue 
                    WHERE DATE_SUB(NOW(), INTERVAL 4 DAY) > date and (etat = " . self::TRAITE_CODE . " OR etat = " . self::IGNORE_CODE . ");");
        $bdd->exec("DELETE FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_recu_queue 
                    WHERE DATE_SUB(NOW(), INTERVAL 4 DAY) > date and (etat = " . self::TRAITE_CODE . " OR etat = " . self::IGNORE_CODE . ");");
    }

    static private function stop_queue($class) {
        LmbEdi\logme("ERREUR ! ARRET DE LA FILE !");
        LmbEdi\trace("process", "Fin du process avec des erreurs !");
        $vars = get_class_vars($class);
        $pid = new lmbedi_pid($vars['process']);
        $pid->setErrorPid();
    }

}
