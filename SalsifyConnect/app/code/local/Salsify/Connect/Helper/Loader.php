<?php
require_once BP.DS.'lib'.DS.'JsonStreamingParser'.DS.'Listener.php';

/**
 * Parser of Salsify data. Also loads into the Magento database.
 */
class Salsify_Connect_Helper_Loader extends Mage_Core_Helper_Abstract implements \JsonStreamingParser\Listener {


  // TODO factor into a log helper
  private function _log($msg) {
    Mage::log('Loader: ' . $msg, null, 'salsify.log', true);
  }


  // Current keys and values that we're building up. We have to do it this way
  // vs. just having a current object stack because php deals with arrays as
  // pass-by-value.
  private $_key_stack;
  private $_value_stack;
  private $_type_stack; // since php doesn't have a separate hash
  const ARRAY_TYPE  = 0;
  const OBJECT_TYPE = 1;

  // Current product batch that has been read in.
  const BATCH_SIZE = 1000;
  private $_batch;

  // Current product.
  private $_product;
  
  // cached attributes
  private $_attributes;

  // current attribute
  private $_attribute;

  // keep track of nesting level during parsing
  private $_nesting_level;
  const HEADER_NESTING_LEVEL  = 2;
  const ITEM_NESTING_LEVEL = 4;

  // keeps track of current state
  private $_in_attributes;
  private $_in_products;


  public function start_document() {
    $this->_log("Starting document load.");

    $this->_key_stack = array();
    $this->_value_stack = array();
    $this->_type_stack = array();

    $this->_attributes = array();
    $this->_attribute = null;

    $this->_batch = array();
    $this->_product = null;

    $this->_nesting_level = 0;
    $this->_in_attributes = false;
    $this->_in_products = false;
  }


  public function end_document() {
    $this->_log("Finished document. Flushing final product data and reindexing.");

    $this->_flush_batch();
    $this->_reindex();
  }


  // Update all indexes in Magento.
  private function _reindex() {
    $processCollection = Mage::getSingleton('index/indexer')
                             ->getProcessesCollection(); 
    
    foreach($processCollection as $process) {
      $process->reindexEverything();
    }
  }


  public function start_object() {
    $this->_nesting_level++;

    if ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      if ($this->_in_attributes) {
        $this->_start_attribute();
      } elseif ($this->_in_products) {
        $this->_start_product();
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_start_nested_thing(self::OBJECT_TYPE);
    }
  }


  public function end_object() {
    if ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      if ($this->_attribute) {
        $this->_end_attribute();
      } elseif ($this->_product) {
        $this->_end_product();
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_end_nested_thing();
    } elseif ($this->_nesting_level === self::HEADER_NESTING_LEVEL) {
        $this->_in_attributes = false;
        $this->_in_products = false;
        // TODO other modes
    }

    $this->_nesting_level--;
  }


  private function _start_attribute() {
    $this->_attribute = array();
  }


  private function _end_attribute() {
    $this->_create_attribute_if_needed($this->_attribute);
    $this->_attribute = null;
  }


  private function _start_product() {
    $this->_product = array();

    // Add fields required by Magento.
    // FIXME query the system to get the full list of required attributes.
    //       otherwise the bulk import fails silently...

    // TODO Salsify only supports simple products right now
    $this->_product['_type'] = 'simple';

    // TODO we should be able to get the attribute set from the category
    //      somehow
    $this->_product['_attribute_set'] = 'Default';

    $this->_product['_product_websites'] = 'base';

    // TODO get these from Salsify, but need more metadata in the export to
    //      get them.
    $this->_product['price'] = 0.01;
    $this->_product['description'] = 'IMPORTED FROM SALSIFY';
    $this->_product['short_description'] = 'IMPORTED FROM SALSIFY';
    $this->_product['weight'] = 0;

    // TODO what are status 1 and visibility 4?
    $this->_product['status'] = 1;
    $this->_product['visibility'] = 4;

    // TODO seriously?
    $this->_product['tax_class_id'] = 2;
    $this->_product['qty'] = 0;
  }

  private function _end_product() {
    // TODO figure out the best solution to get multi-valued properties into
    //      Magento.
    $clean_product = array();
    foreach($this->_product as $key => $val) {
      if (is_array($val)) {
        $clean_product[$key] = implode(', ', $val);
      } else {
        $clean_product[$key] = $val;
      }
    }

    array_push($this->_batch, $clean_product);
    $this->_product = null;

    if (count($this->_batch) > self::BATCH_SIZE) {
      $this->_flush_batch();
    }
  }


