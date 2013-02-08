<?php


require_once BP.DS.'lib'.DS.'JsonStreamingParser'.DS.'Listener.php';


/**
 * Parser of Salsify data. Also loads into the Magento database.
 */
class Salsify_Connect_Helper_Loader extends Mage_Core_Helper_Parser implements \JsonStreamingParser\Listener {

  // Number of products in a batch
  const BATCH_SIZE = 1000;

  // Current batch that has been read in.
  private $_batch;

  // Current product.
  private $_product;

  public function start_document() {
    $this->_batch = array();
  }

  public function end_document() {
    $this->_flush_batch();
  }

  public function start_object() {
    // FIXME
  }

  public function end_object() {
    // FIXME
  }

  public function start_array() {
    // FIXME
  }

  public function end_array() {
    // FIXME
  }

  // Key will always be a string
  public function key($key) {
    // FIXME
  }

  // Note that value may be a string, integer, boolean, array, etc.
  public function value($value) {
    // FIXME
  }


  private function _flush_batch() {
    // FIXME
  }
}