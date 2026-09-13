// banner.js — notice banner: fumadocs-ui components/banner.js.
//
// The original writes an inline <script> that sets `html.nd-banner-<id>` before
// first paint when the banner was already closed; the <style> rule
// `.nd-banner-<id> #<id> { display: none }` then hides it.
// The CSP forbids inline scripts, so this module takes over:
//   · on start: localStorage[<key>] === "true" → set the class on <html> and
//     remove the banner (useEffect → setOpen(false) → return null),
//   · click on the close button: remove the banner, localStorage[<key>] = "true".
// Deviation: a closed banner is briefly visible until this module has loaded.
//
// The key is already present in the banner's <style> rule; it is read from there
// instead of reimplementing `encodeBase32`.

const KEY_RULE = /^\s*\.(nd-banner-[a-z2-7]+)\s+#/;
let booted = false;

export function boot(doc = document) {
  if (booted) return;
  booted = true;
  for (const style of doc.querySelectorAll('style')) {
    const match = KEY_RULE.exec(style.textContent ?? '');
    const root = style.parentElement;
    if (!match || !root || root === doc.head || root.__ndBanner) continue;
    root.__ndBanner = true;
    attach(doc, root, match[1]);
  }
}

function attach(doc, root, key) {
  if (localStorage.getItem(key) === 'true') {
    doc.documentElement.classList.add(key);
    root.remove();
    return;
  }
  const close = root.querySelector(':scope > button[type="button"]');
  close?.addEventListener('click', () => {
    root.remove();
    localStorage.setItem(key, 'true');
  });
}
