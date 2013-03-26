<?php

/**
 * Main controller for all Salsify admin actions.
 */
class Salsify_Connect_Adminhtml_IndexController extends Mage_Adminhtml_Controller_action {

  private static function _log($msg) {
    Mage::log(get_called_class() . $msg, null, 'salsify.log', true);
  }


  // TODO these necessary here?
  const CONFIG_MENU_ID = 'salsify_connect_menu/configuration';
  const IMPORTS_MENU_ID  = 'salsify_connect_menu/index';
  const EXPORTS_MENU_ID  = 'salsify_connect_menu/exports';
  

  // returns whether this is a POST request or not
  private function _is_post() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
  }


  private function _start_render($menu_id) {
    self::_log('rendering '.$menu_id);

    $this->loadLayout();
    $layout = $this->getLayout();

    // deal with the menu and breadcrumbs.
    $this->_setActiveMenu($menu_id)
         ->_title('Salsify Connect');
         // currently we're not really showing any of this stuff
         // ->_title($this->__('My Menu Item'))
         // ->_addBreadcrumb('Salsify Connect', 'Salsify Connect')
         // ->_addBreadcrumb($this->__('My Menu Item'), $this->__('My Menu Item'));

    $menu_block = $layout->createBlock('salsify_connect/adminhtml_menu');
    $menu_block->setActions(array(
      array('label' => 'Salsify Account Details', 'action' => 'configuration'),
      array('label' => 'Manage Imports from Salsify', 'action' => 'index'),
      array('label' => 'Manage Exports to Salsify', 'action' => 'exports')
    ));
    $this->_addLeft($menu_block);
  }


  private function _render_html($html) {
    $block = $this->getLayout()
                  ->createBlock('core/text')
                  ->setText($html);
    $this->_addContent($block);
  }


  private function _render_js($js) {
    $jsblock = $this->getLayout()
                    ->createBlock('core/text')
                    ->setText($js);
    $this->_addJs($jsblock);
  }


  private function _end_render() {
    $this->renderLayout();
  }


  // helper method to return json from a function
  private function _respond_with_json($obj) {
    $response = $this->getResponse();
    $response->setHeader('Content-type', 'application/json');
    $response->setBody(Mage::helper('core')->jsonEncode($obj));
  }


   /**
   * Check currently called action by permissions for current user
   *
   * @return bool
   */
  protected function _isAllowed() {
      return Mage::getSingleton('admin/session')
                 ->isAllowed('salsify_connect/adminhtml_index');
  }


  /**
   * Action for displaying and editing Salsify account details.
   */
  public function configurationAction() {
    if ($this->_is_post()) {
      if (array_key_exists('configuration', $_POST)) {
        $config_data = $_POST['configuration'];
        
        $config = Mage::getModel('salsify_connect/configuration')->getInstance();

        if (array_key_exists('api_key', $config_data)) {
          $config->setApiKey($config_data['api_key']);
        } else {
          $config->setApiKey('');
        }

        if (array_key_exists('url', $config_data)) {
          $config->setUrl($config_data['url']);
        } else {
          $config->setUrl('');
        }

        $config->save();
      } else {
        self::_log("WARNING: POST to config without a configuration element.");
      }
    }

    $this->_start_render(self::CONFIG_MENU_ID);
    $this->_end_render();
  }


  /**
   * Action for managing imports from Salsify to Magento.
   */
  public function indexAction() {
    $this->_start_render(self::IMPORTS_MENU_ID);
    // everything for managing imports already taken care of by standard layout
    // stuff.
    $this->_end_render();
  }


  /**
   * Action for managing exports from Magento to Salsify.
   *
   * Same as Index, actually.
   */
  public function exportsAction() {
    $this->_start_render(self::EXPORTS_MENU_ID);
    // everything for managing exports already taken care of by standard layout
    // stuff.
    $this->_end_render();
  }


  // json interface.
  // creates a new import.
  // TODO need to give better error messages and show them in the client
  public function createimportAction() {
    self::_log("creating import run...");

    $model = Mage::getModel('salsify_connect/importrun');
    $model->save();
    $model->setName('Import from Salsify #' . $model->getId())
          ->enqueue();

    $this->_respond_with_json(array('success' => true));
  }


  // json interface.
  // creates a new export
  // TODO need to give better error messages and show them in the client
  public function createexportAction() {
    self::_log("creating export run...");

    $model = Mage::getModel('salsify_connect/exportrun');
    $model->save();
    $model->setName('Export to Salsify #' . $model->getId())
          ->enqueue();

    $this->_respond_with_json(array('success' => true));
  }


  // kicks off a worker. this is meant only to start a new worker thread, and as
  // such is only called via a javascript ajax call.
  public function createworkerAction() {
    self::_log("creating background worker thread...");

    $salsify = Mage::helper('salsify_connect');
    $salsify->start_worker();
    $this->_respond_with_json(array('success' => true));
  }


  // TODO should refactor this and move accessors that know a lot about Magento
  //      internals into the generic Data.php for reuse elsewhere.
  public function cleanerAction() {
    $this->_start_render('salsify_connect_menu/cleaner');

    $this->_render_html("<h1>Salsify Data Cleaner</h1>");
    $this->_render_html("<p>This will remove all products, all categories, and all Salsify attributes.</p>");
    $this->_render_html("<ul>");

    self::_log("Cleaner: deleting products...");
    $products = Mage::getModel('catalog/product')
                    ->getCollection();
                    // if you just wanted to delete salsify data...
                    // ->addFieldToFilter('price', array("eq"=>0.0100));
    $this->_render_html("<li>Total products to be deleted: " . count($products) . '</li>');
    $image_count = 0;
    foreach($products as $product) {
      $id = $product->getId();

      $product = Mage::getModel('catalog/product')->load($id);

      // various ways to do this, none pretty.
      // http://stackoverflow.com/questions/5709496/magento-programmatically-remove-product-images
      $mediaApi = Mage::getModel("catalog/product_attribute_media_api");
      $items = $mediaApi->items($id);
      foreach($items as $item) {
        $file = $item['file'];
        $mediaApi->remove($id, $file);
        $file = Mage::getBaseDir('media').DS.'catalog'.DS.'product'.$file;
        unlink($file);
        $image_count += 1;
      }

      $product->delete();
    }
    $this->_render_html("<li>Total images deleted: " . $image_count . '</li>');
    self::_log("Cleaner: products and images deleted.");

    self::_log("Cleaner: deleting Salsify categories...");
    $categories = Mage::getModel('catalog/category')
                      ->getCollection();
    $cat_count = 0;
    foreach($categories as $category) {
      if ($category->getLevel() === '1') {
        // failsafe on the root, which i've deleted a few times...
        continue;
      }

      $id = Mage::getResourceModel('catalog/category')
                ->getAttributeRawValue($category->getId(), 'salsify_category_id', 0);
      // this messed things up for some reason...
      // if ($category->getId() != 1) {
      if ($id) {
        $category->delete();
        $cat_count++;
      }
    }
    $this->_render_html("<li>Total categories trees (e.g. roots) deleted: " . $cat_count . '</li>');
    self::_log("Cleaner: categories deleted.");

    self::_log("Cleaner: deleting attributes...");
    $mapper = Mage::getModel('salsify_connect/attributemapping');
    $attr_count = $mapper::deleteSalsifyAttributes();
    $this->_render_html("<li>Total attributes deleted: " . $attr_count . '</li>');
    self::_log("Cleaner: attributes deleted.");

    try {
      $db = Mage::getSingleton('core/resource')
                ->getConnection('core_write');

      $db->query("drop table jobs;");
      $this->_render_html("<li>Jobs table dropped.</li>");

      $db->query("drop table salsify_connect_attribute_mapping;");
      $this->_render_html("<li>Attribute mapping table dropped.</li>");

      $db->query("drop table salsify_connect_import_run;");
      $this->_render_html("<li>Import run table dropped.</li>");

      $db->query("drop table salsify_connect_export_run;");
      $this->_render_html("<li>Export run table dropped.</li>");

      $db->query("drop table salsify_connect_configuration;");
      $this->_render_html("<li>Import configuration table dropped.</li>");

      $db->query("delete from core_resource where code = 'salsify_connect_setup';");
      $this->_render_html("<li>Salsify db installation removed (hitting any Salsify admin URL will recreate the tables).</li>");
    } catch (Exception $e) {
      self::_log("FAIL: " . $e->getMessage());
      throw $e;
    }

    $this->_render_html("</ul>");

    $this->_end_render();
  }

}