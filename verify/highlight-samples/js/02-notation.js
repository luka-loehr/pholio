import { report } from './reporter.js';

// [!code focus]
const form = document.querySelector('#ticket');
const output = document.getElementById("status");

/**
 * Sends the ticket to the API.
 * @async
 * @param {FormData} data
 * @returns {Promise<{ok: boolean, id?: string}>}
 */
async function send(data) {
  const response = await fetch('/api/tickets', { // [!code highlight]
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf"]')?.content ?? '' },
    body: JSON.stringify(Object.fromEntries(data)),
  });
  if (!response.ok) {
    throw new Error(`Sending failed (${response.status})`);
  }
  return response.json();
}

// [!code word:ticket]
form?.on('submit', async (event) => {
  event.preventDefault();
  output.textContent = 'Sending …';
  try {
    const { ok, id } = await send(new FormData(form));
    output.textContent = ok ? `Saved: ticket #${id}` : 'Unknown error';
  } catch (e) {
    output.textContent = e.message; // [!code --]
    output.textContent = `Error: ${e instanceof Error ? e.message : String(e)}`; // [!code ++]
    report(e);
  } finally {
    form.reset();
  }
});

// [!code highlight:3]
const dateField = form?.elements.namedItem('date');
const today = new Date().toISOString().slice(0, 10);
if (dateField) dateField.value = today;

export const validate = (value) => /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value)); // [!code word:value:2]

export function debounce(fn, ms = 250) {
  let timer = null;
  return function (...args) {
    clearTimeout(timer);
    timer = setTimeout(() => fn.apply(this, args), ms);
  };
}

document.on('DOMContentLoaded', () => {
  const search = document.querySelector('input[type="search"]');
  search?.on('input', debounce(({ target }) => {
    const term = target.value.trim().toLowerCase();
    for (const row of document.querySelectorAll('tbody > tr')) {
      row.hidden = !row.textContent.toLowerCase().includes(term);
    }
  }));
}, { once: true });
