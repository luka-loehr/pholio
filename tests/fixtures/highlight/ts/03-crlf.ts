/**
 * Formatting of plan cells (file with CRLF line endings).
 * @file 03-crlf.ts
 */

type Cell = { topic: string; room?: string; cancelled: boolean; notes: string[] };

const TEAM_PATTERN = /^(?<level>\d{1,2})(?<track>[a-zàéî])$/iu;
const INITIALS = /\b[A-ZÀÉÎ]{2,4}\b/g;
const PATH = /\/api\/v\d+\/[\w-]+(?:\/\d+)?\/?$/;
const SPACE = /[\s\u00A0]+/gm;
const BRACKET = /[\]\[()\\/]/y;
const SETS = /[\p{L}--[a-z]]/v;

export function cellAsHtml(c: Cell, slot: number): string {
  const cls = `cell ${c.cancelled ? "cell--cancelled" : ""}`.trim();
  return `<td class="${cls}" data-slot="${slot}">
  <strong>${c.topic}</strong>${c.room ? ` in <em>${c.room}</em>` : ""}
  ${c.notes.length > 0
    ? `<ul>${c.notes.map((n, i) => `<li data-i="${i}">${n.replace(SPACE, " ")}</li>`).join("")}</ul>`
    : `<!-- no notes -->`}
</td>`;
}

export const tag = (parts: TemplateStringsArray, ...values: unknown[]): string =>
  parts.reduce((text, part, i) => `${text}${part}${i < values.length ? String(values[i]) : ""}`, "");

const raw = String.raw`C:\Projects\Plàns\${"interpolated"}\n`;
const tagged = tag`Team ${"blue"} has ${3 + 2} slots`;
const escaped = `Backtick \` and dollar \${none} and ${"real"}`;
const empty = ``;

export function checkTeam(input: string): { level: number; track: string } | null {
  const match = TEAM_PATTERN.exec(input.trim());
  if (!match?.groups) return null;
  const level = parseInt(match.groups.level, 10);
  return level >= 5 && level <= 13 ? { level, track: match.groups.track.toLowerCase() } : null;
}

const ratio = 10 / 2 / 5; // division, not a regex
const withRegex = "5a,6b".split(/,\s*/).filter((k) => INITIALS.test(k) || /\d/.test(k));

export async function* paged<T>(fetchPage: (page: number) => Promise<T[]>): AsyncGenerator<T, void, undefined> {
  for (let page = 1; ; page++) {
    const list = await fetchPage(page);
    if (list.length === 0) return;
    yield* list;
  }
}

export { INITIALS, PATH, BRACKET, SETS, raw, tagged, escaped, empty, ratio, withRegex };
