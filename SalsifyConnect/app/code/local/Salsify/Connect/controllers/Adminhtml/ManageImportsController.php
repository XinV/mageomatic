<?php

class Salsify_Connect_Adminhtml_ManageImportsController extends Mage_Adminhtml_Controller_action {
 
  public function indexAction() {
    Mage::log("index", null, 'salsify.log', true);

    // this MUST come first for this to work
    $this->loadLayout();

    // make sure to set the active menu
    $this->_setActiveMenu('salsify_connect_menu/manage_imports');

    // add a left block to the layout
    $this->_addLeft($this->getLayout()
                         ->createBlock('core/text')
                         ->setText('<h1>Left Block</h1>'));

    // create a text block with the name of "example-block"
    $block = $this->getLayout()
                  ->createBlock('core/text', 'example-block')
                  ->setText('<h1>This is a text block</h1>');
    $this->_addContent($block);

    // add any js to the page using something like this:
    // $jsblock = $this->getLayout()->createBlock('core/text')->setText('<script type="text/        javascript">alert ("foo");</script>');
    // $this->_addJs($jsblock);

    $this->renderLayout();
  }

}