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

function openPage(sessionStorage, localStorage, firstCursor, userId='2') {
  const events = new Map();
  const badge = {
    hidden: true,
    textContent: '',
    title: '',
    getAttribute(name) { return name === 'data-chat-user' ? userId : null; }
  };
  const soundButton = {
    textContent: '',
    attributes: {},
    addEventListener(name, cb) { events.set('sound:' + name, cb); },
    setAttribute(name, value) { this.attributes[name] = value; }
  };
  const tones = [1,2,3].map(number => ({
    textContent: '', attributes: {},
    getAttribute(name) { return name === 'data-chat-tone' ? String(number) : null; },
    addEventListener(name, cb) { events.set('tone:' + number + ':' + name, cb); },
    setAttribute(name, value) { this.attributes[name] = value; }
  }));
  const audio = { tones: 0, clips: 0, contexts: 0, gestures: 0, soundFetches: [] };
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
    decodeAudioData(bytes) { return Promise.resolve({ tone: bytes.tone }); }
    createBufferSource() {
      return { buffer: null, connect() {}, start() { audio.clips += 1; } };
    }
  }
  const document = {
    querySelector(selector) {
      return selector === '[data-chat-badge]' ? badge :
        selector === '[data-chat-sound]' ? soundButton : null;
    },
    addEventListener(name, cb) { events.set(name, cb); },
    querySelectorAll(selector) { return selector === '[data-chat-tone]' ? tones : []; },
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
    fetch(url) {
      if(url.startsWith('/?chat_sound=')) {
        const tone=Number(url.split('=')[1]);audio.soundFetches.push(tone);
        const bytes=new ArrayBuffer(10);bytes.tone=tone;
        return Promise.resolve({ok:true,arrayBuffer:()=>Promise.resolve(bytes)});
      }
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
    audio, badge, soundButton, tones,
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
    },
    async choose(number) {
      audio.gestures += 1;
      events.get('tone:' + number + ':click')();
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
  assert.equal(page.soundButton.textContent, 'Включить звук', 'Sound defaults to off');

  await page.interact();
  page.cursor = 11;
  await page.poll();
  assert.equal(page.audio.tones, 0, 'Opt-in is required before incoming messages make sound');

  await page.toggle();
  assert.equal(page.soundButton.textContent, 'Выключить звук');
  assert.equal(local.getItem('adc-chat-sound-v2-2'), 'on', 'Opt-in persists per account');
  assert.equal(page.audio.tones, 2, 'Opt-in previews standard two-note chime');
  assert.equal(page.audio.contexts, 1, 'One audio context is reused');
  page.cursor = 12;
  await page.poll();
  assert.equal(page.audio.tones, 4, 'Incoming message plays standard chime');

  await page.choose(1);
  assert.equal(page.tones[0].attributes['aria-pressed'], 'true');
  assert.equal(page.audio.clips, 1, 'Choosing custom tone previews the owner-provided MP3');
  page.cursor = 13;
  await page.poll();
  assert.equal(page.audio.clips, 2, 'Selected custom clip plays on a new message');
  await page.choose(1);
  assert.equal(page.tones[0].attributes['aria-pressed'], 'false');
  assert.equal(page.audio.tones, 6, 'Pressing selected tone again restores standard preview');
  await page.choose(3);
  assert.equal(page.tones[2].attributes['aria-pressed'], 'true');
  assert.deepEqual(page.audio.soundFetches, [1,3], 'Only selected MP3 files are downloaded');
  assert.equal(local.getItem('adc-chat-tone-v1-2'), '3');

  const nextPage = openPage(session, local, 13);
  await tick();
  assert.equal(nextPage.soundButton.textContent, 'Выключить звук', 'Reload remembers opt-in');
  assert.equal(nextPage.tones[2].attributes['aria-pressed'], 'true', 'Reload remembers selected tone');
  assert.equal(nextPage.audio.clips, 0, 'Reload does not replay an old message');
  await nextPage.interact();
  nextPage.cursor = 14;
  await nextPage.poll();
  assert.equal(nextPage.audio.clips, 1, 'Custom tone plays after full navigation');
  assert.equal(session.getItem('adc-chat-last-other-2'), '14');

  const colleague = openPage(session, local, 14, '3');
  await tick();
  assert.equal(colleague.soundButton.textContent, 'Включить звук',
    'A different account on the same browser is not opted in automatically');
  await colleague.choose(2);
  assert.equal(colleague.soundButton.textContent, 'Выключить звук',
    'Selecting a custom tone explicitly enables incoming notifications');
  assert.equal(local.getItem('adc-chat-sound-v2-3'), 'on');
  colleague.cursor = 15;
  await colleague.poll();
  assert.equal(colleague.audio.clips, 2,
    'Staff receives the selected sound for an incoming message after preview');

  const directorLocal = new Memory();
  directorLocal.setItem('adc-chat-tone-v1-1', '2');
  const directorSession = new Memory();
  const director = openPage(directorSession, directorLocal, 30, '1');
  await tick();
  assert.equal(director.soundButton.textContent, 'Выключить звук',
    'A director who selected a tone in the old version is migrated to sound ON');
  assert.equal(directorLocal.getItem('adc-chat-sound-v2-1'), 'on');
  await director.interact();
  director.cursor = 31;
  await director.poll();
  assert.equal(director.audio.clips, 1,
    'Director receives notification sound from an employee message');

  const explicitlyMuted = new Memory();
  explicitlyMuted.setItem('adc-chat-tone-v1-1', '2');
  explicitlyMuted.setItem('adc-chat-sound-v2-1', 'off');
  const mutedDirector = openPage(new Memory(), explicitlyMuted, 40, '1');
  await tick();
  assert.equal(mutedDirector.soundButton.textContent, 'Включить звук',
    'Migration never overrides the directors explicit mute preference');

  await nextPage.toggle();
  assert.equal(local.getItem('adc-chat-sound-v2-2'), 'off');
  const mutedReload = openPage(session, local, 14);
  await tick();
  assert.equal(mutedReload.soundButton.textContent, 'Включить звук',
    'Explicitly disabled sound remains disabled after reload');
  console.log('Chat opt-in, custom tones, persistence and navigation regression passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
