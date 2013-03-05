<?php
class Salsify_Connect_Model_Observer {

  public function logUpdate(Varien_Event_Observer $observer) {
    $product = $observer->getEvent()->getProduct();

    // TODO create a model in which to store the list of product IDs that have
    //      been updated since the last Salsify export.

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