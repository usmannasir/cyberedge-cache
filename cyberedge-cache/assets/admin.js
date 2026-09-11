(function () {
  'use strict';
  var button = document.getElementById('cyberedge-check-cache');
  var result = document.getElementById('cyberedge-cache-result');
  if (!button || !result || !window.CyberEdgeCacheAdmin) return;

  button.addEventListener('click', function () {
    button.disabled = true;
    result.className = 'cyberedge-result is-checking';
    result.textContent = 'Checking…';
    fetch(window.CyberEdgeCacheAdmin.homeUrl, {
      method: 'HEAD',
      credentials: 'omit',
      redirect: 'follow'
    }).then(function (response) {
      var value = response.headers.get(window.CyberEdgeCacheAdmin.cacheHeader);
      if (!value) {
        result.className = 'cyberedge-result is-warning';
        result.textContent = 'Header not detected';
        return;
      }
      var normalized = value.toLowerCase();
      result.className = 'cyberedge-result ' + (normalized.indexOf('hit') !== -1 ? 'is-good' : 'is-warning');
      result.textContent = value;
    }).catch(function () {
      result.className = 'cyberedge-result is-warning';
      result.textContent = 'Could not reach the public site';
    }).then(function () {
      button.disabled = false;
    });
  });
}());
