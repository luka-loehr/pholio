// Theme switching like next-themes (key `theme`, attribute `class`).
//
// Usage:
//   import { initTheme } from './theme.js';
//   const theme = initTheme();            // reads storage, attaches listeners
//   theme.set('dark');                    // with view transition and transition lock
//
// Original: node_modules/next-themes/dist/index.mjs with the options from
// fumadocs-ui/dist/provider/base.js:
//   attribute "class", defaultTheme "system", enableSystem, enableColorScheme,
//   disableTransitionOnChange.
//
// Which gives, exactly:
//   · stored is `light` | `dark` | `system` under `theme`
//   · on <html> the class `light` or `dark` is set (the other one is
//     removed), plus `style.color-scheme`
//   · with `system` a matchMedia listener on `(prefers-color-scheme: dark)`
//     keeps the result up to date
//   · before each change a <style> with `transition: none !important` goes into
//     <head>, `getComputedStyle(document.body)` forces the reflow, and a
//     `setTimeout(…, 1)` removes it again
//   · the change itself runs inside `document.startViewTransition` when available
//     (fumadocs-ui: ThemeSwitch.handleThemeChange and ThemeHotKey)

const STORAGE_KEY = 'theme';
const THEMES = ['light', 'dark'];
const MEDIA = '(prefers-color-scheme: dark)';

// next-themes: W() – disable transitions for the duration of the change.
function disableTransitions() {
  const style = document.createElement('style');
  style.appendChild(document.createTextNode(
    '*,*::before,*::after{-webkit-transition:none!important;-moz-transition:none!important;'
    + '-o-transition:none!important;-ms-transition:none!important;transition:none!important}',
  ));
  document.head.appendChild(style);
  return () => {
    window.getComputedStyle(document.body);
    setTimeout(() => { document.head.removeChild(style); }, 1);
  };
}

function systemTheme() {
  return window.matchMedia(MEDIA).matches ? 'dark' : 'light';
}

function readStored() {
  try {
    return localStorage.getItem(STORAGE_KEY) || 'system';
  } catch {
    return 'system';
  }
}

export function initTheme() {
  let theme = readStored();

  function resolved() {
    return theme === 'system' ? systemTheme() : theme;
  }

  // next-themes: applyTheme – swap classes, set color-scheme.
  function apply(value) {
    const root = document.documentElement;
    root.classList.remove(...THEMES);
    root.classList.add(value);
    if (THEMES.includes(value)) root.style.colorScheme = value;
  }

  function set(next) {
    theme = next;
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      /* storage may be blocked */
    }
    const enable = disableTransitions();
    apply(resolved());
    enable();
    update();
  }

  // The button in `light-dark` mode shows the active one of the two states.
  function update() {
    const value = resolved();
    for (const button of document.querySelectorAll('[data-theme-toggle]')) {
      const icons = button.querySelectorAll('svg');
      // Order as in theme-switch.js: sun, moon.
      icons.forEach((svgIcon, i) => {
        const key = i === 0 ? 'light' : 'dark';
        const active = key === value;
        // The base class nd-theme-icon stays; the reference has no attribute for
        // this, so exactly one state class (verify/CLASS-MAP.md, theme.js).
        svgIcon.classList.toggle('nd-theme-icon-active', active);
      });
    }
  }

  function change(next) {
    if (document.startViewTransition) document.startViewTransition(() => set(next));
    else set(next);
  }

  window.matchMedia(MEDIA).addEventListener('change', () => {
    if (theme === 'system') {
      apply(systemTheme());
      update();
    }
  });

  // The button with `mode="light-dark"` toggles between the resolved values.
  for (const button of document.querySelectorAll('button[data-theme-toggle]')) {
    button.addEventListener('click', () => change(resolved() === 'light' ? 'dark' : 'light'));
  }
  // The group of three (`mode="light-dark-system"`) has one button per value.
  for (const group of document.querySelectorAll('div[data-theme-toggle]')) {
    [...group.querySelectorAll('button')].forEach((button, i) => {
      const value = ['light', 'dark', 'system'][i];
      button.addEventListener('click', () => change(value));
    });
  }

  apply(resolved());
  update();

  return {
    get theme() { return theme; },
    get resolvedTheme() { return resolved(); },
    set: change,
    toggle() { change(resolved() === 'dark' ? 'light' : 'dark'); },
  };
}
