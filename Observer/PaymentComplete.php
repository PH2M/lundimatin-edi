<?php

namespace LundiMatin\EDI\Observer;

use \LundiMatin\EDI\LmbEdi;

class PaymentComplete implements \Magento\Framework\Event\ObserverInterface {

    public function execute(\Magento\Framework\Event\Observer $observer) {
        require_once __DIR__ . '/../LmbEdi/function.lib.php';
        LmbEdi\LmbEdi::instance()->create_payment($observer->getPayment()->getOrder()->getIncrementId());
        return $this;
    }

}
