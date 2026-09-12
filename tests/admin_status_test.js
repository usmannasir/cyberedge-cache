'use strict';

// Exercise the actual dashboard handler without network or a WordPress account.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const script = fs.readFileSync(path.join(__dirname, '../cyberedge-cache/assets/admin.js'), 'utf8');
let checks = 0;

async function scenario(status, reason, expected, good) {
  let click;
  let request;
  const button = { disabled: false, addEventListener: (event, handler) => { assert.equal(event, 'click'); click = handler; } };
  const result = { className: '', textContent: '' };
  vm.runInNewContext(script, {
    document: { getElementById: id => id === 'cyberedge-check-cache' ? button : result },
    window: { CyberEdgeCacheAdmin: { homeUrl: 'https://example.test/', cacheHeader: 'X-CyberEdge-Cache', cacheReasonHeader: 'X-CyberEdge-Cache-Reason' } },
    fetch: async (url, options) => {
      request = { url, options };
      if (status === 'network-error') throw new Error('offline');
      return { headers: { get: name => name === 'X-CyberEdge-Cache' ? status : reason } };
    }
  });
  click();
  assert.equal(button.disabled, true);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(request.url, 'https://example.test/');
  assert.equal(request.options.method, 'GET');
  assert.equal(request.options.credentials, 'omit');
  assert.equal(request.options.cache, undefined, 'Probe must not add a request no-cache veto');
  assert.equal(button.disabled, false);
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
  console.log(JSON.stringify({ passed: checks, network_requests: 0 }));
})().catch(error => { console.error(error); process.exitCode = 1; });
