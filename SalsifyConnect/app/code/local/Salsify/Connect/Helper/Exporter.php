<?php

/**
 * Writes out Magento data to a Salsify format.
 */
class Salsify_Connect_Helper_Exporter extends Mage_Core_Helper_Abstract {

  private function _log($msg) {
    Mage::log('Exporter: ' . $msg, null, 'salsify.log', true);
  }


  const STAGE_ERROR            = -1;
  const STAGE_NOT_STARTED      = 0;
  const STAGE_HEADER           = 1;
  const STAGE_ATTRIBUTES       = 2;
  const STAGE_ATTRIBUTE_VALUES = 3;
  const STAGE_PRODUCTS         = 4;
  const STAGE_DONE             = 5;
  private $_stage;

  // File that we're writing data out to. We do not have responsibility for
  // opening and closing this file; that must be done by the calling class.
  private $_file;


  private function _write($content) {
    fwrite($this->_file, $content . "\n");
  }


  // Sets the that we'll be writing to.
  public function set_file($file) {
    $this->_file = $file;
    $this->_stage = self::STAGE_NOT_STARTED;
  }


  public function start_document() {
    $this->_stage = STAGE_HEADER;
    $this->_write('[');
  }

  public function end_document() {
    $this->_stage = STAGE_DONE;
    $this->_write(']');
  }


  public function start_header() {
    $this->_write('{"header":{"version":"2012-12","update_semantics":"truncate","scope":["all"]}}');
  }

  public function end_header() {
    // NOOP
  }


  public function start_attributes() {
    $this->_write(',{"attributes":[');
  }

  public function end_attributes() {
    $this->_write(']}');
  }


  public function start_attribute_values() {
    $this->_write(',{"attribute_values":[');
  }

  public function end_attribute_values() {
    $this->_write(']}');
  }


  public function start_products() {
    $this->_write(',{"products":[');
  }

  public function end_products() {
    $this->_write(']}');
  }
}