/**
 * Definition for salsify.connect submodule for the Salsify Connect admin
 * interfae in Magento.
 */

var salsify = salsify || {};
salsify = (function (parent) {
  var sc = parent.connect = parent.connect || {};

  sc.createImport = function () {
    alert("FIXME: need to impleent the createImport");
  };

  sc.createExport = function () {
    // this has to be done in 2 steps. the first is to create the export, and
    // the second is to kick off the worker process in the background with an
    // AJAX call that will take quite some time to return (and, in fact, we'll
    // probably have refreshed the page or gone elsewhere before it even does
    // return).

    
  };

  return parent;
}(salsify || {}));