<?php

namespace LundiMatin\EDI\Observer;

use \LundiMatin\EDI\LmbEdi;

class SaleComplete implements \Magento\Framework\Event\ObserverInterface {

    public function execute(\Magento\Framework\Event\Observer $observer) {
        $event_name = $observer->getEvent()->getName();
        $order = $observer->getEvent()->getOrder();
        
        switch($event_name) {
            case "sales_order_place_after" :
                LmbEdi\LmbEdi::instance()->create_order($order->getIncrementId());
                break;
            case "sales_order_save_after" :
                if ($order->getState() == "processing") {
                    LmbEdi\LmbEdi::instance()->create_payment($order->getIncrementId());
                }
                break;
        }
        
        return $this;
    }

}
