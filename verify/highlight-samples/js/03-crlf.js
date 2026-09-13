// Helpers for member lists (CRLF line endings)
export const PATTERNS = {
  email: /^[\w.+-]+@(?:[\w-]+\.)+[a-z]{2,}$/i,
  phone: /^\+?\d[\d\s/-]{5,}\d$/,
  initials: /[A-ZÀ-Þ][a-zà-ÿ]{1,3}/gu,
  html: /<\/?([a-z][a-z0-9]*)\b[^>]*>/gi,
  brackets: /[[\]{}()]/g,
  lookbehind: /(?<=€\s?)\d+(?:\.\d{2})?/,
  unicodeSet: /[\p{Script=Latin}&&\p{Lu}]/v,
};

export function csvRow(values) {
  return values.map((v) => (/[;"\n]/.test(v) ? `"${String(v).replace(/"/g, '""')}"` : v)).join(';');
}

const ratio = 7 / 2 / 1; // not a regex
let g = 3, i = 2;
const alsoNoRegex = ratio /g/ i;

export function list(team, members) {
  return `
<section class="team" id="team-${team.toLowerCase()}">
  <h2>Team ${team} (${members.length} ${members.length === 1 ? 'member' : 'members'})</h2>
  <ol>
    ${members
      .sort((a, b) => a.lastName.localeCompare(b.lastName, 'en'))
      .map(({ firstName, lastName, birthday }) => `<li>${lastName}, ${firstName}${
        birthday ? ` <small>(${new Intl.DateTimeFormat('en-GB').format(birthday)})</small>` : ''
      }</li>`)
      .join('\n    ')}
  </ol>
</section>`;
}

const raw = String.raw`\d+\.\d+ stays ${'litéral'}`;
const escBacktick = `\`code\` costs \$5 or ${5}€`;

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export async function retry(task, attempts = 3) {
  for (let n = 1; n <= attempts; n++) {
    try {
      return await task(n);
    } catch (error) {
      if (n === attempts) throw error;
      await sleep(2 ** n * 100);
    }
  }
}

check: {
  if (PATTERNS.email.test('office@example.com')) break check;
  console.error("Invalid address");
}

export { raw, escBacktick, g, i, alsoNoRegex };
