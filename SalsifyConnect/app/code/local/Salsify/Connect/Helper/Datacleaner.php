<?php

/**
 * Deletes all local Salsify data.
 */
class Salsify_Connect_Helper_Datacleaner extends Mage_Core_Helper_Abstract {
  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }


  private $_total_products;
  private $_total_images;
  private $_total_categories;
  private $_total_attributes;
  private $_tables_dropped;


  public function __construct() {
    $this->_total_products = 0;
    $this->_total_images = 0;
    $this->_total_categories = 0;
    $this->_total_attributes = 0;
    $this->_tables_dropped = array();
  }


  public function totalProductsDeleted() {
    return $this->_total_products;
  }

  public function totalImagesDeleted() {
    return $this->_total_images;
  }

  public function totalCategoriesDeleted() {
    return $this->_total_categories;
  }

  public function totalAttributesDeleted() {
    return $this->_total_attributes;
  }

  public function tablesDropped() {
    return $this->_tables_dropped;
  }


  // clears all data from Salsify.
  public function clean() {
    self::_log("deleting all salsify data...");
    $this->_delete_products()
         ->_delete_categories()
         ->_delete_attributes()
         ->_drop_tables();
    return $this;
  }


  private function _delete_products() {
    self::_log("deleting products and associated images...");

    $products = Mage::getModel('catalog/product')
                    ->getCollection();
                    // if you just wanted to delete salsify data...
                    // ->addFieldToFilter('price', array("eq"=>0.0100));
    $this->_total_products = count($products);

    $this->_total_images = 0;
    $mediaApi = Mage::getModel("catalog/product_attribute_media_api");
    foreach($products as $product) {
      $id = $product->getId();

      $product = Mage::getModel('catalog/product')->load($id);

      // various ways to do this, none pretty.
      // http://stackoverflow.com/questions/5709496/magento-programmatically-remove-product-images
      $items = $mediaApi->items($id);
      foreach($items as $item) {
        $file = $item['file'];
        $mediaApi->remove($id, $file);
        $file = Mage::getBaseDir('media').DS.'catalog'.DS.'product'.$file;
        try {
          unlink($file);
        } catch (Exception $e) {
          self::_log("WARNING: could not unlink file " . $file . ": " . $e->getMessage());
        }
        $this->_total_images += 1;
      }

      $product->delete();
    }

    self::_log($this->_total_products . " products and " . $this->_total_images . " images deleted.");
    return $this;
  }


  private function _delete_categories() {
    $this->_total_categories = 0;

    self::_log("deleting Salsify categories...");
    $categories = Mage::getModel('catalog/category')
                      ->getCollection();
    
    foreach($categories as $category) {
      $id = Mage::getResourceModel('catalog/category')
                ->getAttributeRawValue($category->getId(), 'salsify_category_id', 0);
      if ($id) {
        self::_log("ID OF THING: " . $id);
        $category->delete();
        $this->_total_categories++;
      }
    }
    self::_log($this->_total_categories . " categories deleted.");

    return $this;
  }


  private function _delete_attributes() {
    self::_log("deleting attributes...");
    $mapper = Mage::getModel('salsify_connect/attributemapping');
    $this->_total_attributes = $mapper::deleteSalsifyAttributes();
    self::_log($this->_total_attributes . "attributes deleted.");

    return $this;
  }


  private function _drop_tables() {
    self::_log("dropping tables...");

    try {
      $db = Mage::getSingleton('core/resource')
                ->getConnection('core_write');

      // TODO this could be pretty awkward if someone is already using DJJob
      //      with some other Magento Connect plugin...
      $this->_drop_table($db, 'jobs')
           ->_drop_table($db, 'salsify_connect_attribute_mapping')
           ->_drop_table($db, 'salsify_connect_image_mapping')
           ->_drop_table($db, 'salsify_connect_accessorycategory_mapping')
           ->_drop_table($db, 'salsify_connect_accessory_mapping')
           ->_drop_table($db, 'salsify_connect_import_run')
           ->_drop_table($db, 'salsify_connect_export_run')
           ->_drop_table($db, 'salsify_connect_configuration');

      $db->query("delete from core_resource where code = 'salsify_connect_setup';");
      self::_log("Salsify db installation removed (so now hitting any Salsify admin URL will recreate the tables).");
    } catch (Exception $e) {
      self::_log("ERROR dropping Salsify Connect tables: " . $e->getMessage());
      throw $e;
    }

    self::_log("done dropping all Salsify Connect tables.");
    return $this;
  }

  private function _drop_table($db, $table) {
    $db->query("drop table " . $table . ";");
    self::_log($table . " table dropped.");
    array_push($this->_tables_dropped, $table);
    return $this;
  }
}