  public function start_array() {
    $this->_nesting_level++;
    
    if ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_start_nested_thing(self::ARRAY_TYPE);
    }
  }


  public function end_array() {
    if ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      $this->_end_nested_thing();
    }

    $this->_nesting_level--;
  }


  private function _start_nested_thing($type) {
    array_push($this->_value_stack, array());
    array_push($this->_type_stack, $type);
  }


  private function _end_nested_thing() {
    $value = array_pop($this->_value_stack);
    $type = array_pop($this->_type_stack);

    if (empty($this->_value_stack)) {
      $key = array_pop($this->_key_stack);

      // at the root of an object
      if ($this->_in_attributes) {
        $this->_attribute[$key] = $value;
      } elseif ($this->_in_products) {
        if (array_key_exists($key, $this->_attributes)) {
          $code = $this->_attribute_code($this->_attributes[$key]);
          $this->_product[$code] = $value;
        } else {
          $this->_log("ERROR: product has key of undeclared attribute: " . $key);
        }
      }
      // TODO other types
    } else {
      // within a nested object of some kind
      $parent_value = array_pop($this->_value_stack);
      $parent_type = array_pop($this->_type_stack);
      if ($parent_type === self::ARRAY_TYPE) {
        array_push($parent_value, $value);
      } elseif ($parent_type === self::OBJECT_TYPE) {
        $key = array_pop($this->_key_stack);
        $parent_value[$key] = $value;
      }
      array_push($this->_value_stack, $parent_value);
      array_push($this->_type_stack, $parent_type);
    }
  }


  // Key will always be a string
  public function key($key) {
    array_push($this->_key_stack, $key);

    if ($this->_nesting_level === self::HEADER_NESTING_LEVEL) {
      if ($key === 'attributes') {
        $this->_log("Starting to parse attributes.");
        $this->_in_attributes = true;
      } elseif ($key === 'products') {
        $this->_log("Starting to parse products.");
        $this->_in_products = true;
      }
      // TODO other modes
    }
  }


  // Note that value may be a string, integer, boolean, array, etc.
  public function value($value) {
    if ($this->_nesting_level === self::ITEM_NESTING_LEVEL) {
      $key = array_pop($this->_key_stack);

      if ($this->_in_attributes) {
        $this->_attribute[$key] = $value;
      } elseif ($this->_in_products) {
        if (!array_key_exists($key, $this->_attributes)) {
          $this->_log('ERROR: skipping unrecognized property id on product: ' . $key);
        } else {
          $attribute = $this->_attributes[$key];
          $code = $this->_attribute_code($attribute);

          // FIXME how can we store longer properties?
          $this->_product[$code] = substr($value, 0, 255);
        }
      }
    } elseif ($this->_nesting_level > self::ITEM_NESTING_LEVEL) {
      // unbelievable how PHP doesn't have array_peek...
      $parent = array_pop($this->_value_stack);
      $type = array_pop($this->_type_stack);
      if ($type === self::ARRAY_TYPE) {
        array_push($parent, $value);
      } elseif ($type === self::OBJECT_TYPE) {
        $key = array_pop($this->_key_stack);
        $parent[$key] = $value;
      }
      array_push($this->_value_stack, $parent);
      array_push($this->_type_stack, $type);
    }
  }


  private function _flush_batch() {
    $this->_log("Flushing product batch of size: ".count($this->_batch));

    try {
      // TODO decide which of these APIs to use

      Mage::getSingleton('fastsimpleimport/import')
          ->setBehavior(Mage_ImportExport_Model_Import::BEHAVIOR_REPLACE)
          ->processProductImport($this->_batch);

      // $api = Mage::getModel('api_import/import_api');
      // $api->importEntities($this->_batch);

      $this->_batch = array();
    } catch (Exception $e) {
      $this->_log('ERROR could not flush batch: ' . $e->getMessage());
      $this->_log('Backtrace:' . $e->getTraceAsString());
    }
  }


  public function _create_attribute_if_needed($attribute) {
    $id = $attribute['id'];
    if (!array_key_exists($id, $this->_attributes)) {
      // TODO enable product type configuration here when relevant. For now we're
      //      just supporting simple products anyway (not grouped, configurable,
      //      etc.).
      //      When Salsify has bundles we'll have to deal with this.
      $product_type = 'simple';

      // TODO have types
      $type = 'text';

      $code = $this->_attribute_code($attribute);
      $dbattribute = $this->_get_attribute_from_code($code);
      if (!$dbattribute) {
        $dbattribute = $this->_create_attribute($code, $attribute, $type, $product_type);
      }

      $this->_attributes[$id] = $attribute;
    }
    return $this->_attributes[$id];
  }


  private function _attribute_code($attribute) {
    $is_id = false;
    $is_name = false;

    if (array_key_exists('roles', $attribute)) {
      $roles = $attribute['roles'];
      if (array_key_exists('products', $roles)) {
        $product_roles = $roles['products'];
        if (in_array('id', $product_roles)) {
          $is_id = true;
        }
        if (in_array('name', $product_roles)) {
          $is_name = true;
        }
      }
    }

    if ($is_id) {
      return 'sku';
    } elseif ($is_name) {
      return 'name';
    }
    // TODO get other OOTB type attributes via mapping from Salsify.

    // code can only be 30 characters at most and cannot contain spaces
    // creating a checksum seemed to be the easiest way to accomplish that,
    // though it has the downside of creating opaque atttribute_ids which do
    // show up in the admin panel...
    //
    // Could also have edited this file:
    //   /app/code/core/Mage/Eav/Model/Entity/Attribute.php
    //   CONST ATTRIBUTE_CODE_MAX_LENGTH = 60;
    $code = 'salsify_'.md5($attribute['name']);
    $code = substr($code, 0, 30);
    return $code;
  }


  // Thanks http://www.sharpdotinc.com/mdost/2009/04/06/magento-getting-product-attributes-values-and-labels/
  private function _get_attribute_from_code($code) {
    $attributeId = Mage::getResourceModel('eav/entity_attribute')
                       ->getIdByCode('catalog_product', $code);
    if (!$attributeId) {
      return null;
    }
    $attribute = Mage::getModel('catalog/resource_eav_attribute')
                     ->load($attributeId);
    return $attribute;
  }


  // Thanks to http://inchoo.net/ecommerce/magento/programatically-create-attribute-in-magento-useful-for-the-on-the-fly-import-system/
  // as a starting point.
  // More docs: http://www.magentocommerce.com/wiki/5_-_modules_and_development/catalog/programmatically_adding_attributes_and_attribute_sets
  private function _create_attribute($code, $attribute, $attribute_type, $product_type) {
    // There are even more options that we're not setting here. For example:
    // http://alanstorm.com/magento_attribute_migration_generator

    $name = $attribute['name'];

    // I *think* this is everything we COULD be setting, with some properties
    // commented out. I got values from eav_attribute and catalog_eav_attribute

    // TODO should we be flexible on global vs. store? what elements here should
    //      be configurable during an import?

    $_attribute_data = array(
      'attribute_code' => $code,
      'note' => 'Added automatically during Salsify import',
      'default_value_text' => '',
      'default_value_yesno' => 0,
      'default_value_date' => '',
      'default_value_textarea' => '',
      // # default_value - set below

      // These are available but shouldn't be set here.
      // # attribute_model
      // # backend_model
      // # backend_type - set below
      // # backend_table
      // # source_model

      'is_user_defined' => 0,
      'is_global' => 1,
      'is_unique' => 0,
      'is_required' => 0,
      // # is_visible
      'is_configurable' => 0,
      'is_searchable' => 0,
      'is_filterable' => 0,
      'is_filterable_in_search' => 0,
      'is_visible_in_advanced_search' => 0,
      'is_comparable' => 0,
      'is_used_for_price_rules' => 0,
      'is_wysiwyg_enabled' => 0,
      'is_html_allowed_on_front' => 0,
      'is_visible_on_front' => 1,
      // # is_used_for_promo_rules
      'used_in_product_listing' => 0,
      'used_for_sort_by' => 0,
      'type' => $attribute_type,
      // # position

      // # frontend_model
      // # frontend_class
      // TODO the frontend_input can't be varchar, so there is a mismatch here...
      'frontend_input' => $attribute_type, //'boolean','text', etc.
      'frontend_label' => $name,
      // # frontend_input_renderer

      // without this it will not show up in the UI
      'group' => 'General',

      // TODO apply_to multiple types by default? right now Salsify itself only
      //      really supports the simple type.
      'apply_to' => array($product_type), //array('grouped') see http://www.magentocommerce.com/wiki/modules_reference/english/mage_adminhtml/catalog_product/producttype
    );

    $model = Mage::getModel('catalog/resource_eav_attribute');

    if (is_null($model->getIsUserDefined()) || $model->getIsUserDefined() != 0) {
      // required to let Magento know how to store the values for this attribute
      // in their EAV setup.
      $_attribute_data['backend_type'] = $model->getBackendTypeByInput($_attribute_data['frontend_input']);
    }

    $defaultValueField = $model->getDefaultValueByInput($_attribute_data['frontend_input']);
    if ($defaultValueField) {
      $_attribute_data['default_value'] = $_attribute_data[$defaultValueField];
    }

    $model->addData($_attribute_data);
    $model->setIsUserDefined(1);

    // Need to add the properties to a specific group of they don't show up in
    // the admin UI at all. In the future we might want to make this an option
    // so that we don't pollute the general attribute set. Maybe dumping all
    // into a Salsify group?

    // originally:
    // $model->setEntityTypeId(Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId());
    $entityTypeId = Mage::getModel('eav/entity')
                        ->setType('catalog_product')
                        ->getTypeId();
    $model->setEntityTypeId($entityTypeId);

    $attributeSetId = Mage::getModel('catalog/product')
                          ->getResource()
                          ->getEntityType()
                          ->getDefaultAttributeSetId();
    $model->setAttributeSetId($attributeSetId);

    # wish I knew a better way to do this without having to get the core setup...
    $setup = new Mage_Eav_Model_Entity_Setup('core_setup');
    $attributeGroupId = $setup->getDefaultAttributeGroupId($entityTypeId, $attributeSetId);
    $model->setAttributeGroupId($attributeGroupId);

    try {
      $model->save();
    } catch (Exception $e) {
      $this->_log('ERROR: could not create attribute <'.$name.'>: '.$e->getMessage());
    }

    // should be in the DB now
    return $this->_get_attribute_from_code($code);
  }
}