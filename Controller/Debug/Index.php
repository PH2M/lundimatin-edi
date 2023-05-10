<?php
 
namespace LundiMatin\EDI\Controller\Debug;
use LundiMatin\EDI\Model\LmbAction;
 
class Index extends LmbAction {
    
    public function doAction($params) {
        include __DIR__ . '/../../view/frontend/debug.php';
    }
}
