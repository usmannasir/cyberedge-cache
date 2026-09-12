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
      // CyberEdge deliberately does not cache HEAD responses, so use an
      // anonymous GET to exercise the same path a visitor actually receives.
      method: 'GET',
      credentials: 'omit',
      redirect: 'follow'
    }).then(function (response) {
      var value = response.headers.get(window.CyberEdgeCacheAdmin.cacheHeader);
      if (!value) {
        result.className = 'cyberedge-result is-warning';
        result.textContent = 'CyberEdge header not detected. Check that this domain routes through CyberEdge.';
        return;
      }
      var normalized = value.toLowerCase().trim();
      var reason = response.headers.get(window.CyberEdgeCacheAdmin.cacheReasonHeader || 'X-CyberEdge-Cache-Reason');
      var reasons = {
        'request-cookie': 'A login, session, unknown, or malformed cookie keeps this request private.',
        'request-authorization': 'Authenticated requests are not shared.',
        'request-query': 'URLs with query parameters are not shared.',
        'request-cache-control': 'The browser requested a fresh response. Turn off DevTools “Disable cache” and navigate normally to test a HIT.',
        'request-pragma': 'The browser requested a fresh response. Turn off DevTools “Disable cache” and navigate normally to test a HIT.',
        'private-path': 'This page is excluded to protect private content.',
        'request-method': 'This request method is not cacheable.',
        'request-range': 'Partial-content requests are not shared.',
        'origin-response': 'The origin response was not eligible for shared caching.',
        'origin-vary': 'The origin varies this page by visitor state, so it is not shared.',
        'storage-unavailable': 'Edge cache storage is temporarily unavailable. The origin served this request.',
        'configured-bypass': 'This request is excluded by the domain’s cache configuration.'
      };
      result.className = 'cyberedge-result ' + (normalized === 'hit' ? 'is-good' : 'is-warning');
      if (normalized === 'bypass') {
        result.textContent = 'BYPASS — ' + (reasons[reason] || 'This request was intentionally not served from the shared cache.');
      } else if (normalized === 'hit') {
        result.textContent = 'HIT — served from CyberEdge cache.';
      } else if (normalized === 'miss') {
        result.textContent = 'MISS — no cached copy was available. Check again after this response warms the cache.';
      } else {
        result.textContent = value;
      }
    }).catch(function () {
      result.className = 'cyberedge-result is-warning';
      result.textContent = 'Could not reach the public site';
    }).then(function () {
      button.disabled = false;
    });
  });
}());
