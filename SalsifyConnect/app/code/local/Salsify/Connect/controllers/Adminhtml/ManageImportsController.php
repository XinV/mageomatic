<?php

class Salsify_Connect_Adminhtml_ManageImportsController extends Mage_Adminhtml_Controller_action {
 
  public function indexAction() {
    Mage::log("index", null, 'salsify.log', true);

    echo "HERE";

    $this->loadLayout();
    $this->renderLayout();
  }

}