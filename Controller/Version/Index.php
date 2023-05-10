<?php

namespace LundiMatin\EDI\Controller\Version;
use \LundiMatin\EDI\LmbEdi;
use LundiMatin\EDI\Model\LmbAction;
use LundiMatin\EDI\Model\Receiver;

class Index extends LmbAction {

    public function doAction($params) {
        $this->checkSerial($params);
        $this->send(array("version" => \lmbedi_config::$VERSION));
    }
}