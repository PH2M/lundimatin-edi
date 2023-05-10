<?php

namespace LundiMatin\EDI\Model;
use \LundiMatin\EDI\LmbEdi;

class LmbKpi {
    
    protected static $list = array();
    protected static $log_unknown = false;
    protected static $prefix = "kpi_";
    protected static $time_precision = 9;
    
    protected $reference = 0;
    protected $open_time = 0;
    protected $step_time = 0;
    protected $log_name = "kpi";
    
    // Message #3195404
    protected static function get_time() {
        if (function_exists("hrtime")) {
            $time_precis = hrtime(true);
        }
        else {
            $time_precis = microtime(true);
        }
        return $time_precis;
    }
    
    protected static function format_reference($reference) {
        return preg_replace("[^a-zA-Z0-9\-_]", "", $reference);
    }
    
    protected static function key_reference($reference) {
        return self::$prefix.self::format_reference($reference);
    }
    
    protected static function humanfriendly_time($time_precis) {
        return ($time_precis / 10**self::$time_precision)." secondes";
    }
    
    protected static function get($reference) {
        $key_reference = self::key_reference($reference);
        if (!empty(self::$list[$key_reference])) {
            return self::$list[$key_reference];
        }
        return new self($reference);
    }
    
    protected static function create($reference, $log_name = "") {
        $kpi = self::get($reference);
        if (!empty($kpi)) {
            return $kpi;
        }
        return new self($reference, $log_name);
    }
    
    protected function __construct($reference = "", $log_name = "") {
        $this->reference = $reference;
        $key_reference = self::key_reference($reference);
        self::$list[$key_reference] = $this;
        if (!empty($log_name)) {
            $this->log_name = $log_name;
        }
    }
    
    protected function clear() {
        $key_reference = self::key_reference($this->reference);
        if (!empty(self::$list[$key_reference])) {
            unset(self::$list[$key_reference]);
        }
    }
    
    public static function open_kpi($reference, $log_name = "") {
        $kpi = self::create($reference, $log_name);
        if (empty($kpi->open_time)) {
            $kpi->open_time = self::get_time(true);
            $kpi->step_time = $kpi->open_time;
            $kpi->trace("Début KPI $reference => " . $kpi->open_time);
        }
        else {
            $kpi->step("Reload");
        }
        return $kpi;
    }
    
    public static function kpi_step($reference, $message = "Ajout d'une étape sans nom") {
        $kpi = self::get($reference);
        $kpi->step($message);
    }
    
    public function step($message = "Ajout d'une étape sans nom") {
        $current_time = self::get_time(true);
        $this->trace("Step KPI ".$this->reference." $message => " . $current_time);
        $this->trace("    Délai depuis la dernière étape => " . self::humanfriendly_time($current_time - $this->step_time));
        $this->trace("    Délai depuis le début => " . self::humanfriendly_time($current_time - $this->open_time));
        $this->step_time = $current_time;
    }
    
    public static function close_kpi($reference) {
        $kpi = self::get($reference);
        $kpi->close();
    }
    
    public function close() {
        $current_time = self::get_time(true);
        $this->trace("Fin KPI ".$this->reference." => " . $current_time);
        $this->trace("    Délai total => " . self::humanfriendly_time($current_time - $this->open_time));
        $this->clear();
    }

    protected function trace($message) {
        LmbEdi\trace($this->log_name, $message);
    }
}
