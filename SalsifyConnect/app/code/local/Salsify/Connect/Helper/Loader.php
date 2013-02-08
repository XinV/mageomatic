<?php
require_once BP.DS.'lib'.DS.'JsonStreamingParser'.DS.'Listener.php';

/**
 * Parser of Salsify data. Also loads into the Magento database.
 */
class Salsify_Connect_Helper_Loader extends Mage_Core_Helper_Abstract implements \JsonStreamingParser\Listener {

  // Number of products in a batch
  const BATCH_SIZE = 1000;

  // Current batch that has been read in.
  private $_batch;

  // Current product.
  private $_product;
  private $_key;

  // FIXME this variable is only necessary since we are only loading in props
  // right now.
  private $_in_nested;

  public function start_document() {
    $this->_batch = array();
    $this->_in_nested = 0;
  }

  public function end_document() {
    $this->_flush_batch();
  }

  public function start_object() {
    if ($this->_product) {
      $this->_in_nested++;
    } else {
      $this->_product = array();
    }
  }

  public function end_object() {
    if ($this->_in_nested > 0) {
      $this->_in_nested--;
    } else {
      array_push($this->_batch, $this->_product);
      $this->_product = null;
    }
  }

  public function start_array() {
    if ($this->_product) {
      // FIXME multi-assigned values, etc.
      $this->_in_nested++;
    } else {
      // start of product list
    }
  }

  public function end_array() {
    if ($this->_in_nested > 0) {
      $this->_in_nested--;
    } else {
      // end of document
    }
  }

  // Key will always be a string
  public function key($key) {
    if ($this->_in_nested == 0) {
      $this->_key = $key;
    }
  }

  // Note that value may be a string, integer, boolean, array, etc.
  public function value($value) {
    if ($this->_in_nested == 0 && $this->_key) {
      $this->_product[$this->_key] = $value;
      $this->_key = null;
    }
  }

  private function _flush_batch() {
    echo($this->_batch);
    // FIXME
  }
}