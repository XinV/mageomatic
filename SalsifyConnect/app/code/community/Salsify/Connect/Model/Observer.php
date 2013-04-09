<?php
/**
 * This class will be used to watch the products in Magento so that Salsify
 * Connect will only send the changed products to Salsify during a sync instead
 * of bulk sending all of them.
 */
class Salsify_Connect_Model_Observer {

  public function logUpdate(Varien_Event_Observer $observer) {
    $product = $observer->getEvent()->getProduct();

    $name = $product->getName();
    $sku = $product->getSku();
  }

}