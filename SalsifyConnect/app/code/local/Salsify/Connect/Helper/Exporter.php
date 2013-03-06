<?php

/**
 * Writes out Magento data to a Salsify format.
 *
 * @todo enable partial exports (just products, etc.).
 */
class Salsify_Connect_Helper_Exporter extends Mage_Core_Helper_Abstract {

  private static function _log($msg) {
    Mage::log('Exporter: ' . $msg, null, 'salsify.log', true);
  }


  // TODO: Doxygen vs. some other PHP documentation?
  //! Open export stream
  /*! File that we're writing data out to. We do not have responsibility for
      opening and closing this stream; that must be done by the calling class.
   */
  private $_output_stream;


  // cached handle to the salsify helper
  private $_salsify;


  // handy helper function that writes the given content out to the exporter's
  // output stream and adds a newline.
  private function _write($content) {
    fwrite($this->_output_stream, $content . "\n");
  }


  // TODO: Doxygen vs. some other PHP documentation?
  /**
   * Creates a COMPLETE export of Magento data to Salsify.
   *
   * @param $export_stream already opened output stream to which the export will
   *                       be written.
   */
  public function export($export_stream) {
    $this->_salsify Mage::getModel('salsify_connect');

    $this->_output_stream = $export_stream;

    self::_log("starting to export Magento data into document");
    $this->_start_document();

    self::_log("writing header...");
    $this->_start_header();
    $this->_end_header();
    self::_log("done writing header.");

    self::_log("writing attributes...");
    $this->_start_attributes();
    $this->_write_attributes();
    $this->_end_attributes();
    self::_log("done writing attributes.");

    self::_log("writing attribute values...");
    $this->_start_attribute_values();
    $this->_write_attribute_values();
    $this->_end_attribute_values();
    self::_log("done writing attribute values.");

    self::_log("writing products...");
    $this->_start_products();
    $this->_write_products();
    $this->_end_products();
    self::_log("done writing products.");

    $this->_end_document();
    self::_log("done exporting Magento data into document");
  }


  private function _start_document() {
    $this->_write('[');
  }

  private function _end_document() {
    $this->_write(']');
  }


  private function _start_header() {
    // makes this a little more readable
    $this->_write('{"header":{');
    $this->_write('"version":"2012-12"');
    $this->_write(',"update_semantics":"truncate"');
    $this->_write(',"scope":["all"]');
    $this->_write('}}');
  }

  private function _end_header() {
    // NOOP
  }


  // keeps track of whether we're talking about the first item in an array
  private $_first_item;

  private function _start_nonheader_section($name) {
    // because the header is guaranteed to have been first, it is safe to assume
    // a comma is needed here.
    $this->_write(',{"'.$name.'":[');
    $this->_first_item = true;
  }

  private function _end_nonheader_section() {
    $this->_write(']}');
  }

  private function _write_object($object) {
    if (!$this->_first_item) {
      $this->_write(',');
    }
    $json = json_encode($object);
    $this->_write($json);
    $this->_first_item = false;
  }


  private function _start_attributes() {
    $this->_start_nonheader_section('attributes');
  }

  private function _end_attributes() {
    $this->_end_nonheader_section();
  }

  private function _write_attributes() {
    $mapper = $this->_salsify->get_attribute_mapper();
    $attributes = $mapper::getProductAttributes();
    foreach ($attributes as $attribute) {
      $this->_write_attribute($mapper, $attribute);
    }
  }

  private function _write_attribute($mapper, $attribute) {
    $attribute_json = array();

    $code = $attribute->getAttributeCode();
    $id = $mapper::getIdForCode($code);
    $attribute_json['id'] = $id;

    $name = $attribute->getFrontendLabel();
    $attribute_json['name'] = $id;

    // ROLES

    // FIXME

    $this->_write_object($attribute_json);
  }


  private function _start_attribute_values() {
    $this->_start_nonheader_section('attribute_values');
  }

  private function _end_attribute_values() {
    $this->_end_nonheader_section();
  }

  private function _write_attribute_values() {
    // FIXME implement
  }


  private function _start_products() {
    $this->_start_nonheader_section('products');
  }

  private function _end_products() {
    $this->_end_nonheader_section();
  }

  private function _write_products() {
    // FIXME implement
  }
}