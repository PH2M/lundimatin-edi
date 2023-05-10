<?php

use \LundiMatin\EDI\LmbEdi;

require_once(__DIR__ . '/edi_process.php');

class lmbedi_event extends \lmbedi_edi_process {

    public static $process = "peq.pid";

    public function __construct($id_message) {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "SELECT * FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_events_queue WHERE id=$id_message;";
        $res = $bdd->query($query);
        if (is_object($res) && $me = $res->fetchObject()) {
            $this->id = $me->id;
            $this->type_event = $me->type_event;
            $this->param = $me->param;
            if (!is_null(json_decode($this->param))) {
                $this->param = json_decode($this->param, true);
            }
            $this->date = $me->date;
            $this->etat = $me->etat;
        }
    }

    public static function loadNext() {
        $bdd = LmbEdi\LmbEdi::getBDD();

        $cpt_event = -1;
        do {
            $continue = true;
            $cpt_event++;
            $query = "SELECT id FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_events_queue
                        WHERE etat!='" . lmbedi_queue::TRAITE_CODE . "' ORDER BY date,id ASC LIMIT $cpt_event,1;";
            $result = $bdd->query($query);
            if (is_object($result) && $mes = $result->fetchObject()) {
                $message = new lmbedi_event($mes->id);
                if ($message->estExecutable()) {
                    LmbEdi\trace("event", $mes->id . ': est executable');
                } else {
                    LmbEdi\trace("event", $mes->id . ': n\'est pas executable');
                }
            } else {
                $continue = false;
            }
        } while ($continue && !$message->estExecutable());

        if ($continue) {
            return $message;
        }
        return null;
    }

    public function exec() {
        $emetteur = new LmbEdi\Spec\Emeteur();
        $emetteur->getPreTraite();
        $emetteur->getPostTraite();

        $ret = false;
        $function = $this->type_event;

        // On charge les méthodes pour identifier si la méthode est dans la classe de prétraitement ou dans la classe parent
        $methode = false;

        if (class_exists(LmbEdi\Spec\Emeteur::$preTraiteClassName)) {
            $class_preTrait = new ReflectionClass(LmbEdi\Spec\Emeteur::$preTraiteClassName);
            try {
                $methode = $class_preTrait->getMethod($function);
            } catch (Exception $e) {
                LmbEdi\trace("Exception", "La méthode " . $function . " n'existe pas !");
            }
        }

        if (!empty($methode->name) && !empty($methode->class) && $methode->name == $function && $methode->class == LmbEdi\Spec\Emeteur::$preTraiteClassName
        ) {
            $pretraite = true;
            $ret = $emetteur->getPreTraite()->$function($this->param);
            if ($ret === false) {
                LmbEdi\trace_error("pretrait", "méthode $function forcée à être ignorée par un prétraitement");
                return true;
            }
        }

        if (method_exists($emetteur, $function) && (empty($pretraite) || $pretraite && !empty($ret))) {
            //LmbEdi\trace(self::getProcess(), $function."(".$this->param.",".$this->params.")");
            $ret = $emetteur->$function($this->param);

            // On charge les méthodes pour identifier si la méthode est dans la classe de post-traitement ou dans la classe parent
            $methode = false;
            if (class_exists(LmbEdi\Spec\Emeteur::$postTraiteClassName)) {
                $class_postTrait = new ReflectionClass(LmbEdi\Spec\Emeteur::$postTraiteClassName);
                try {
                    $methode = $class_postTrait->getMethod($function);
                } catch (Exception $e) {
                    LmbEdi\trace("Exception", "La méthode " . $function . " n'existe pas !");
                }
            }

            if (!empty($ret) && is_array($ret) && !empty($methode->name) && !empty($methode->class) && $methode->name == $function && $methode->class == LmbEdi\Spec\Emeteur::$postTraiteClassName
            ) {
                //LmbEdi\trace(self::getProcess(), "posttraite::".$function);
                $ret = $emetteur->getPostTraite()->$function(
                        array(
                            "param" => $this->param,
                            "retour" => $ret
                        )
                 );
            }
        } else {
            LmbEdi\trace_error(self::getProcess(), "La méthode '$function()' n'existe pas !");
            return false;
        }
        if (!empty($ret) && is_array($ret)) {
            $func_dist = $ret['_evt_name_'];
            unset($ret['_evt_name_']);
            LmbEdi\Spec\Emeteur::envoi_LMB($func_dist, $ret);
            return true;
        } else if ($ret === true)
            return true;
        else
            return false;
        return $ret;
    }

    public function estExecutable() {
        return true;
    }

    public function remove() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $bdd->exec("UPDATE " . LmbEdi\LmbEdi::getPrefix() . "edi_events_queue SET etat = 1 WHERE id='" . $this->id . "'");
        return true;
    }

    public static function getProcess() {
        return self::$process;
    }

    public static function create($type_event, $params, $force = false) {
		
        $bdd = LmbEdi\LmbEdi::getBDD();
        if (is_array($params) || is_object($params)){
            $params = json_encode($params);
		}
        //**************************************************************
        //Enregistrement dans la table edi_events_queue
        $isSaved = false;
        $query = "INSERT INTO " . LmbEdi\LmbEdi::getPrefix() . "edi_events_queue 
                    (type_event, param, date, etat) VALUES (" . $bdd->quote($type_event) . ", " . $bdd->quote($params) . ", NOW(), " . lmbedi_queue::OK_CODE . ");";
        LmbEdi\trace("edi_event", $query);
        if ($bdd->exec($query)) {
            $isSaved = true;
        }
        if (!$isSaved) {
            $pid = new lmbedi_pid(self::$process);
            $pid->setErrorPid();
            $error = "******* INSERTION d'un évenement dans la Queue impossible !!!\n";
            $error .= "query: " . $query . "\n";
            $error .= $bdd->errorCode() . " => " . print_r($bdd->errorInfo(), true);
            $error .= "type_event: " . $type_event . "\n";
            $error .= "param: " . $params . "\n";
            $error .= "date: " . date('Y-m-d H:i:s') . "\n\n";
            mail('support@lundimatin.fr', '[ALERTE EDI] ' . LmbEdi\LmbEdi::getRacineURL() . ' - Insertion d\'un event dans la file impossible.', $error . "" . LmbEdi\LmbEdi::getRacineURL() . "/modules/lmb/debug.php");
            $fp = fopen(dirname(__FILE__) . "/../_error_events_not_saved", "a");
            if ($fp) {
                fwrite($fp, $error);
                fclose($fp);
            }
        }
        else {
            $tentative = 0;
            while (!LmbEdi\new_process('event', self::$process)) {
                sleep(2);
                if ($tentative++ > 3) {
                    LmbEdi\trace_error('create_process', self::getProcess()." n'a pas pu être relancé après 3 tentatives");
                    break;
                }
            }
        }
    }

}
