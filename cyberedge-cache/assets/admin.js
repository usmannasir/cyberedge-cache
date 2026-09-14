(function () {
  'use strict';
  var button = document.getElementById('cyberedge-check-cache');
  var result = document.getElementById('cyberedge-cache-result');
  if (!button || !result || !window.CyberEdgeCacheAdmin) return;

  button.addEventListener('click', function () {
    if (button.disabled) return;
    button.disabled = true;
    if (typeof AbortController !== 'function' || typeof fetch !== 'function') {
      result.className = 'cyberedge-result is-warning';
      result.textContent = 'This browser cannot run a timed cache check. Update your browser and try again.';
      button.disabled = false;
      return;
    }
    result.className = 'cyberedge-result is-checking';
    result.textContent = 'Checking the public site… This can take up to 15 seconds.';
    var controller = new AbortController();
    var timedOut = false;
    var timeout = setTimeout(function () {
      timedOut = true;
      controller.abort();
    }, 15000);
    fetch(window.CyberEdgeCacheAdmin.homeUrl, {
      // CyberEdge deliberately does not cache HEAD responses, so use an
      // anonymous GET to exercise the same path a visitor actually receives.
      method: 'GET',
      credentials: 'omit',
      redirect: 'follow',
      signal: controller.signal
    }).then(function (response) {
      if (!response.ok) {
        result.className = 'cyberedge-result is-warning';
        result.textContent = 'The public site returned HTTP ' + response.status + '. Check the site and try again; cache status could not be verified.';
        return;
      }
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
      result.textContent = timedOut ? 'The public-site check timed out after 15 seconds. Check your connection and try again.' : 'Could not reach the public site. Check your connection and try again.';
    }).then(function () {
      clearTimeout(timeout);
      button.disabled = false;
    });
  });
}());
