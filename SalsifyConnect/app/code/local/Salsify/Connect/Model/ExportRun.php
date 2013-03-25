<?php

/**
 * Represents a single export run for Salsify. This provides all state
 * persistence functionality around a Salsify export.
 *
 * A bunch of information is kept in the database, while temporary data is
 * kept on the filesystem in var/salsify/.
 */
class Salsify_Connect_Model_ExportRun extends Mage_Core_Model_Abstract {

  private function _log($msg) {
    Mage::log('ExportRun: ' . $msg, null, 'salsify.log', true);
  }


  // cached handles on the helpers
  private $_config;
  private $_salsify_api;

  // FIXME need to update the status for export
  //
  const STATUS_ERROR                 = -1;
  const STATUS_NOT_STARTED           = 0;
  // const STATUS_SALSIFY_PREPARING     = 1;
  // const STATUS_DOWNLOAD_JOB_IN_QUEUE = 2;
  // const STATUS_DOWNLOADING           = 3;
  // const STATUS_LOADING               = 4;
  // const STATUS_DONE                  = 5;
  // public function get_status_string() {
  //   switch ($this->getStatus()) {
  //     case self::STATUS_ERROR:
  //       return "Error: Failed";
  //     case self::STATUS_NOT_STARTED:
  //       return "Export not started";
  //     case self::STATUS_SALSIFY_PREPARING:
  //       return "Salsify is preparing the data.";
  //     case self::STATUS_DOWNLOAD_JOB_IN_QUEUE:
  //       return "Download job is in the queue waiting to start.";
  //     case self::STATUS_DOWNLOADING:
  //       return "Magento is downloading the data from Salsify.";
  //     case self::STATUS_LOADING:
  //       return "Magento is loading the local Salsify data.";
  //     case self::STATUS_DONE:
  //       return "Import from Salsify has been successfully loaded into Magento.";
  //     default:
  //       throw new Exception("INTERNAL ERROR: unknown status: " . $this->getStatus());
  //   }
  // }

  public function set_error($e) {
    $this->_log("Setting export run status to error: " . $e->getMessage());
    $this->setStatus(self::STATUS_ERROR);
    $this->save();
    throw $e;
  }

  protected function _construct() {
    if (!$this->getStatus()) {
      $this->setStatus(self::STATUS_NOT_STARTED);
    }
    $this->_init('salsify_connect/exportrun');
  }

  private function _get_config() {
    if (!$this->_config) {
      $this->_config = Mage::getModel('salsify_connect/configuration')
                           ->load($this->getInstance());
      if (!$this->_config->getApiKey()) {
        throw new Exception("you must first configure your Salsify account information.");
      }
    }
    return $this->_config;
  }

  private function _get_salsify_api() {
    if (!$this->_salsify_api) {
      $config = $this->_get_config();
      // FIXME remove this since we're working as a singleton now
      $this->_salsify_api = Mage::helper('salsify_connect/salsifyapi');
      $this->_salsify_api->set_base_url($config->getUrl());
      $this->_salsify_api->set_api_key($config->getApiKey());
      $token = $this->getToken();
    }
    return $this->_salsify_api;
  }

  public function start_export() {
    // FIXME move to a background job or something like that...
    $salsify = Mage::helper('salsify_connect');
    // $salsify->export_data();
  }

}