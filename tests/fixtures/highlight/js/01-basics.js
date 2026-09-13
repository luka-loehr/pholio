
'use strict';

/**
 * @typedef {Object} Appointment
 * @property {string} title - Label, e.g. "Kick-off"
 * @property {Date} start
 * @property {number} [duration=45] Duration in minutes
 */

// Constants
const RELEASE_START = new Date(2026, 8, 14);
const hex = 0x1F, HEX = 0XAB_CD, octal = 0o17, binary = 0B1101;
const decimal = 12.5e-2, huge = 1.8E308, sep = 1_000_000.000_1, dotStart = .75;
const big = 123n * -2n;
const negative = -1, infinite = -Infinity, notANumber = NaN;

let esc = 'Tab:\tNew:\nCR:\rBS:\\ Quote:\' Unicode: é \u{1F389} Hex: \x41';
const empty1 = "", empty2 = '', empty3 = ``;
const quote = "«Who learns, wins», said the director 'back then' and \"today\".";

/**
 * Computes the end of an appointment.
 * @param {Appointment} appointment
 * @returns {Date}
 */
function end(appointment) {
	const duration = appointment.duration ?? 45;
	return new Date(appointment.start.getTime() + duration * 60_000);
}

const isWeekend = (date) => [0, 6].includes(date.getDay());
const double = x => x * 2;

class Calendar {
	static #count = 0;
	appointments = [];

	constructor(name = 'Team calendar') {
		this.name = name;
		Calendar.#count += 1;
	}

	get next() {
		const now = Date.now();
		return this.appointments.find((a) => a.start.valueOf() > now) || null;
	}

	*[Symbol.iterator]() {
		yield* this.appointments;
	}
}

if (typeof module !== 'undefined' && module.exports) {
	module.exports = { end, isWeekend, double, Calendar, RELEASE_START, hex, HEX, octal, binary, decimal, huge, sep, dotStart, big, negative, infinite, notANumber, esc, empty1, empty2, empty3, quote };
}
