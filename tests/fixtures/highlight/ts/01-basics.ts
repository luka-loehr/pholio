
/**
 * Base types for the release board of the example workspace.
 * @module releases/types
 * @author Docs Team <team@example.com>
 * @since 2.4.0
 * @see {@link https://example.com/guide/releases}
 */

// Weekdays as a numeric enum
export enum Weekday {
	Monday = 1,
	Tuesday,
	Wednesday,
	Thursday,
	Friday, // there is no Saturday
}

export const enum RoomKind {
	Office = "OFFICE",
	Lab = 'LAB',
	Hall = "HALL",
}

export interface Slot {
	readonly number: number; // 1 to 10
	topic: string;
	owner?: string | null;
	room: `${RoomKind}-${number}`;
	[extra: string]: unknown;
}

type Partially<T> = { [K in keyof T]?: T[K] };
type TextOnly<T> = T extends string ? T : never;
type Pair<A, B = A> = readonly [A, B];

/* Limits – deliberately constants,
   so the guide can link to them. */
const MAX_SLOTS = 10;
const color = 0xFF_A0_3C;
const mode = 0o755;
const mask = 0b1010_0101;
const pi = 3.141_592, half = .5, small = 1e-3, avogadro = 6.022E+23;
const membersTotal = 1_250;
const huge = 9_007_199_254_740_993n, hexBig = 0xFFn;
const minus = -42, minusDecimal = -0.25;

const empty = "";
const alsoEmpty = '';
const escapes = "Line 1\nLine 2\twith tab, \"quote\" and \\ backslash";
const unicode = 'Caf\u00e9: \x41 \u{1F389} \0 end \'';
const nested = "She said: 'Deadline missed!'";
const reversed = 'He replied: "Again?"';

export function isFree(slot: Slot): boolean {
	return slot.topic === "" || slot.owner == null;
}

export const countSlots = (plan: Slot[], day?: Weekday): number =>
	plan.filter((s) => s.number <= MAX_SLOTS && (day === undefined || s["day"] === day)).length;

let pair: Pair<string> = ["red", "blue"] as const;
let part: Partially<Slot> = {};
export type { TextOnly };
export default { Weekday, isFree, countSlots, color, mode, mask, pi, half, small, avogadro, membersTotal, huge, hexBig, minus, minusDecimal, empty, alsoEmpty, escapes, unicode, nested, reversed, pair, part };
