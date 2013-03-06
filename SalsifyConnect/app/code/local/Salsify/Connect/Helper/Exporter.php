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


  // cached attribute map from magento code -> salsify id
  private $_attribute_map;


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
    try {
      $this->_salsify = Mage::helper('salsify_connect');
      $this->_attribute_map = array();

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
    } catch (Exception $e) {
      self::_log("ERROR: could not complete export: " . $e->getMessage());
      throw $e;
    }
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

    // FIXME need to write out a "target_product_id" kind of attribute as well
  }

  private function _write_attribute($mapper, $attribute) {
    $attribute_json = array();

    $code = $attribute['code'];
    // need to load the full model here. to this point it's only a small array
    // with some key items.
    $attribute = $mapper::loadProductAttributeByMagentoCode($code);

    $id = $mapper::getIdForCode($code);
    if (!$id) {
      self::_log("ERROR: could not load attribute for export. skipping: " . var_export($attribute,true));
      return null;
    }
    $attribute_json['id'] = $id;

    $this->_attribute_map[$code] = $id;

    $name = $attribute->getFrontendLabel();
    if (!$name) {
      $name = $id;
    }
    $attribute_json['name'] = $name;

    $roles = $mapper::getRolesForMagentoCode($code);
    if ($roles) {
      $attribute_json['roles'] = $roles;
    }

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
    $products = Mage::getModel('catalog/product')
                    ->getCollection();
    foreach ($products as $product) {
      $this->_write_product($product);
    }
  }

  private function _write_product($product) {
    $product_json = array();

    $id = $product->getId();
    // need to load the full product model to have access to all of its
    // attribute values.
    $product = Mage::getModel('catalog/product')
                   ->load($id);
    
    $attributes = $product->getData();
    foreach ($attributes as $key => $value) {
      if ($key === 'stock_item') {
        // for some reason the system CRASHES if you even try to refer to the
        // $value variable in this case.
        continue;
      } elseif (!$value) {
        self::_log("WARNING: value is null for key. skipping: " . var_export($key,true));
        continue;
      }

      self::_log("KEY: " . var_export($key,true));
      self::_log("VALUE: " . var_export($value,true));
      if ($key === 'media_gallery') {
        // TODO digital assets
      } elseif(array_key_exists($key, $this->_attribute_map)) {
        $salsify_id = $this->_attribute_map[$key];
        $product_json[$salsify_id] = $value;
      } else {
        self::_log("WARNING: no mapping for attribute with code. skipping.");
      }
    }

    // TODO accessories

    self::_log("PRODUCT TO WRITE: " . var_export($product,true));

    $this->_write_object($product_json);
  }
}