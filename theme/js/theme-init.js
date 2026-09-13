// Theme bootstrap before first paint, as a classic synchronous script.
//
// Include it in <head> without async/defer/type="module" so it runs before
// the first paint:
//   <script src="…/assets/js/theme-init.js"></script>
//
// Why a separate file: the site runs under a Content-Security-Policy without
// 'unsafe-inline', so an inline bootstrap script cannot be used. Runtime behaviour (switching,
// storage, matchMedia listener, view transition) lives in theme.js.
//
// The expression below is the bootstrap script of next-themes, unchanged.
// Arguments:
//   attribute "class", storageKey "theme", defaultTheme "system", forcedTheme null,
//   themes ["light","dark"], value null, enableSystem true, enableColorScheme true
// Effect: class `light` or `dark` on <html>, plus style.colorScheme; with
// `system` (or empty storage) prefers-color-scheme decides; if storage is
// blocked, the static initial state stays.
((e, i, s, u, m, a, l, h)=>{
    let d = document.documentElement, w = [
        "light",
        "dark"
    ];
    function p(n) {
        (Array.isArray(e) ? e : [
            e
        ]).forEach((y)=>{
            let k = y === "class", S = k && a ? m.map((f)=>a[f] || f) : m;
            k ? (d.classList.remove(...S), d.classList.add(a && a[n] ? a[n] : n)) : d.setAttribute(y, n);
        }), R(n);
    }
    function R(n) {
        h && w.includes(n) && (d.style.colorScheme = n);
    }
    function c() {
        return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
    }
    if (u) p(u);
    else try {
        let n = localStorage.getItem(i) || s, y = l && n === "system" ? c() : n;
        p(y);
    } catch (n) {}
})("class","theme","system",null,["light","dark"],null,true,true);
