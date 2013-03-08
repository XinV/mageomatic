<?php

set_include_path(get_include_path().PS.Mage::getBaseDir('lib').DS.'DJJob');
require_once('DJJob.php');

class Salsify_Connect_Adminhtml_ManageImportsController extends Mage_Adminhtml_Controller_action {

  private static function _log($msg) {
    Mage::log('ManageImports: ' . $msg, null, 'salsify.log', true);
  }


  // TODO remove
  const API_KEY = 'Vy5s4ohFoQYAqJ1kR5Pz';

  // TODO remove
  const BASE_ADMIN_URL = 'salsify/adminhtml_manageimports/';

  // TODO these necessary here?
  const INDEX_MENU_ID  = 'salsify_connect_menu/manage_imports';
  const CONFIG_MENU_ID = 'salsify_connect_menu/configuration';


  // TODO remove
  private function _get_url($action) {
    return Mage::helper("adminhtml")
               ->getUrl(self::BASE_ADMIN_URL . $action);
  }

  private function _start_render($menu_id) {
    self::_log('rendering '.$menu_id);

    $this->loadLayout();
    $layout = $this->getLayout();

    // deal with the menu
    $this->_setActiveMenu($menu_id);

    $block = $layout->createBlock('salsify_connect/adminhtml_menu');
    $block->setActions(array(
      array('label' => 'Configure Salsify', 'action' => 'config'),
      array('label' => 'Import from Salsify', 'action' => 'config'),
      array('label' => 'Export to Salsify', 'action' => 'export')
    ));
    $this->_addLeft($block);
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

 
  // TODO remove when we're going live. this is just for testing.
  public function testAction() {
    $this->_start_render('salsify_connect_menu/test');

    $this->_render_html('<h1>Trying to render custom block</h1>');

    $this->_end_render();
  }


  public function exportAction() {
    $this->_start_render('salsify_connect_menu/export');

    $salsify = Mage::helper('salsify_connect');
    $salsify->export_data('http://127.0.0.1:5000', self::API_KEY);

    $this->_render_html("<h1>Export to Salsify complete.</h1>");

    $this->_end_render();
  }


  public function indexAction() {
    $this->_start_render(self::INDEX_MENU_ID);

    // TODO load the block that we want to load programatically
    //      try to load the Salsify_Connect_Block_Adminhtml_ManageImports
    //      block as it SHOULD be able to do the trick...
    $configurl = $this->_get_url('config');
    $usage = '<h1>Import Process</h1>'
           . '<ul>'
           . '  <li><a href="'.$configurl.'">Create a configuration</a></li>'
           . '</ul>';
    $this->_render_html($usage);


    // TODO how do we get the block to show here?

    // saw this:
    // $this->getLayout()->getBlock('head')->setCanLoadExtJs(true);

    // tried this:
    // $imports_block = Mage::getBlock('salsify_connect/adminhtml_manageimports');
    // $this->_addContent($imports_block);

    $this->_end_render();
  }


  public function configurationAction() {
    $this->_start_render(self::CONFIG_MENU_ID);

    // must render this via a block
    $this->_render_html('<h1>COMING SOON!</h1>');

    $this->_end_render();
  }


  // TODO only used for development purposes. remove once no longer necessary.
  public function testloadAction() {
    $this->_start_render('salsify_connect_menu/testload');
    
    $salsify = Mage::helper('salsify_connect');
    $file = BP.DS.'var'.DS.'salsify'.DS.'simple.json';
    $salsify->import_data($file);

    $this->_render_html("<h1>Import Succeeded</h1>");
    $this->_render_html("Imported from: " . $file);

    $this->_end_render();
  }


  // TODO make this into a form that the user can use to enter a configuration
  public function configAction() {
    $this->_start_render('salsify_connect_menu/config');

    // $params = $this->getRequest()->getParams();

    // if (!array_key_exists('api_key', $params)) {
    //   throw new Exception("Must specify api_key to use for import.");
    // }
    // $api_key = $params['api_key'];

    // if (!array_key_exists('salsify_url', $params)) {
    //   throw new Exception("Must specify salsify_url to use for import.");
    // }
    // $url = urldecode($params['salsify_url']);

    $url = 'http://127.0.0.1:5000/';

    $config = Mage::getModel('salsify_connect/configuration');
    $config->setApiKey(self::API_KEY);
    $config->setUrl($url);
    $config->save();

    $id = $config->getId();
    $import_url = $this->_get_url('import') . '?config=' . $id;

    $this->_render_html('<h1>Configuration created: ' . $id . '</h1>');
    $this->_render_html('Next: <a href="'.$import_url.'">Kick off import</a>');

    $this->_end_render();
  }

  // TODO remove for production
  public function importAction() {
    $params = $this->getRequest()->getParams();
    if (!array_key_exists('config', $params)) {
      throw new Exception("Must specify configuration ID to use for import.");
    }
    $config_id = $params['config'];

    $model = Mage::getModel('salsify_connect/importrun');
    $model->setConfigurationId($config_id);
    $model->save();
    $model->start_import();

    $url = $this->_get_url('chimport') . '?id=' . $model->getId();
    $this->_redirectUrl($url);
  }


  // TODO make this into some kind of polling/monitoring/restful thing that
  //      is called by JS from the manage_imports main area
  public function chimportAction() {
    $this->_start_render('salsify_connect_menu/chimport');

    $params = $this->getRequest()->getParams();
    $import_id = $params['id'];

    $import = Mage::getModel('salsify_connect/importrun');
    $import->load((int)$import_id);
    if (!$import->getId()) {
      throw new Exception("Must specify a valid import ID.");
    }
    $this->_render_html("Current status: " . $import->get_status_string() . '<br/>');

    if (!$import->is_done()) {
      $this->_render_html("<br/><br/>Attempting next stage...");
      if($import->is_waiting_on_salsify()) {
        $advanced = $import->start_download_if_ready();
        if (!$advanced) {
          $this->_render_html('<br/>Still waiting on Salsify.');
        } else {
          $this->_render_html('<br/>Download is ready. Enqueued background job to complete import.');
          $this->sneaky_worker_thread_start();
        }
      } elseif ($import->is_waiting_on_worker()) {
        $this->_render_html('<br/>Still waiting on background worker to pick up the job.');
        $this->sneaky_worker_thread_start();
      }
    }

    $url = $this->_get_url('chimport') . '?id=' . $import_id;
    $this->_render_html('<br><a href="'.$url.'">Re-chimport</a>');

    $this->_end_render();
  }

  private function sneaky_worker_thread_start() {
    $worker_url = $this->_get_url('worker');

    $worker_js = "
    <script type=\"text/javascript\">
      new Ajax.Request('" . $worker_url ."', {
        onSuccess: function(response) {
          // woo hoo!
        }
      });
    </script>";
    $this->_render_js($worker_js);
  }


  public function workerAction() {
    self::_log("Worker: creating a worker!");

    // TODO add a one-time token or something like that to enable this to be
    //      called safely.
    $job = Mage::getModel('salsify_connect/importjob');
    $job->start_worker();
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