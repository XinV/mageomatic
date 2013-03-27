<?php

/**
 * Contains functionality shared by both Export and Import runs.
 *
 * Subclass models must contain 'status', 'status_message',
 * 'start_time', and 'end_time' columns
 * of types integer, text, datetime, and datetime respectively.
 */
abstract class Salsify_Connect_Model_SyncRun extends Mage_Core_Model_Abstract {

  protected static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }


  // cached handle
  private $_salsify_api;


  const STATUS_ERROR       = -1;
  const STATUS_NOT_STARTED = 0;
  const STATUS_DONE        = 1000;
  abstract function get_status_string();

  // sets the status and status message of the sync.
  protected function _set_status($code) {
    $this->setStatus($code);
    $this->setStatusMessage($this->get_status_string());
    if ($code === self::STATUS_DONE) {
      $this->_set_end_time();
    }
  }


  // sets the status of this sync to error.
  // FIXME must remove myself from the job queue if this happens...
  public function set_error($e) {
    if (is_string($e)) {
      $e = new Exception($e);
    }
    self::_log("Setting sync run status to error: " . $e->getMessage());
    $this->_set_end_time();
    $this->setStatus(self::STATUS_ERROR);
    $this->setStatusMessage('Error: ' . $e->getMessage());
    $this->save();
    throw $e;
  }


  // sets the start time to the current time. MySQL friendly datetime format.
  protected function _set_start_time() {
    // FIXME this doesn't seem to be working. time set is weird.
    $this->setStartTime(date('Y-m-d h:m:s'));
    return $this;
  }

  // sets the start time to the current time. MySQL friendly datetime format.
  protected function _set_end_time() {
    // FIXME this doesn't seem to be working. time set is weird.
    $this->setEndTime(date('Y-m-d h:m:s'));
    return $this;
  }


  protected function _construct() {
    if (!$this->getId()) {
      $this->_set_status(self::STATUS_NOT_STARTED);
      // start time is updated with actual start time if there are not failures
      $this->setStartTime(date('Y-m-d h:m:s', time()));
    }

    $this->_ensure_complete_salsify_configuration();
    $this->_get_salsify_api();
    return $this;
  }


  // ensures that the Salsify account confguration is complete.
  private function _ensure_complete_salsify_configuration() {
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
      $this->_salsify_api = Mage::helper('salsify_connect/salsifyapi');
    }
    return $this->_salsify_api;
  }


  // causes this job to be enqueued in the DJJob database
  public function enqueue() {
    $salsify = Mage::helper('salsify_connect');
    $salsify->enqueue_job($this);
    return $this;
  }
}