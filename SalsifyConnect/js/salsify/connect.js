var salsify = salsify || {};
salsify = (function (parent) {
  var sc = parent.connect = parent.connect || {};

  sc.createExport = function () {
    alert("IT WORKS");
  };

  return parent;
}(salsify || {}));