// Search worker: loads the index once and answers queries off the main thread.
// Started by search-dialog.js as a module worker.
//
// Messages in:
//   { type: 'load', url }       fetch the index and build the engine (the first call wins)
//   { type: 'query', query }    queries that arrive while one waits replace it,
//                               so only the newest input is answered
// Messages out:
//   { type: 'ready' }
//   { type: 'error', message }  the index could not be loaded; queries answer []
//   { type: 'result', query, items, ms }

import { createSearch } from './search.js';

let engine = null;
let loading = null;
let waiting = null;
let scheduled = false;

function load(url) {
  loading ??= fetch(url)
    .then((res) => (res.ok ? res.json() : Promise.reject(new Error(`HTTP ${res.status} for ${url}`))))
    .then((json) => {
      engine = createSearch(json);
      self.postMessage({ type: 'ready' });
    })
    .catch((error) => {
      self.postMessage({ type: 'error', message: String(error?.message ?? error) });
    });
  return loading;
}

async function answer() {
  if (loading) await loading;
  scheduled = false;
  const message = waiting;
  waiting = null;
  if (!message) return;
  const started = performance.now();
  const items = engine ? engine.search(message.query) : [];
  self.postMessage({ type: 'result', query: message.query, items, ms: performance.now() - started });
}

self.addEventListener('message', (event) => {
  const message = event.data;
  if (message?.type === 'load') {
    load(message.url);
  } else if (message?.type === 'query') {
    waiting = message;
    if (!scheduled) {
      scheduled = true;
      // A macrotask lets queries that are already queued replace this one first.
      setTimeout(answer, 0);
    }
  }
});
