<?php
class Salsify_Connect_Model_ImportRun extends Mage_Core_Model_Abstract {

  const STATUS_ERROR       = -1;
  const STATUS_NOT_STARTED = 0;
  const STATUS_PREPARING   = 1;
  const STATUS_UPLOADING   = 2;
  const STATUS_DOWNLOADING = 3;
  const STATUS_LOADING     = 4;
  const STATUS_DONE        = 5;

  private $_config;
  private $_downloader;

  protected function _construct() {
    if (!$this->getStatus()) {
      $this->setStatus(self::STATUS_NOT_STARTED);
    }
    $this->_init('salsify_connect/importrun');
  }

  public function get_status_string() {
    switch ($this->getStatus()) {
      case self::STATUS_ERROR:
        return "Error: Failed";
      case self::STATUS_NOT_STARTED:
        return "Export not started";
      case self::STATUS_PREPARING:
        return "Salsify is preparing the export.";
      case self::STATUS_UPLOADING:
        return "Salsify is uploading the export.";
      case self::STATUS_DOWNLOADING:
        return "Magento is downloading the export.";
      case self::STATUS_LOADING:
        return "Magento is loading the exported data.";
      case self::STATUS_DONE:
        return "Salsify export has been successfully loaded into Magento.";
      default:
        throw new Exception("INTERNAL ERROR: unknown status: " . $this->getStatus());
    }
  }

  public function start_import() {
    $this->setStatus(self::STATUS_PREPARING);
    $this->setStartTime(date('Y-m-d h:m:s', time()));
    $this->save();
    
    try {
      $downloader = $this->_get_downloader();
      $export = $downloader->create_export();
    } catch (Exception $e) {
      $this->setStatus(self::STATUS_ERROR);
      $this->save();
      throw $e;
    }
    $this->setToken($export->id);
    $this->setStatus(self::STATUS_PREPARING);
    $this->save();
  }

  private function _get_config() {
    if (!$this->_config) {
      $this->_config = Mage::getModel('salsify_connect/configuration')
                           ->load($this->getConfigurationId());
      if (!$this->_config->getId()) {
        throw new Exception("Must first specify a valid import configuration.");
      }
    }
    return $this->_config;
  }

  private function _get_downloader() {
    if (!$this->_downloader) {
      $config = $this->_get_config();
      $this->_downloader = Mage::helper('salsify_connect/downloader');
      $this->_downloader->set_base_url($config->getUrl());
      $this->_downloader->set_api_key($config->getApiKey());
    }
    return $this->_downloader;
  }

}