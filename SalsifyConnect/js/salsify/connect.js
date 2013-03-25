/**
 * Definition for salsify.connect submodule for the Salsify Connect admin
 * interface in Magento.
 *
 * NOTE: we're using Prototype's Ajax here since that library is automatically
 *       loaded by Magento, and it felt unnecessary to introduce a more modern
 *       JS library for the basic calls that we're making here.
 */

var salsify = (function (parent) {
  var sc = parent.connect = parent.connect || {};


  // creates a background worker
  function createWorker(workerUrl) {
    new Ajax.Request(workerUrl, {
      onSuccess: function(response) {
        // note that this is unlikely to ever be called since the page will
        // almost always be reloaded before this callback is given a chance.
      }
    });
  }


  // creates and kicks off a new import job from Salsify.
  //
  // the createUrl and workerUrl parameters are necessary since they contain
  // extra information (noteably security stuff) that we won't know in advance.
  sc.createImport = function (createUrl, workerUrl) {
    alert("FIXME: need to impleent the createImport");
  };


  sc.createExport = function (createUrl, workerUrl) {
    // this has to be done in 2 steps. the first is to create the export, and
    // the second is to kick off the worker process in the background with an
    // AJAX call that will take quite some time to return (and, in fact, we'll
    // probably have refreshed the page or gone elsewhere before it even does
    // return).

    // first create the export run...
    new Ajax.Request(createUrl, {
      method: 'get',
      onSuccess: function(response) {
        // next kickoff the background worker
        createWorker(workerUrl);

        // reload the page to show the newly created object
        document.location.reload(true);
      }
    });
  };

  return parent;
}(salsify || {}));