<?php

use LundiMatin\EDI\LmbEdi;

class lmbedi_message_recu extends lmbedi_edi_process {

    public static $process = "pmrq.pid";
    private $chaine = "";
    private $nom_fonction;
    private $params;
    protected static $crypto;

    public function __construct($id_message = null) {
        if (empty(self::$crypto))
            self::$crypto = lmbedi_crypto::getInstance(\lmbedi_config::CODE_CONNECTION());

        if (!$id_message)
            return;

        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "SELECT * FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_recu_queue
                        WHERE id=$id_message;";
        if ($res = $bdd->query($query)) {
            if ($me = $res->fetchObject()) {
                $this->id = $me->id;
                $this->date = $me->date;
                $this->chaine = $me->chaine;
                $this->etat = $me->etat;
            }
        }
    }

    public static function create() {
        $mess_recu = new lmbedi_message_recu();
        return $mess_recu;
    }

    public function set_fonction($fonction_name, $params) {

        if ($fonction_name != "") {
            $this->nom_fonction = $fonction_name;
        } else {
            return false;
        }
        if (!empty($params)) {
            $this->params = $params;
        } else {
            return false;
        }

        return $this->saveme();
    }

    public function saveme() {
        $bdd = LmbEdi\LmbEdi::getBDD();

        $chaine['nom_fonction'] = $this->nom_fonction;
        $chaine['params'] = $this->params;
        $sig = md5(uniqid());

        $query = "INSERT INTO " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_recu_queue 
                        (chaine, date, etat,sig)
                    VALUES(" . $bdd->quote(json_encode($chaine)) . ", NOW(), '" . lmbedi_queue::OK_CODE . "','$sig');";

        if ($bdd->exec($query) > 0) {
            return true;
        }
    }

    private function encryptme() {
        $tab = [];
        $tab["nom_fonction"] = $this->nom_fonction;
        $tab["params"] = $this->params;
        if (\lmbedi_config::GZ_DATAS() && is_array($tab["params"])) {
            foreach ($tab["params"] as &$param) {
                if (!empty($param) && is_string($param)) {
                    $param = gzcompress($param, 5);
                }
            }
        }
        $chaine = self::$crypto->encrypt(json_encode($tab));

        return $chaine;
    }

