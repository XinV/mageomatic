<?php
class Salsify_Connect_Model_ImportRun extends Mage_Core_Model_Abstract {

  const STATUS_PREPARING   = 1;
  const STATUS_UPLOADING   = 2;
  const STATUS_DOWNLOADING = 3;
  const STATUS_LOADING     = 4;
  const STATUS_DONE        = 5;

  protected function _construct() {
    $this->_init('salsify_connect/importrun');
  }

  public function set_status_preparing() {
    $this->setStatus(self::STATUS_PREPARING);
  }

  public function set_status_uploading() {
    $this->setStatus(self::STATUS_UPLOADING);
  }

  public function set_status_downloading() {
    $this->setStatus(self::STATUS_DOWNLOADING);
  }

  public function set_status_loading() {
    $this->setStatus(self::STATUS_LOADING); 
  }

  public function set_status_done() {
    $this->setStatus(self::STATUS_DONE); 
  }
}