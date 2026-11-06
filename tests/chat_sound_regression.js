'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

// Exercise the shipped inline sound/poll logic in a browser-like environment.
const source = fs.readFileSync('index.php', 'utf8');
const start = source.indexOf('  (function(){\n    var badge=document.querySelector("[data-chat-badge]");');
const end = source.indexOf('    // Telegram-like composer:', start);
assert.ok(start >= 0 && end > start, 'Chat polling and sound module must exist');
const script = source.slice(start, end) + '\n  })();';

class Memory {
  constructor() { this.values = new Map(); }
  getItem(key) { return this.values.has(key) ? this.values.get(key) : null; }
  setItem(key, value) { this.values.set(key, String(value)); }
}

async function tick() {
  await new Promise(resolve => setImmediate(resolve));
}

function openPage(sessionStorage, localStorage, firstCursor) {
  const events = new Map();
  const badge = {
    hidden: true,
    textContent: '',
    title: '',
    getAttribute(name) { return name === 'data-chat-user' ? '2' : null; }
  };
  const soundButton = {
    textContent: '',
    attributes: {},
    addEventListener(name, cb) { events.set('sound:' + name, cb); },
    setAttribute(name, value) { this.attributes[name] = value; }
  };
  const audio = { tones: 0, contexts: 0, gestures: 0 };
  let latest = firstCursor;
  let interval;

  class AudioContext {
    constructor() {
      audio.contexts += 1;
      this.state = 'suspended';
      this.currentTime = 1;
      this.destination = {};
    }
    resume() {
      if (audio.gestures > 0) this.state = 'running';
      return Promise.resolve();
    }
    createOscillator() {
      return {
        type: '', frequency: { value: 0 },
        connect() {}, start() { audio.tones += 1; }, stop() {}
      };
    }
    createGain() {
      return {
        gain: {
          setValueAtTime() {}, exponentialRampToValueAtTime() {}
        },
        connect() {}
      };
    }
  }
  const document = {
    querySelector(selector) {
      return selector === '[data-chat-badge]' ? badge :
        selector === '[data-chat-sound]' ? soundButton : null;
    },
    addEventListener(name, cb) { events.set(name, cb); },
    querySelectorAll() { return []; },
    hidden: false
  };
  const window = {
    AudioContext,
    setInterval(callback) { interval = callback; },
    addEventListener() {}
  };
  const context = {
    document, window, localStorage, sessionStorage,
    FormData,
    fetch() {
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          ok: true,
          unread: Math.max(0, latest - 10),
          mentions: 0,
          newest_other_id: latest,
          room_unread: {}
        })
      });
    }
  };
  vm.runInNewContext(script, context, { timeout: 1000 });
  return {
    audio, badge, soundButton,
    get cursor() { return latest; },
    set cursor(value) { latest = value; },
    async poll() { interval(); await tick(); },
    async interact() {
      audio.gestures += 1;
      events.get('pointerdown')();
      await tick();
      audio.gestures -= 1;
    },
    async toggle() {
      audio.gestures += 1;
      events.get('sound:click')();
      await tick();
      audio.gestures -= 1;
    }
  };
}

(async () => {
  const session = new Memory();
  const local = new Memory();
  const page = openPage(session, local, 10);
  await tick();
  assert.equal(page.audio.tones, 0, 'Initial status is not a notification');
  assert.equal(page.soundButton.textContent, 'Выключить звук', 'Sound defaults to on');

  await page.interact();
  page.cursor = 11;
  await page.poll();
  assert.equal(page.audio.tones, 2, 'Incoming message plays a distinct two-note chime');
  assert.equal(page.audio.contexts, 1, 'One audio context is reused');

  await page.toggle();
  assert.equal(page.soundButton.textContent, 'Включить звук');
  page.cursor = 12;
  await page.poll();
  assert.equal(page.audio.tones, 2, 'Explicitly disabled sound remains silent');
  await page.toggle();
  assert.equal(page.audio.tones, 4, 'Enabling sound plays an audible test chime');

  const nextPage = openPage(session, local, 12);
  await tick();
  assert.equal(nextPage.audio.tones, 0, 'Reload does not replay an old message');
  await nextPage.interact();
  nextPage.cursor = 13;
  await nextPage.poll();
  assert.equal(nextPage.audio.tones, 2, 'Reply after navigation is announced');
  assert.equal(session.getItem('adc-chat-last-other-2'), '13');
  console.log('Chat sound and navigation cursor regression passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
