// tabs.js — tabs with the behaviour of Base UI Tabs (@base-ui/react 1.8.0: tabs/root/TabsRoot.js, tabs/tab/TabsTab.js,
// tabs/panel/TabsPanel.js, internals/composite/root/useCompositeRoot.js).
//
// Static DOM (components/tabs.php): root, tab list, exactly one panel and a
// <template data-nd-tabs> with all panels and the configuration
// (data-values, data-default, data-group-id, data-persist, data-update-anchor, data-guard).
//
// Behaviour:
//   · Click on an inactive tab → onValueChange. With groupId all blocks of the
//     same group follow, the value goes into sessionStorage (with persist also into
//     localStorage); without groupId only this block switches.
//   · On start a block with groupId reads sessionStorage (with persist falling back to
//     localStorage) and adopts the value; this counts as a value change with direction.
//   · data-activation-direction: position of the new tab relative to the old one
//     (computeActivationDirection), set on root, list, all tabs and the panel.
//   · Only the active panel is mounted: aria-controls is only on the active tab,
//     data-index on the panel is 0.
//   · Roving tabindex: arrow left/right (wrapping), Home, End only move
//     focus (activateOnFocus is off); Enter/Space trigger the native click.
//   · With data-guard, values that are not in items are ignored.
//   · updateAnchor only writes when a panel has an id; Pholio's tabs have none,
//     so the address stays unchanged.
//   · Hash: if the target is inside the mounted panel, it is scrolled to after one frame.

const groups = new Map();
let booted = false;

export function boot(doc = document) {
  if (booted) return;
  booted = true;
  for (const template of doc.querySelectorAll('template[data-nd-tabs]')) attach(template);
}

function attach(template) {
  const root = template.parentElement;
  if (!root || root.__ndTabs) return;
  root.__ndTabs = true;

  const values = JSON.parse(template.dataset.values ?? '[]');
  const list = root.querySelector(':scope > [role="tablist"]');
  const tabs = list ? [...list.querySelectorAll(':scope > [role="tab"]')] : [];
  const stored = new Map();
  for (const panel of template.content.children) stored.set(panel.dataset.value, panel);
  const groupId = template.dataset.groupId || null;
  const persist = template.hasAttribute('data-persist');
  const updateAnchor = template.hasAttribute('data-update-anchor');
  const guard = template.hasAttribute('data-guard');
  // Pholio has no id on <Tab>; the map stays empty.
  const valueToId = new Map();

  let value = template.dataset.default;
  let highlighted = Math.max(0, tabs.findIndex((tab) => tab.hasAttribute('data-active')));

  const mountedPanel = () => root.querySelector(':scope > [role="tabpanel"]');
  const tabFor = (v) => {
    const index = values.indexOf(v);
    return index < 0 ? null : tabs[index];
  };

  function direction(oldValue, newValue) {
    if (oldValue == null || newValue == null) return 'none';
    const oldTab = tabFor(oldValue);
    const newTab = tabFor(newValue);
    if (!oldTab || !newTab) {
      if (oldTab !== newTab) return newValue > oldValue ? 'right' : 'left';
      return 'none';
    }
    const before = oldTab.getBoundingClientRect().left;
    const after = newTab.getBoundingClientRect().left;
    if (after < before) return 'left';
    if (after > before) return 'right';
    return 'none';
  }

  function setHighlight(index) {
    highlighted = index;
    tabs.forEach((tab, i) => tab.setAttribute('tabindex', i === index ? '0' : '-1'));
  }

  // setValue of the outer Tabs (checked against items).
  function setValue(next) {
    if (guard && !values.includes(next)) return;
    if (next === value) return;
    const dir = direction(value, next);
    value = next;
    render(dir);
  }

  function render(dir) {
    for (const el of [root, list, ...tabs]) el?.setAttribute('data-activation-direction', dir);

    mountedPanel()?.remove();
    let panel = null;
    const source = stored.get(value);
    if (source) {
      panel = source.cloneNode(true);
      panel.removeAttribute('data-value');
      panel.setAttribute('data-orientation', 'horizontal');
      panel.setAttribute('data-activation-direction', dir);
      panel.setAttribute('role', 'tabpanel');
      panel.setAttribute('tabindex', '0');
      panel.setAttribute('data-index', '0');
      const tab = tabFor(value);
      if (tab) panel.setAttribute('aria-labelledby', tab.id);
      root.insertBefore(panel, template);
    }

    tabs.forEach((tab, i) => {
      const active = values[i] === value;
      tab.toggleAttribute('data-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.toggleAttribute('data-composite-item-active', active);
      if (active && panel) tab.setAttribute('aria-controls', panel.id);
      else tab.removeAttribute('aria-controls');
    });

    // The highlight follows the active tab as long as focus is not inside the list.
    const index = values.indexOf(value);
    if (index >= 0 && !(list && list.contains(document.activeElement))) setHighlight(index);
  }

  function onValueChange(next) {
    if (updateAnchor) {
      const id = valueToId.get(next);
      if (id) window.history.replaceState(null, '', `#${id}`);
    }
    if (groupId) {
      for (const listener of groups.get(groupId) ?? []) listener(next);
      sessionStorage.setItem(groupId, next);
      if (persist) localStorage.setItem(groupId, next);
    } else {
      setValue(next);
    }
  }

  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => {
      if (values[index] === value || tab.disabled) return;
      onValueChange(values[index]);
    });
    tab.addEventListener('focus', () => setHighlight(index));
  });

  list?.addEventListener('keydown', (event) => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    const rtl = getComputedStyle(list).direction === 'rtl';
    const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
    const last = tabs.length - 1;
    let next = highlighted;
    if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = last;
    else if (event.key === forward) next = highlighted >= last ? 0 : highlighted + 1;
    else next = highlighted <= 0 ? last : highlighted - 1;
    if (next === highlighted) return;
    event.preventDefault();
    setHighlight(next);
    queueMicrotask(() => tabs[next]?.focus());
  });

  if (groupId) {
    let previous = sessionStorage.getItem(groupId);
    if (persist) previous ??= localStorage.getItem(groupId);
    if (previous) setValue(previous);
    const listeners = groups.get(groupId) ?? new Set();
    listeners.add(setValue);
    groups.set(groupId, listeners);
  }

  const openFromHash = () => {
    const hash = window.location.hash.slice(1);
    if (!hash) return;
    for (const [v, id] of valueToId.entries()) {
      if (id === hash) {
        setValue(v);
        root.scrollIntoView();
        return;
      }
    }
    const target = document.getElementById(hash);
    const panel = mountedPanel();
    if (!target || !panel || !panel.contains(target)) return;
    setValue(value);
    requestAnimationFrame(() => target.scrollIntoView());
  };
  openFromHash();
  window.addEventListener('hashchange', openFromHash);
}
