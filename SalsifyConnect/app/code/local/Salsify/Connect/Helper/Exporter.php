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


  // list of codes to skip when exporting a product. mostly exists to surpress
  // overly chatting logs so that properties will only be talked about the first
  // time.
  private $_attribute_codes_to_skip;


  // cached mapping of magento ID to salsify ID
  private $_category_mapping;


  private function _init_skip_list() {
    $this->_attribute_codes_to_skip = array();

    // internal magento properties that we shouldn't be exporting
    array_push($this->_attribute_codes_to_skip, 'entity_id');
    array_push($this->_attribute_codes_to_skip, 'entity_type_id');
    array_push($this->_attribute_codes_to_skip, 'attribute_set_id');
    array_push($this->_attribute_codes_to_skip, 'type_id');

    // TODO are there more of these?
  }


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
      $this->_init_skip_list();
      $this->_category_mapping = array();

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
    $categories = Mage::getModel('catalog/category')
                      ->getCollection();
    foreach($categories as $category) {
      $this->_write_category($category);
    }
  }

  private function _write_category($category) {
    $category_json = array();

    $magento_id = $category->getId();
    // we're missing key data here (such as name) so need to load the whole
    // category
    $category = Mage::getModel('catalog/category')
                    ->load($magento_id);
    $level = $category->getLevel();
    
    if ($level === 1) {
      // global root. skip.
      continue;
    }

    if (!array_key_exists($magento_id, $this->_category_mapping)) {
      $this->_load_category_mapping($category);
    }
    $salsify_id = $this->_category_mapping[$magento_id];
    $category_json['id'] = $salsify_id;

    $name = $category->getName();
    $category_json['name'] = $name;

    // 2 is a relative to the root. so these are local roots.
    if ($category->getLevel() > 2) {
      $parent_id = $category->getParentId();
      if (!array_key_exists($parent_id, $this->_category_mapping)) {
        $parent_category = Mage::getModel('catalog/category')
                               ->load($parent_id);
        $this->_load_category_mapping($parent_category);
      }
      $category_json['parent_id'] = $this->_category_mapping[$parent_id];
    }

    $this->_write_object($category_json);
  }

  private function _load_category_mapping($category) {
    $magento_id = $category->getId();
    $salsify_id = Mage::getResourceModel('catalog/category')
                      ->getAttributeRawValue($magento_id, 'salsify_category_id', 0);
    if (!$salsify_id) {
      // no salsify_id yet exists. need to create one.
      $salsify_id = 'magento_' . $category->getPath();
      $category->setSalsifyCategoryId($salsify_id);
      $category->save();
    }

    $this->_category_mapping[$magento_id] = $salsify_id;
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
      if (in_array($key, $this->_attribute_codes_to_skip)) {
        // skip quietly
        continue;
      } elseif (!$value) {
        self::_log("WARNING: value is null for key. skipping: " . var_export($key,true));
        array_push($this->_attribute_codes_to_skip, $key);
        continue;
      } elseif ($key === 'media_gallery') {
        // TODO digital assets
        //      the media items don't have URLs associated with them, so maybe
        //      we want to use the mediaApi stuff like in the controller...
      } elseif(array_key_exists($key, $this->_attribute_map)) {
        $salsify_id = $this->_attribute_map[$key];
        $product_json[$salsify_id] = $value;
      } else {
        self::_log("WARNING: no mapping for attribute with code. skipping: " . var_export($key,true));
      }
    }

    // TODO category assignments
    // TODO accessories

    $this->_write_object($product_json);
  }
}