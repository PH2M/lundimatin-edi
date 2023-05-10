<?php

namespace LundiMatin\EDI\Controller\Restart;
use \LundiMatin\EDI\LmbEdi;
use LundiMatin\EDI\Model\LmbAction;
use LundiMatin\EDI\Model\Receiver;

class Index extends LmbAction {

    protected static $PIDS = array(
        "peq.pid" => array(
            "table" => "edi_events_queue",
            "process" => "event"
        ),
        "pmeq.pid" => array(
            "table" => "edi_messages_envoi_queue",
            "process" => "envoi"
        ),
        "pmrq.pid" => array(
            "table" => "edi_messages_recu_queue",
            "process" => "reception"
        )
    );
    
    protected function getPidsValides() {
        return array_keys(self::$PIDS);
    }

    protected function isValidePid($pid_name) {
        return in_array($pid_name, $this->getPidsValides());
    }
    
    protected function splitEtat($etat) {
        return array(
            "statut" => (int)substr($etat, 0, 1),
            "time" => (int)substr($etat, 1)
        );
    }

    protected function pidEtatToArray($etat) {
        $split_etat = $this->splitEtat($etat);
        $statut = $split_etat["statut"];
        $time = $split_etat["time"];
        
        $tab = array();
        $tab["statut_code"] = $statut;
        $tab["last_exec"] = $time;
        $tab["date_last_exec"] = date("d-m-Y H:i:s", $time);
        
        switch($statut) {
            case 0:
                $tab["statut_text"] = "Inactif";
                break;
            case 1:
                $tab["statut_text"] = "En cours";
                $tab["delay"] = time() - $time;
                break;
            case 9:
                $tab["statut_text"] = "ERREUR";
                break;
            default:
                $tab["statut_text"] = "Inconnu";
                break;
            break;
        }
        
        return $tab;
    }
    
    public function doAction($params) {
        $this->checkSerial($params);
        $bdd = LmbEdi\LmbEdi::getBDD();
        
        $delay_warning = !empty($params["delay_warning"]) ? $params["delay_warning"] : 60;
        $delay_recheck = !empty($params["delay_recheck"]) ? $params["delay_recheck"] : 8;
        
        $retour = array();
        $retour["after"] = $this->get_process_statuts();
        
        $retour["restart"] = $this->reload_edi($delay_warning);
        
        sleep($delay_recheck);
        
        $retour["before"] = $this->get_process_statuts();
        
        $this->send($retour);
    }
    
    public function get_process_statuts() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        
        $retour = array();
        
        foreach (\lmbedi_pid::getList() as $process) {
            $retour[$process->name] = $this->pidEtatToArray($process->etat);
            $retour[$process->name]["name"] = $process->name;
            
            if ($this->isValidePid($process->name)) {
                $table = self::$PIDS[$process->name]["table"];
                
                $query_mess = "SELECT COUNT(id) nb FROM ".LmbEdi\LmbEdi::getPrefix()."$table WHERE etat = ".\lmbedi_queue::OK_CODE;
                $resultset = $bdd->query($query_mess);
                $result = $resultset->fetchObject();
                
                $retour[$process->name]["qte_mess"] = ($result ? $result->nb : 0);
                
                $query_err = "SELECT COUNT(id) nb FROM ".LmbEdi\LmbEdi::getPrefix()."$table WHERE etat = 9";
                $resultset_err = $bdd->query($query_err);
                $result_err = $resultset_err->fetchObject();
                
                $retour[$process->name]["qte_error"] = ($result_err ? $result_err->nb : 0);
            }
        }
        
        return $retour;
    }
    
    protected function reload_edi($delay_warning) {
        $bdd = LmbEdi\LmbEdi::getBDD();
        
        $retour = array();
        $retour["blocked"] = "";
        
        foreach (\lmbedi_pid::getList() as $process) {
            $name = $process->name;
            
            $split_etat = $this->splitEtat($process->etat);
            $statut = $split_etat["statut"];
            $time = $split_etat["time"];
            
            $restart_message = "";
            
            if ($this->isValidePid($name)) {
                $query_error = "SELECT id FROM ".LmbEdi\LmbEdi::getPrefix().self::$PIDS[$name]["table"]." WHERE etat = 9";
                
                if ($mess_error = $bdd->query($query_error)->fetchObject()) {
                    if (!empty($retour["blocked"])) {
                        $retour["blocked"] .= "\n";
                    }
                    $retour["blocked"] .= "$name impossible a redémarrer car le message ".$mess_error->id." est en erreur";
                    continue;
                }
            }
            if ($statut === 9) {
                $restart_message = "Process en erreur";
            }
            if ($statut === 1 && time() - $time > $delay_warning) {
                $restart_message = "Process en cours depuis plus de $delay_warning secondes";
            }
            if ($statut === 0 && $this->isValidePid($name)) {
                $query_count = "SELECT COUNT(id) AS count FROM ".LmbEdi\LmbEdi::getPrefix().self::$PIDS[$name]["table"]." WHERE etat = ".\lmbedi_queue::OK_CODE;
                $count_messages = $bdd->query($query_count)->fetchObject()->count;
                if ($count_messages > 0) {
                    $restart_message = "Process inactif avec $count_messages messages en attente";
                }
            }
            
            if (!empty($restart_message)) {
                if ($this->isValidePid($name)) {
                    $pid = new \lmbedi_pid($name);
                    $pid->unsetPid();
                    LmbEdi\new_process(self::$PIDS[$name]["process"], $name);
                }
                
                $retour[$name] = $restart_message;
            }
        }
        
        return $retour;
    }
}