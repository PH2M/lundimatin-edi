<?php
abstract class lmbedi_edi_process {
    
    protected $id;
    protected $etat = -1;

    abstract function exec();

    abstract function estExecutable();

    /**
     * 
     * @return null
     */
    static function getProcess(){
        return null;
    }

    abstract function remove();
    
    static function loadNext(){
        return null;
    }
    
    /**
     * 
     * @return int
     */
    public function getEtat() {
        return $this->etat;
    }

    /**
     * 
     * @return int
     */
    public function getId(){
        return $this->id;
    }
}


