<?php

set_include_path(get_include_path().PS.Mage::getBaseDir('lib').DS.'DJJob');
require_once('DJJob.php');

class Salsify_Connect_Adminhtml_ManageImportsController extends Mage_Adminhtml_Controller_action {


  const INDEX_MENU_ID  = 'salsify_connect_menu/manage_imports';
  const CONFIG_MENU_ID = 'salsify_connect_menu/configuration';


  // FIXME factor into a log helper
  private function _log($msg) {
    Mage::log('ManageImports: ' . $msg, null, 'salsify.log', true);
  }


  private function _start_render($menu_id) {
    $this->_log('rendering '.$menu_id);

    $this->loadLayout();
    $this->_setActiveMenu($menu_id);

    // add a left block to the layout
    // FIXME add links to the menu items for easy use on the left
    //       not sure how to create a link in magento to a resource, though...
    $this->_addLeft($this->getLayout()
                         ->createBlock('core/text')
                         ->setText('<h1>FUTURE SITE OF SWEET MENU</h1>'));
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

 
  // FIXME remove when we're going live. this is just for testing.
  public function testAction() {
    $this->_start_render('salsify_connect_menu/test');
    $this->_render_html('<h1>This is a header</h1>');
    $this->_render_html('<div>This is a text block</div>');
    $this->_end_render();
  }


  public function indexAction() {
    $this->_start_render(self::INDEX_MENU_ID);

    // FIXME load the block that we want to load programatically
    //       try to load the Salsify_Connect_Block_Adminhtml_ManageImports
    //       block as it SHOULD be able to do the trick...
    $usage = 'usage:'
           . '<br/>&nbsp;&nbsp;salsify/index/testload - loads a pre-saved test file. just for testing import.'
           . '<br/>&nbsp;&nbsp;salsify/index/config?api_key=YOURKEY&salsify_url=YOURURL - creates a config for export usage.'
           . '<br/>&nbsp;&nbsp;salsify/index/export?config=ID - kicks off an export using config ID 1.'
           . '<br/>&nbsp;&nbsp;salsify/index/chexport?id=ID - checks the status of export with ID 1 and advances it if ready.';
    $this->_render_html($usage);


    // FIXME how do we get the block to show here?

    // saw this:
    // $this->getLayout()->getBlock('head')->setCanLoadExtJs(true);

    // tried this:
    // $imports_block = Mage::getBlock('salsify_connect/adminhtml_manageimports');
    // $this->_addContent($importsblock);


    $this->_end_render();
  }


  public function configurationAction() {
    $this->_start_render(self::CONFIG_MENU_ID);

    // must render this via a block
    $this->_render_html('<h1>COMING SOON!</h1>');

    $this->_end_render();
  }


  // FIXME only used for development purposes. remove once no longer necessary.
  public function testloadAction() {
    $this->_start_render('salsify_connect_menu/testload');
    
    $salsify = Mage::helper('salsify_connect');
    $file = BP.DS.'var'.DS.'salsify'.DS.'export.json';
    $salsify->load_data($file);

    $this->_render_html("<h1>Import Succeeded</h1>");
    $this->_render_html("Imported from: " . $file);

    $this->_end_render();
  }

  // FIXME make this into a form that the user can use to enter a configuration
  public function configAction() {
    // $params = $this->getRequest()->getParams();

    // if (!array_key_exists('api_key', $params)) {
    //   throw new Exception("Must specify api_key to use for import.");
    // }
    // $api_key = $params['api_key'];

    // if (!array_key_exists('salsify_url', $params)) {
    //   throw new Exception("Must specify salsify_url to use for import.");
    // }
    // $url = urldecode($params['salsify_url']);

    $api_key = 'yNoKZx9UabqqQ1m2c6K2';
    $url = 'http://localhost:5000/';

    $config = Mage::getModel('salsify_connect/configuration');
    $config->setApiKey($api_key);
    $config->setUrl($url);
    $config->save();

    echo '<br/>configuration created: ' . $config->getId();
  }

  // FIXME remove for dev
  public function exportAction() {
    echo '<br/>creating new export from Salsify...';

    // $params = $this->getRequest()->getParams();
    // if (!array_key_exists('config', $params)) {
    //   throw new Exception("Must specify configuration ID to use for import.");
    // }
    // $config_id = $params['config'];
    $config_id = 1;

    $model = Mage::getModel('salsify_connect/importrun');
    $model->setConfigurationId($config_id);
    $model->save();
    $model->start_import();

    echo '<br/>created. go to salsify/index/chexport/id/'.($model->getId()).' to check the status';

    // TODO use jquery to automatically check for updates so that the user
    //      doesn't have to refresh the screen.
  }

  // FIXME make this into some kind of polling/monitoring/restful thing that
  //       is called by JS from the manage_imports main area
  public function chexportAction() {
    // $params = $this->getRequest()->getParams();
    // $import_id = $params['id'];
    $import_id = 1;

    $import = Mage::getModel('salsify_connect/importrun');
    $import->load((int)$import_id);
    if (!$import->getId()) {
      throw new Exception("Must specify a valid import ID.");
    }
    echo "Current status: " . $import->get_status_string();

    if (!$import->is_done()) {
      echo "<br/><br/>Attempting next stage...";
      if($import->is_waiting_on_salsify()) {
        $advanced = $import->start_download_if_ready();
        if (!$advanced) {
          echo '<br/>Still waiting on Salsify.';
        } else {
          echo '<br/>Download is ready. Enqueued background job to complete import.';
          $this->sneaky_worker_thread_start();
        }
      } elseif ($import->is_waiting_on_worker()) {
        echo '<br/>Still waiting on background worker to pick up the job.';
        $this->sneaky_worker_thread_start();
      }
    }
  }

  private function sneaky_worker_thread_start() {
    $jquery = '<script src="//ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>';
    $sneaky = '<script type="text/javascript">$.get("/salsify/admin_html/mangeimports/worker");</script>';
    $this->_render_js($jquery);
    $this->_render_js($sneaky);

    // FIXME not sure if this works  with new factoring
    // FIXME add a local jquery fallback (mostly for offline testing)
  }

  public function workerAction() {
    // TODO add a one-time token or something like that to enable this to be
    //      called safely.
    $job = Mage::getModel('salsify_connect/importjob');
    $job->start_worker();
  }

}