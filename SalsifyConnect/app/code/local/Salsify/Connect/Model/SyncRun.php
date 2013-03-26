<?php

/**
 * Contains functionality shared by both Export and Import runs.
 *
 * FIXME finish refactor
 */
class Salsify_Connect_Model_SyncRun extends Mage_Core_Model_Abstract {


  // cached handles on the helpers
  protected $_config;
  protected $_salsify_api;


  // ensures that the Salsify account confguration is complete.
  // FIXME move somewhere else
  protected function _get_config() {
    if (!$this->_config) {
      $this->_config = Mage::getModel('salsify_connect/configuration')
                           ->getInstance();
      if (!$this->_config->getApiKey() || !$this->_config->getUrl()) {
        $this->set_error("you must first configure your Salsify account information.");
      }
    }
    return $this->_config;
  }


  protected function _get_salsify_api() {
    if (!$this->_salsify_api) {
      $config = $this->_get_config();

      // FIXME remove this since we're working as a singleton now
      $this->_salsify_api = Mage::helper('salsify_connect/salsifyapi');
      $this->_salsify_api->set_base_url($config->getUrl());
      $this->_salsify_api->set_api_key($config->getApiKey());
    }
    return $this->_salsify_api;
  }
}