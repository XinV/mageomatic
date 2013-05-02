<?php

// Block to provide a form to configure the Salsify connection.
class Salsify_Connect_Block_Adminhtml_Config
      extends Mage_Core_Block_Template
{

  private static function _log($msg) {
    Mage::log("Salsify_Connect_Block_Adminhtml_Config" . ': ' . $msg, null, 'salsify.log', true);
  }


  private $_salsify_config;

  public function _construct() {
    $this->_salsify_config = Mage::getModel('salsify_connect/configuration')
                                 ->getInstance();
    $this->setTemplate('salsify/config.phtml');
    return parent::_construct();
  }

  public function getPostActionUrl() {
    return $this->getUrl('*/*/configuration');
  }

  public function getApiKey() {
    return $this->_salsify_config->getApiKey();
  }

  public function getSalsifyUrl() {
    return $this->_salsify_config->getUrl();
  }

}