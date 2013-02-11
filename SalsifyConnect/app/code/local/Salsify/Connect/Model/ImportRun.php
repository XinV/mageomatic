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
    $this->_init('salsify_connect/importrun');
  }

  public function start_import() {
    $this->setStatus(self::STATUS_PREPARING);
    $this->setStartTime(date('Y-m-d h:m:s', time()));
    $this->save();
    
    try {
      $downloader = $this->_get_downloader();
      $export = $downloader->create_export();
      $this->setToken($export->id);
    } catch (Exception $e) {
      $this->setStatus(self::STATUS_ERROR);
      $this->save();
      throw $e;
    }
  }

  private function _get_config() {
    if (!$this->_config) {
      $this->_config = Mage::getModel('salsify_connect/configuration')
                           ->load($this->getConfigurationId())
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
      $this->_downloader.set_base_url($config->getUrl());
      $this->_downloader.set_api_key($config->getApiKey());
    }
    return $this->_downloader;
  }

}