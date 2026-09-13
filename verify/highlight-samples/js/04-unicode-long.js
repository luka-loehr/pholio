/* eslint-disable no-octal, no-octal-escape */
// Unicode examples: é è ê à ç ñ ø – 🎉🎈 – 𝟙𝟚𝟛 – 漢字

var señor = { façade: 'Rue de la Paix 7', niño: 1.72 };
var $price = '12.50 €'; // with a non-breaking space
var _party = "🎉 as a surrogate pair, direct: 🎉";
var legacyOctal = 0755; // sloppy mode only
var octalEscape = "\101\102\103 and \0 and \7";

const schedule = { "Algebra-0": { hours: 1, weight: 0.0 }, "Geometry-1": { hours: 2, weight: 0.5 }, "Astronomy-2": { hours: 3, weight: 1.0 }, "Façade Design-3": { hours: 4, weight: 1.5 }, "Botany-4": { hours: 5, weight: 2.0 }, "Physics-5": { hours: 1, weight: 2.5 }, "Chemistry-6": { hours: 2, weight: 3.0 }, "Biology-7": { hours: 3, weight: 0.0 }, "History-8": { hours: 4, weight: 0.5 }, "Civics-9": { hours: 5, weight: 1.0 }, "Geography-10": { hours: 1, weight: 1.5 }, "Fine Arts-11": { hours: 2, weight: 2.0 }, "Music-12": { hours: 3, weight: 2.5 }, "Sports-13": { hours: 4, weight: 3.0 }, "Computing-14": { hours: 5, weight: 0.0 }, "Philosophy-15": { hours: 1, weight: 0.5 }, "Ethics-16": { hours: 2, weight: 1.0 }, "Economics-17": { hours: 3, weight: 1.5 }, "Spanish-18": { hours: 4, weight: 2.0 }, "Linguistics-19": { hours: 5, weight: 2.5 }, "Robotics-20": { hours: 1, weight: 3.0 }, "Film & Theatre-21": { hours: 2, weight: 0.0 }, "Algebra-22": { hours: 3, weight: 0.5 }, "Geometry-23": { hours: 4, weight: 1.0 }, "Astronomy-24": { hours: 5, weight: 1.5 }, "Façade Design-25": { hours: 1, weight: 2.0 }, "Botany-26": { hours: 2, weight: 2.5 }, "Physics-27": { hours: 3, weight: 3.0 }, "Chemistry-28": { hours: 4, weight: 0.0 }, "Biology-29": { hours: 5, weight: 0.5 }, "History-30": { hours: 1, weight: 1.0 }, "Civics-31": { hours: 2, weight: 1.5 }, "Geography-32": { hours: 3, weight: 2.0 }, "Fine Arts-33": { hours: 4, weight: 2.5 }, "Music-34": { hours: 5, weight: 3.0 }, "Sports-35": { hours: 1, weight: 0.0 }, "Computing-36": { hours: 2, weight: 0.5 }, "Philosophy-37": { hours: 3, weight: 1.0 }, "Ethics-38": { hours: 4, weight: 1.5 }, "Economics-39": { hours: 5, weight: 2.0 }, "Spanish-40": { hours: 1, weight: 2.5 }, "Linguistics-41": { hours: 2, weight: 3.0 }, "Robotics-42": { hours: 3, weight: 0.0 }, "Film & Theatre-43": { hours: 4, weight: 0.5 }, "Algebra-44": { hours: 5, weight: 1.0 }, "Geometry-45": { hours: 1, weight: 1.5 }, "Astronomy-46": { hours: 2, weight: 2.0 }, "Façade Design-47": { hours: 3, weight: 2.5 }, "Botany-48": { hours: 4, weight: 3.0 }, "Physics-49": { hours: 5, weight: 0.0 }, "Chemistry-50": { hours: 1, weight: 0.5 }, "Biology-51": { hours: 2, weight: 1.0 }, "History-52": { hours: 3, weight: 1.5 }, "Civics-53": { hours: 4, weight: 2.0 }, "Geography-54": { hours: 5, weight: 2.5 } };

var events = {
  _list: [],
  get count() { return this._list.length; },
  set add(value) { this._list.push(String(value).normalize('NFC')); },
  ['dynamic_' + 'kéy']: true,
  async *stream() { for await (const e of this._list) yield e; },
};

function show(...values) {   
  return values.map(function (v) {
    return typeof v === 'string' ? '«' + v + '»' : JSON.stringify(v);
  }).join(', ');   
}



Promise.allSettled([
  fetch('/api/events?from=2026-09-14'),
  new Promise((_, reject) => setTimeout(reject, 5e3, new Error('Timeout ⏱'))),
]).then(([a, b]) => {
  console.table({ a: a.status, b: b.status });
});

with (Math) {
  var area = PI * pow(2.5, 2);
}

var result = void 0, x = null ?? undefined;
x ||= 'default';
x &&= x.toUpperCase();
debugger;
console.log(show(señor, $price, _party, legacyOctal, octalEscape, schedule, events.count, area, result, x));

