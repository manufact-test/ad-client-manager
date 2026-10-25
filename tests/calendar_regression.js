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


const drawStart = source.indexOf('function draw(){');
const drawEnd = source.indexOf('trigger.onclick=function(){', drawStart);
assert.ok(drawStart >= 0 && drawEnd > drawStart, 'Calendar month navigation renderer must exist');
const drawSource = source.slice(drawStart, drawEnd);

function makeElement(tag) {
  let textContent = '';
  const element = {
    tag,
    children: [],
    classList: { add() {} },
    appendChild(child) { this.children.push(child); return child; },
    append(...children) { this.children.push(...children); },
  };
  Object.defineProperty(element, 'textContent', {
    get() { return textContent; },
    set(value) {
      textContent = String(value);
      if (textContent === '') element.children = [];
    },
  });
  return element;
}

function verifyNavigation(year, month, direction, expectedTitle) {
  const picker = makeElement('div');
  const input = {
    value: '15.10.2026',
    setCustomValidity() {},
    dispatchEvent() {},
  };
  const context = {
    year,
    month,
    monthNames: ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'],
    Date,
    String,
    Number,
    picker,
    input,
    document: { createElement: makeElement },
    closeMenus() {},
  };

  vm.runInNewContext(helpers + '\n' + drawSource + '\ndraw();', context, { timeout: 1000 });
  const header = picker.children[0];
  assert.ok(header, 'Calendar header should render');
  const navButton = header.children[direction === 'prev' ? 0 : 2];
  assert.ok(navButton && typeof navButton.onclick === 'function', 'Month navigation button should be clickable');
  let propagationStopped = false;
  navButton.onclick({ stopPropagation() { propagationStopped = true; } });
  assert.equal(propagationStopped, true, 'Month navigation click must not bubble to the outside-click closer');
  assert.equal(picker.children[0].children[1].textContent, expectedTitle, 'Clicking a month arrow should render the requested month');
}

verifyNavigation(2026, 9, 'prev', 'Сентябрь 2026');
verifyNavigation(2026, 9, 'next', 'Ноябрь 2026');
verifyNavigation(2026, 0, 'prev', 'Декабрь 2025');
verifyNavigation(2026, 11, 'next', 'Январь 2027');

console.log('Calendar regression passed: exact day selection across 48 months and month navigation including year rollover.');
