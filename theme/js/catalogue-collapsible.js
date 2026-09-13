// catalogue-collapsible.js — the catalogue components built on Base UI Collapsible:
// file tree (reference UI components/files.js, Folder), type table
// (components/type-table.js, Item) and inline table of contents (components/inline-toc.js).
//
// Opening, closing, the height animation and mounting from <template data-collapsible-panel>
// are done by js/collapsible.js. Only what each component adds itself lives here:
//   · Folder: the icon switches between `folder` and `folder-open` (open ? FolderOpen : FolderIcon).
//   · TypeTable row with id: on open `history.replaceState(null, "", "#<id>")`,
//     on start a matching hash opens the row.
//   · InlineTOC: nothing else.

import { Collapsible } from './collapsible.js';
import { icon } from './util.js';

const FOLDER = '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"></path>';
const FOLDER_OPEN = '<path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2"></path>';

let booted = false;

export function boot(doc = document) {
  if (booted) return;
  booted = true;

  for (const trigger of doc.querySelectorAll('.nd-files button.nd-files-folder')) {
    const root = trigger.parentElement;
    Collapsible.attach(root, {
      trigger,
      disabled: trigger.getAttribute('aria-disabled') === 'true',
      onOpenChange(open) {
        const svg = trigger.querySelector(':scope > svg');
        if (svg) svg.outerHTML = open ? icon('folder-open', '', FOLDER_OPEN) : icon('folder', '', FOLDER);
      },
    });
  }

  for (const root of doc.querySelectorAll('.nd-typetable-item')) {
    const instance = Collapsible.attach(root, {
      onOpenChange(open) {
        if (open && root.id) window.history.replaceState(null, '', `#${root.id}`);
      },
    });
    if (instance && root.id && window.location.hash === `#${root.id}`) instance.setOpen(true, 'none');
  }

  for (const root of doc.querySelectorAll('.nd-inlinetoc')) Collapsible.attach(root);
}
