<?php

/**
 * Main controller for all Salsify admin actions.
 */
class Salsify_Connect_Adminhtml_IndexController extends Mage_Adminhtml_Controller_action {

  private static function _log($msg) {
    Mage::log(get_called_class() . ': ' . $msg, null, 'salsify.log', true);
  }


  // the 'salsify_connect_ment' refers to the XML element of the menu in
  // adminhtml.xml.
  const CONFIG_MENU_ID   = 'salsify_connect_menu/configuration';
  const IMPORTS_MENU_ID  = 'salsify_connect_menu/index';
  const EXPORTS_MENU_ID  = 'salsify_connect_menu/exports';
  

  // returns whether this is a POST request or not
  private function _is_post() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
  }


  private function _start_render($menu_id) {
    self::_log('rendering ' . $menu_id);

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


  // TODO remove this when there is no longer a need.
  public function testAction() {
    $this->_start_render('salsify_connect_menu/exports');

    // $salsify = Mage::helper('salsify_connect');
    // $export_file = $salsify->export_data();
    // $this->_render_html('Exported data: ' . $export_file);

    // $categories = Mage::getModel('catalog/category')
    //                   ->getCollection();
    // foreach($categories as $category) {
    //   $magento_id = $category->getId();
    //   $salsify_id = Mage::getResourceModel('catalog/category')
    //                     ->getAttributeRawValue($magento_id, 'salsify_category_id', 0);
    //   $this->_render_html('salsify id: ' . $salsify_id . '<br/>');
    // }


    $sku = '15825261';
    $url = 'http://res.cloudinary.com/salsify/image/upload/amsskkckauueek4audgm.jpg';

    $this->_render_html("Trying to filter all the mappings<br/>");
    self::_log("1");
    $mappings = Mage::getModel('salsify_connect/imagemapping')
                    ->getCollection()
                    ->addFieldToFilter('sku', array('eq' => $sku))
                    ->addFieldToFilter('url', array('eq' => $url));
    self::_log("2");
    $this->_render_html("Got collection...<br/>");
    $mapping = $mappings->getFirstItem();
    self::_log("3");
    $this->_render_html("Got first item...<br/>");
    if (!$mapping || !$mapping->getId()) {
      $this->_render_html("No matching mapping found...<br/>");
    } else {
      $this->_render_html("Matching mapping found...<br/>");
    }

    $this->_end_render();
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


  // json interface.
  // kicks off a worker. this is meant only to start a new worker thread, and as
  // such is only called via a javascript ajax call.
  public function createworkerAction() {
    self::_log("creating background worker thread...");

    $salsify = Mage::helper('salsify_connect');
    $salsify->start_worker();
    $this->_respond_with_json(array('success' => true));
  }


  /**
   * Causes all Salsify data to be cleared from the system.
   * TODO remove this when we send the plugin out to people.
   */
  public function cleanerAction() {
    $this->_start_render('salsify_connect_menu/cleaner');

    $cleaner = Mage::helper('salsify_connect/datacleaner');
    $cleaner->clean();

    $this->_render_html("<h1>Salsify Data Cleaner</h1>");
    $this->_render_html("<p>This will remove all products, all categories, and all Salsify attributes.</p>");
    $this->_render_html("<ul>");
    $this->_render_html("<li>Total products to be deleted: " . $cleaner->totalProductsDeleted() . '</li>');
    $this->_render_html("<li>Total images deleted: " . $cleaner->totalImagesDeleted() . '</li>');
    $this->_render_html("<li>Total categories trees (e.g. roots) deleted: " . $cleaner->totalCategoriesDeleted() . '</li>');
    $this->_render_html("<li>Total attributes deleted: " . $cleaner->totalAttributesDeleted() . '</li>');

    $tables_dropped = $cleaner->tablesDropped();
    foreach ($tables_dropped as $table) {
      $this->_render_html("<li>" . $table . " table dropped.</li>");
    }

    $this->_render_html("</ul>");

    $this->_end_render();
  }

}