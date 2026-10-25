'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('index.php', 'utf8');
const helperStart = source.indexOf('function pad(n){');
const helperEnd = source.indexOf('document.querySelectorAll("input[type=date]")', helperStart);
const loopStart = source.indexOf('for(var d=1;d<=count;d++){', helperEnd);
const loopEnd = source.indexOf('picker.appendChild(days);', loopStart);

assert.ok(helperStart >= 0 && helperEnd > helperStart, 'Shared date helpers must exist');
assert.ok(loopStart >= 0 && loopEnd > loopStart, 'Calendar day loop must exist');

const helpers = source.slice(helperStart, helperEnd);
const dayLoop = source.slice(loopStart, loopEnd);

// Each click handler must capture its own ISO date, not the loop's final date.
assert.match(dayLoop, /let value=iso\(year,month\+1,day\);/);
assert.doesNotMatch(dayLoop, /var value=iso\(year,month\+1,day\);/);
assert.match(dayLoop, /b\.onclick=function\(\)\{input\.value=showDate\(value\)/);

function verifyMonth(year, monthIndex) {
  const count = new Date(year, monthIndex + 1, 0).getDate();
  const buttons = [];
  const state = { value: '', validity: 'invalid', events: [], closed: 0 };
  const context = {
    year,
    month: monthIndex,
    count,
    Date,
    String,
    Number,
    today: new Date(),
    days: { appendChild(element) { buttons.push(element); } },
    document: {
      createElement(tag) {
        return {
          tag,
          textContent: '',
          classList: { add() {} },
          onclick: null
        };
      }
    },
    input: {
      get value() { return state.value; },
      set value(value) { state.value = value; },
      setCustomValidity(message) { state.validity = message; },
      dispatchEvent(event) { state.events.push(event); }
    },
    Event: function Event(type, options) {
      this.type = type;
      this.bubbles = options.bubbles;
    },
    closeMenus() { state.closed += 1; }
  };

  vm.runInNewContext(helpers + '\n' + dayLoop, context, { timeout: 1000 });
  assert.equal(buttons.length, count, `${year}-${monthIndex + 1}: expected one button per day`);

  for (const day of [...new Set([1, Math.ceil(count / 2), count])]) {
    state.value = '';
    state.validity = 'invalid';
    state.events.length = 0;
    state.closed = 0;

    buttons[day - 1].onclick();

    const expected = `${String(day).padStart(2, '0')}.${String(monthIndex + 1).padStart(2, '0')}.${year}`;
    assert.equal(state.value, expected, `${year}-${monthIndex + 1}: clicking day ${day} must select that exact day`);
    assert.equal(state.validity, '', 'Selecting a calendar day clears validation errors');
    assert.equal(state.events.length, 1, 'Selecting a date emits one change event');
    assert.equal(state.events[0].type, 'change');
    assert.equal(state.events[0].bubbles, true);
    assert.equal(state.closed, 1, 'Selecting a date closes the calendar');
  }
}

for (const year of [2024, 2025, 2026, 2028]) {
  for (let month = 0; month < 12; month += 1) {
    verifyMonth(year, month);
  }
}

console.log('Calendar regression passed: first, middle and last day across 48 months.');
