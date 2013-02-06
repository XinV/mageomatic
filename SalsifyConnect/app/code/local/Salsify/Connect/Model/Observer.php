<?php
class Salsify_Connect_Model_Observer {

  public function logUpdate(Varien_Event_Observer $observer) {
    $product = $observer->getEvent()->getProduct();

    // FIXME record this somewhere else
    $name = $product->getName();
    $sku = $product->getSku();
    Mage::log(
        "{$name} ({$sku}) updated",
        null, 
        'salsify.log',
        true
    );
  }

}