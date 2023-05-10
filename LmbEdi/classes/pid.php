<?php

use LundiMatin\EDI\LmbEdi;

class lmbedi_pid {

    const ETAT_REPOS = 0;
    const ETAT_RUN = 1;
    const ETAT_ERROR = 9;

    private $pid_name;

    /**
     * 
     * @return int
     */
    public static function getAlertTime(): int {
        return 2 * 60;
    }

    /**
     * 
     * @return int
     */
    public static function getIntervalleTime(): int {
        return 15 * 60;
    }

    public function __construct($name) {
        $this->pid_name = $name;
    }

    /**
     * 
     * @return string
     */
    function getName(): string {
        return $this->pid_name;
    }

    function issetPid() {
        $alert_time = self::getAlertTime();
        $intervale_mail = self::getIntervalleTime();

        $data = $this->getEtat();
        $return = $data != self::ETAT_REPOS;
        if ($data == self::ETAT_RUN) {
            $old_time = $this->getTime();
            $new_time = time();
            if (($new_time - $old_time) > $alert_time && ($new_time - \lmbedi_config::LAST_ALERT()) > $intervale_mail) {
                \lmbedi_config::LAST_ALERT($new_time);
                $server = LmbEdi\LmbEdi::getRacineURL();
                if (\lmbedi_config::MAIL_ALERT())
                    mail(\lmbedi_config::MAIL_ALERT(), "Blocage EDI Mg2 : $server", "Ca bloque sur $server/modules/lmb/debug.php\nDepuis " . strftime("%Hh%M:%S", $new_time - $old_time - 3600) . "\nDétails : " . LMBEDI_PLUGIN_DIR . '/LmbEdi/' . "pid/" . $this->pid_name . "\n" . print_r(debug_backLmbEdi\trace(), true));
            }
        }
        return $return;
    }

    function getPid() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "SELECT etat FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_pid  WHERE name = " . $bdd->quote($this->pid_name) . "";
        $res = $bdd->query($query);
        if ($pid = $res->fetchObject()) {
            return $pid->etat;
        }
        return self::ETAT_REPOS;
    }

    public function getEtat() {
        return substr($this->getPid(), 0, 1);
    }

    public function getTime() {
        return substr($this->getPid(), 1);
    }

    function isError() {
        return $this->getEtat() == self::ETAT_ERROR;
    }

    function setPid() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "REPLACE INTO " . LmbEdi\LmbEdi::getPrefix() . "edi_pid (name, etat, sys_pid) VALUES (" . $bdd->quote($this->pid_name) . ", " . $bdd->quote(self::ETAT_RUN . time()) . ", " . $bdd->quote(getmypid()) . ")";
        $bdd->exec($query);
    }

    function setErrorPid() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        global $intervale_mail;
        $query = "REPLACE INTO " . LmbEdi\LmbEdi::getPrefix() . "edi_pid (name, etat) VALUES (" . $bdd->quote($this->pid_name) . ", " . $bdd->quote(self::ETAT_ERROR . time()) . ")";
        $bdd->exec($query);

        $new_time = time();
        if (($new_time - \lmbedi_config::LAST_ALERT()) > $intervale_mail) {
            \lmbedi_config::LAST_ALERT($new_time);

            $server = LmbEdi\LmbEdi::getRacineURL();
            if (\lmbedi_config::MAIL_ALERT())
                mail(\lmbedi_config::MAIL_ALERT(), "Blocage Critique Mg2 : $server", "Alerte sur $server...\n$server/modules/lmb/debug.php\nDétails : " . $this->pid_name . "\n" . print_r(debug_backLmbEdi\trace(), true));
        }
    }

    function getSysPid() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "SELECT sys_pid FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_pid  WHERE name = " . $bdd->quote($this->pid_name) . "";
        $res = $bdd->query($query);
        if ($pid = $res->fetchObject()) {
            return $pid->sys_pid;
        }
        return "0";
    }

    function majDatePid() {
        $this->setPid();
    }

    function unsetPid() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "REPLACE INTO " . LmbEdi\LmbEdi::getPrefix() . "edi_pid (name, etat) VALUES (" . $bdd->quote($this->pid_name) . ", " . $bdd->quote(self::ETAT_REPOS . time()) . ")";
        if (!$bdd->exec($query)) {
            LmbEdi\trace_error("pid", "Impossible de remettre le PID à 0");
        }
    }

    function deletePid() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "DELETE FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_pid WHERE name = " . $bdd->quote($this->pid_name) . "";
        $bdd->exec($query);
    }

    public static function getList() {
        $bdd = LmbEdi\LmbEdi::getBDD();
        $query = "SELECT name, etat FROM " . LmbEdi\LmbEdi::getPrefix() . "edi_pid ORDER BY name";
        $res = $bdd->query($query);
        $pids = [];
        while ($pid = $res->fetchObject()) {
            $pids[] = $pid;
        }
        return $pids;
    }

    public static function stopIsset() {
        $pid = new self("stop");
        return $pid->issetPid();
    }

    /**
     * 
     * @return string
     */
    public function getLibEtat() {
        switch ($this->getEtat()) {
            case self::ETAT_REPOS:
                $lib = "Inactif";
                break;
            case self::ETAT_RUN:
                $lib = "En cours";
                break;
            case self::ETAT_ERROR:
                $lib = "En erreur";
                break;
        }
        return $lib;
    }

}
