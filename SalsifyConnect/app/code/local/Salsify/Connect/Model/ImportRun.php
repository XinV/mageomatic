<?php
class Salsify_Connect_Model_ImportRun extends Mage_Core_Model_Abstract
{
  const STATUS_PROCESSING = 1;
  const STATUS_UPLOADING  = 2;
  const STATUS_READY      = 3;

  protected function _construct() {
    $this->_init('salsify_connect/import_run');
  }
}