    public static function loadNext() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "SELECT * FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_recu_queue
                    WHERE etat!='" . lmbedi_queue::TRAITE_CODE . "' ORDER BY date,id ASC LIMIT 1;";
        if ($result = $bdd->query($query)) {
            if ($mes = $result->fetchObject()) {
                return new lmbedi_message_recu($mes->id);
            }
            $result->closeCursor();
        }
        return null;
    }

    public function exec() {
        $bdd = LmbEdi\LmbEdi::getBDD();

        $return = false;
        LmbEdi\logme("Début du traitement du message " . $this->id);
        $this->decryptme();
        LmbEdi\logme("fonction: " . $this->nom_fonction);
        LmbEdi\logme($this->id . " - after decrypt - (json) " . $this->nom_fonction . ": " . print_r(@json_encode($this->params, JSON_PRETTY_PRINT), true));

        $recepteur = new \LundiMatin\EDI\LmbEdi\Spec\Recepteur();
        $recepteur->getPreTraite();
        $recepteur->getPostTraite();

        // On charge les méthodes pour identifier si la méthode est dans la classe de prétraitement ou dans la classe parent
        $methode = false;
        if (class_exists(\LundiMatin\EDI\LmbEdi\Spec\Recepteur::$preTraiteClassName)) {
            $class_preTrait = new ReflectionClass(\LundiMatin\EDI\LmbEdi\Spec\Recepteur::$preTraiteClassName);
            try {
                $methode = $class_preTrait->getMethod($this->nom_fonction);
            } catch (Exception $e) {
                LmbEdi\trace("Exception", "La méthode " . $this->nom_fonction . " n'existe pas !");
            }
        }
        if (!empty($methode->name) && !empty($methode->class) && $methode->name == $this->nom_fonction && $methode->class == \LundiMatin\EDI\LmbEdi\Spec\Recepteur::$preTraiteClassName
        ) {
            $this->params = $recepteur->getPreTraite()->{$this->nom_fonction}($this->params);
            // En sortie, $this->params est égal à $this->params qui a été modifié
            // Si $this->params est égal à false, on ignore volontairement
            // l'éxécution du processus normal
        }

        if (is_array($this->params)) {
            if (method_exists($recepteur, $this->nom_fonction)) {
                /* if (in_array($this->nom_fonction, explode(";", \lmbedi_config::FUNCTIONS_TO_LOG()))) {
                  $msg['nom_fonction'] = $this->nom_fonction;
                  $msg['params'] = $this->params;
                  $msg['date'] = $this->date;
                  $msg['sig'] = $this->sig;
                  $msg['id'] = $this->id;
                  lmb_edi::logMessageRecu($msg);
                  } */
                try {
                    $return = $recepteur->{$this->nom_fonction}($this->params);
                    LmbEdi\logme("fonction executée OK");
                    if (method_exists(get_class($recepteur->getPostTraite()), $this->nom_fonction)) {
                        LmbEdi\logme("posttraite::" . $this->nom_fonction);
                        $return = $recepteur->getPostTraite()->{$this->nom_fonction}($this->params, $return);
                    }
                    return $return;
                } catch (Exception $e) {
                    LmbEdi\trace_error("reception", $e->getMessage() . "\n" . $e->getTraceAsString());
                    $query = "UPDATE " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_recu_queue
                                SET etat = '" . lmbedi_queue::ERROR_CODE . "' WHERE id=" . $this->id;
                    $bdd->exec($query);
                    throw $e;
                }
            } else {
                LmbEdi\logme("function not found!");
                $query = "UPDATE " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_recu_queue
                                SET etat = '" . lmbedi_queue::ERROR_CODE . "' WHERE id=" . $this->id;
                $bdd->exec($query);
                @mail('support@lundimatin.fr', "[ALERTE EDI] - Le module EDI ne semble plus à jour", LmbEdi\LmbEdi::getRacineURL() . "<br/>\r\nFonction " . $this->nom_fonction . " innexistante.");
                LmbEdi\trace_error("reception", "[ALERTE EDI] - Le module EDI ne semble plus à jour `" . $this->nom_fonction . "`");
            }
        } else {
            LmbEdi\logme("la fonction " . $this->nom_fonction . " est byPassée");
            return $this->params;
        }
        return false;
    }

    private function decryptme() {
        if ($this->chaine != "") {
            if (substr($this->chaine, 0, 1) == "{") {
                $tab = json_decode($this->chaine, true);
            } else {
                $tab = json_decode(self::$crypto->decrypt($this->chaine), true);
            }
            if (is_null($tab)) {
                $constants = get_defined_constants(true);
                $json_errors = [];
                foreach ($constants["json"] as $name => $value) {
                    if (!strncmp($name, "JSON_ERROR_", 11)) {
                        $json_errors[$value] = $name;
                    }
                }
                LmbEdi\trace_error("reception", "Erreur JSON : " . $json_errors[json_last_error()]);
                throw new \Exception("JSON ERROR : message_reception err:7EY ".print_r($tab,1));
            }
            
            if (\lmbedi_config::GZ_DATAS() && is_array($tab["params"])) {
                foreach ($tab["params"] as &$param) {
                    if (!empty($param) && is_string($param)) {
                        $param = gzuncompress($param);
                    }
                }
            }
            $this->nom_fonction = $tab["nom_fonction"];
            $this->params = $tab["params"];

            return true;
        }
    }
    
    public function getId() {
        return $this->id;
    }
    
    public function getNom_fonction() {
        if (empty($this->nom_fonction)) {
            $this->decryptme();
        }
        
        return $this->nom_fonction;
    }
    
    public function getParams() {
        if (empty($this->params)) {
            $this->decryptme();
        }
        
        return $this->params;
    }
    
    public function getChaine() {
        return $this->chaine;
    }

    public function estExecutable() {
        return true;
    }

    public function remove() {
        $bdd = LmbEdi\LmbEdi::getBDD();

        $query = "UPDATE " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_recu_queue
                        SET etat = '" . lmbedi_queue::TRAITE_CODE . "' WHERE id=" . $this->id;
        $bdd->exec($query);
    }

    public static function getProcess() {
        return self::$process;
    }

}
