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

      $this->_create_attribute_if_needed($this->_key);
      // FIXME need to create the attribute value as well if needed

      $this->_key = null;
    }
  }


  private function _flush_batch() {

    // FIXME need to create new column names if they don't already exist

    Mage::getSingleton('fastsimpleimport/import')
        ->setBehavior(Mage_ImportExport_Model_Import::BEHAVIOR_REPLACE)
        ->processProductImport($this->_batch);
    $this->_batch = array();
  }


  private function _create_attribute_if_needed($name) {
    // $setup->addAttribute('catalog_product', 'product_type', array(
    //   // 'group'             => 'Product Options',
    //   // 'label'             => 'Product Type',
    //   'label'             => $name,
    //   'note'              => 'Added automatically during Salsify import',
    //   'type'              => 'text',    //backend_type
    //   // 'input'             => 'text',  //frontend_input
    //   // 'frontend_class'    => '',
    //   // 'source'            => 'sourcetype/attribute_source_type',
    //   // 'backend'           => '',
    //   // 'frontend'          => '',
    //   'global'            => Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_STORE,
    //   'required'          => false,
    //   'visible_on_front'  => false,
    //   // 'apply_to'          => 'simple',
    //   // 'is_configurable'   => false,
    //   'used_in_product_listing' => true
    //   // 'sort_order'        => 5,
    // ));

    // FIXME need to figure out if the attribute already exists.

    $this->_create_attribute($name, 'text', 'simple');
  }


  // Thanks to http://inchoo.net/ecommerce/magento/programatically-create-attribute-in-magento-useful-for-the-on-the-fly-import-system/
  // as a starting point.
  public function _create_attribute($label, $attribute_type, $product_type) {
    // code can only be 30 characters at most and cannot contain spaces
    // creating a checksum seemed to be the easiest way to accomplish that,
    // though it has the downside of creating opaque atttribute_ids which do
    // show up in the admin panel...
    $code = 'salsify_'.(($product_type) ? $product_type : 'joint').'_'.md5($label);
    $code = substr($code, 0, 30);
    echo '<br/>code: '.$code;

    $_attribute_data = array(
      'attribute_code' => $code,
      'default_value_text' => '',
      'default_value_yesno' => '0',
      'default_value_date' => '',
      'default_value_textarea' => '',
      'is_global' => '1',
      'is_unique' => 0,
      'is_required' => '0',
      'is_configurable' => 0,
      'is_searchable' => 0,
      'is_filterable' => 0,
      'is_filterable_in_search' => 0,
      'is_visible_in_advanced_search' => '0',
      'is_comparable' => '0',
      'is_used_for_price_rules' => '0',
      'is_wysiwyg_enabled' => '0',
      'is_html_allowed_on_front' => '1',
      'is_visible_on_front' => '0',
      'used_in_product_listing' => '0',
      'used_for_sort_by' => '0',
      'frontend_input' => $attribute_type, //'boolean','text', etc.
      'frontend_label' => array('Salsify Attribute '.(($product_type) ? $product_type : 'joint').' '.$label),
      'apply_to' => array($product_type), //array('grouped') see http://www.magentocommerce.com/wiki/modules_reference/english/mage_adminhtml/catalog_product/producttype
    );

    $model = Mage::getModel('catalog/resource_eav_attribute');

    if (is_null($model->getIsUserDefined()) || $model->getIsUserDefined() != 0) {
      echo '<br/>HERE';
      $_attribute_data['backend_type'] = $model->getBackendTypeByInput($_attribute_data['frontend_input']);
    }

    $defaultValueField = $model->getDefaultValueByInput($_attribute_data['frontend_input']);
    if ($defaultValueField) {
      $_attribute_data['default_value'] = $_attribute_data[$defaultValueField];
    }

    $model->addData($_attribute_data);
    $model->setEntityTypeId(Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId());
    $model->setIsUserDefined(1);
    try {
      $model->save();
    } catch (Exception $e) {
      Mage::log(
        'ERROR: could not create attribute <'.$label.'>: '.$e->getMessage(),
        null, 
        'salsify.log',
        true
      );
    }
  }
}