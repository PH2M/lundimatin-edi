<?php

use LundiMatin\EDI\LmbEdi;

class lmbedi_message_envoi extends lmbedi_edi_process {

    protected $destination;
    protected $nom_fonction = "";
    protected $params = [];
    protected $chaine;
    protected $date;
    protected $date_creation;
    protected $message = "";
    protected $sig;
    protected $saved = 0;
    protected $destination_set = false;
    protected static $id_canal;
    protected static $crypto;
    public static $process = "pmeq.pid";
    public static $transport = null;

    public function __construct($ref_message = null) {
        $bdd = LmbEdi\LmbEdi::getBDD();

        if (empty(self::$crypto))
            self::$crypto = lmbedi_crypto::getInstance(\lmbedi_config::CODE_CONNECTION());

        if (isset($ref_message)) {
            $query = "SELECT id,chaine,date,sig,etat FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_envoi_queue WHERE id='" . $ref_message . "'";
            $res = $bdd->query($query);
            if ($me = $res->fetchObject()) {
                $this->id = $me->id;
                $this->date = $me->date;
                $this->sig = $me->sig;
                $this->etat = $me->etat;
                $this->chaine = $me->chaine;
            }
            $res->closeCursor();
        } else {
            $this->date_creation = date("Y-m-d H:i:s");
            $this->etat = 0;
        }
    }

    public static function loadNext() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "SELECT * FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_envoi_queue
                    WHERE etat!='" . lmbedi_queue::TRAITE_CODE . "' ORDER BY date,id ASC LIMIT 1;";
        $result = $bdd->query($query);
        if ($event = $result->fetchObject()) {
            return new lmbedi_message_envoi($event->id);
        }
        $result->closeCursor();
        return null;
    }

    public function exec() {
        $return = "";
        LmbEdi\logme("Début de l'envoi du message " . $this->id);
        $this->decryptme();
        LmbEdi\logme($this->id . " - after decrypt - (json) " . $this->destination . " - " . $this->nom_fonction . ": " . print_r(@json_encode($this->params, JSON_PRETTY_PRINT), true));

        try {
            $transport_options = array('location' => $this->destination,
                'uri' => 'urn:Liaison',
                'connection_timeout' => 10,
                'LmbEdi\trace' => 1,
                'style' => SOAP_RPC,
                'use' => SOAP_ENCODED,
                'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP);
            if (empty(self::$transport))
                self::$transport = new SoapClient(NULL, $transport_options);
            $transport_contenu = array($this->sig, $this->chaine);
            $return = self::$transport->__soapCall("Receiver", $transport_contenu);
            $reponse = self::$transport->__getLastResponse();
        } catch (Exception $e) {
            LmbEdi\logme("!!!!!!!EXCEPTION SOAP : " . $e->getMessage());
            LmbEdi\logme("Reponse :" . self::$transport->__getLastResponse());
            LmbEdi\logme("Contenu :" . print_r($transport_contenu, 1));
            LmbEdi\logme('File: ' . $e->getFile());
            LmbEdi\logme('Line: ' . $e->getLine());
            LmbEdi\logme('Backtrace: ' . $e->getTraceAsString());

            header("HTTP/1.1 400 Bad Request");
            self::$transport = null;
            return false;
        }

        if ($return === true) {
            return true;
        } else if ($return !== true) {
            LmbEdi\logme("----------------------------------------\n!!!ERREUR!!! lors de l'envoi du message $this->id \n-----------------------------------------------");
            LmbEdi\logme($reponse);
            $pid = new lmbedi_pid(self::$process);
            $pid->setErrorPid();
            return false;
        }
        return false;
    }

    private function encryptme() {
        $tab = [];
        $tab["destination"] = $this->destination;
        $tab["nom_fonction"] = $this->nom_fonction;
        $tab["params"] = $this->params;
        $tab["etat"] = $this->etat;
        $tab["message"] = $this->message;

        if (\lmbedi_config::GZ_DATAS()) {
            foreach ($tab["params"] as &$param) {
                if (!empty($param) && is_string($param)) {
                    $param = gzcompress($param, 5);
                }
            }
        }
        $chaine = self::$crypto->encrypt(json_encode($tab));

        return $chaine;
    }

    private function decryptme() {
        if ($this->chaine != "") {
            $tab = LmbEdi\json_decode_utf8(self::$crypto->decrypt($this->chaine), true);
            if (is_null($tab)) {
                $constants = get_defined_constants(true);
                $json_errors = [];
                foreach ($constants["json"] as $name => $value) {
                    if (!strncmp($name, "JSON_ERROR_", 11)) {
                        $json_errors[$value] = $name;
                    }
                }
                $json_errors[json_last_error()];
                LmbEdi\trace_error("envoi", "Erreur JSON : " . $json_errors[json_last_error()]);
                LmbEdi\trace_error("envoi", "Erreur JSON : ->chaine" . print_r($this->chaine, 1));
                LmbEdi\trace_error("envoi", "Erreur JSON : ->decrypted" . print_r(self::$crypto->decrypt($this->chaine), 1));
                throw new \Exception("JSON ERROR : message_envoi err:HKDD");
            }

            if (\lmbedi_config::GZ_DATAS() && is_array($tab["params"])) {
                foreach ($tab["params"] as &$param) {
                    if (!empty($param) && is_string($param)) {
                        $param = gzuncompress($param);
                    }
                }
            }
            $this->nom_fonction = $tab["nom_fonction"];
            $this->destination = $tab["destination"];
            $this->params = $tab["params"];
            $this->etat = $tab["etat"];
            $this->message = $tab["message"];
            return true;
        }
    }

    public function save() {
        $bdd = LmbEdi\LmbEdi::getBDD();

        $this->chaine = $this->encryptme();
        if (!empty($this->id)) {
            $query = "SELECT id,chaine,date FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_envoi_queue WHERE id='" . $this->id . "'";
            if ($res = $bdd->query($query)) {
                $query = "UPDATE " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_envoi_queue set chaine=" . $bdd->quote($this->chaine) . ", date=NOW() WHERE id='" . $this->di . "'";
                if (!$bdd->exec($query)) {
                    return false;
                }
            }
        } else {
            $sig = md5(uniqid());
            $query = "INSERT INTO " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_envoi_queue
                        (chaine, date, etat, sig) VALUES (" . $bdd->quote($this->chaine) . ", NOW(), " . lmbedi_queue::OK_CODE . ", '$sig');";
            if ($bdd->exec($query)) {
                $this->id = $bdd->lastInsertId();
            } else {
                LmbEdi\trace_error("save", $query);
                LmbEdi\trace_error("save", "ERREUR : " . print_r($bdd->errorInfo(), true));
                return false;
            }
        }
    }

    public function set_destination($destination) {
        if ($destination != "") {
            $this->destination = $destination;
            $this->destination_set = true;
            return true;
        } else {
            return false;
        }
    }

    public function set_fonction($fonction_name, $params) {
        if ($fonction_name != "") {
            $this->nom_fonction = $fonction_name;
        } else {
            return false;
        }
        if (is_array($params) && count($params) > 0) {
            $this->params = $params;
        } else {
            return false;
        }
        return true;
    }

    public function set_message($message) {
        if ($message != "") {
            $this->message = $message;
            return true;
        } else {
            return false;
        }
    }

    public function estExecutable() {
        return true;
    }

    public function remove() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "DELETE FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_messages_envoi_queue WHERE id=" . $this->id;
        $bdd->query($query);
    }

    public static function getProcess() {
        return self::$process;
    }

}
