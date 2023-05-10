<?php
 
namespace LundiMatin\EDI\Controller\Pile;
use LundiMatin\EDI\Model\LmbAction;
use \LundiMatin\EDI\LmbEdi;
 
class Envoi extends LmbAction {
    
    public function doAction($params) {
        include __DIR__ . '/../../LmbEdi/liaison/_process_messages_queue.php';
    }
}
