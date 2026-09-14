'use strict';

// Exercise the actual dashboard handler without network or a WordPress account.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../cyberedge-cache/assets/admin.js'), 'utf8');
let checks = 0;

async function scenario(status, reason, expected, good, httpStatus = 200) {
  let click;
  let request;
  let timer;
  let cleared = false;
  let requests = 0;
  const button = { disabled: false, addEventListener: (event, handler) => { assert.equal(event, 'click'); click = handler; } };
  const result = { className: '', textContent: '' };
  vm.runInNewContext(script, {
    document: { getElementById: id => id === 'cyberedge-check-cache' ? button : result },
    window: { CyberEdgeCacheAdmin: { homeUrl: 'https://example.test/', cacheHeader: 'X-CyberEdge-Cache', cacheReasonHeader: 'X-CyberEdge-Cache-Reason' } },
    AbortController,
    setTimeout: (callback, milliseconds) => { assert.equal(milliseconds, 15000); timer = callback; return 42; },
    clearTimeout: id => { assert.equal(id, 42); cleared = true; },
    fetch: async (url, options) => {
      requests++;
      request = { url, options };
      if (status === 'network-error') throw new Error('offline');
      if (status === 'timeout') return new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' })));
      });
      return { ok: httpStatus >= 200 && httpStatus < 300, status: httpStatus,
        headers: { get: name => name === 'X-CyberEdge-Cache' ? status : reason } };
    }
  });
  click();
  assert.equal(button.disabled, true);
  assert.match(result.textContent, /up to 15 seconds/);
  click();
  assert.equal(requests, 1, 'Repeated clicks must not start overlapping probes');
  if (status === 'timeout') timer();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(request.url, 'https://example.test/');
  assert.equal(request.options.method, 'GET');
  assert.equal(request.options.credentials, 'omit');
  assert.equal(request.options.cache, undefined, 'Probe must not add a request no-cache veto');
  assert.equal(button.disabled, false);
  assert.equal(cleared, true, 'Completed probes must release their deadline timer');
  assert.equal(request.options.signal.aborted, status === 'timeout');
  assert.match(result.textContent, expected);
  assert.equal(result.className.includes('is-good'), good);
  checks++;
}

(async () => {
  await scenario('hit', null, /HIT — served from CyberEdge cache/, true);
  await scenario('miss', null, /MISS — no cached copy/, false);
  await scenario('bypass', 'request-cache-control', /BYPASS.*Disable cache/, false);
  await scenario('bypass', 'request-cookie', /BYPASS.*cookie keeps this request private/, false);
  await scenario('bypass', 'origin-response', /origin response was not eligible/, false);
  await scenario('bypass', '<img src=x>', /intentionally not served/, false);
  await scenario(null, null, /header not detected.*routes through CyberEdge/, false);
  await scenario('network-error', null, /Could not reach/, false);
  await scenario('not-a-hit', null, /^not-a-hit$/, false);
  await scenario('timeout', null, /timed out after 15 seconds.*try again/, false);
  await scenario('hit', null, /HTTP 503.*could not be verified/, false, 503);
  await scenario('hit', null, /HTTP 403.*could not be verified/, false, 403);
  await scenario(null, null, /HTTP 404.*could not be verified/, false, 404);
  let click;
  const button = { disabled: false, addEventListener: (event, handler) => { click = handler; } };
  const result = { className: '', textContent: '' };
  vm.runInNewContext(script, {
    document: { getElementById: id => id === 'cyberedge-check-cache' ? button : result },
    window: { CyberEdgeCacheAdmin: { homeUrl: 'https://example.test/' } }
  });
  click();
  assert.equal(button.disabled, false);
  assert.match(result.textContent, /browser cannot run a timed cache check/);
  checks++;
  console.log(JSON.stringify({ passed: checks, network_requests: 0 }));
})().catch(error => { console.error(error); process.exitCode = 1; });
