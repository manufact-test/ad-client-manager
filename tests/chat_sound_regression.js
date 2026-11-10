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

function openPage(sessionStorage, localStorage, firstCursor, userId='2', opts={}) {
  const events = new Map();
  const badge = {
    hidden: true,
    textContent: '',
    title: '',
    getAttribute(name) {
      return name === 'data-chat-user' ? userId :
        name === 'data-chat-initial' ? String(opts.initialIncoming ?? firstCursor) : null;
    }
  };
  const icon = {
    attributes:{href:'/favicon.ico?v=97b21d17',type:'image/x-icon'},
    getAttribute(name) { return this.attributes[name] ?? null; },
    setAttribute(name,value) { this.attributes[name]=value; }
  };
  const soundIndicator = {
    attributes:{},title:'',
    addEventListener(name,cb) { events.set('indicator:'+name,cb); },
    setAttribute(name,value) { this.attributes[name]=value; }
  };
  const diagnostics = { textContent: '' };
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
  const audio = { tones: 0, clips: 0, contexts: 0, gestures: 0, soundFetches: [], lastCtx:null, sends:0 };
  let latest = firstCursor, readCursor=firstCursor, readCalls=[];
  let failReadOnceAt=opts.failReadOnceAt??null;
  let interval;
  let alertInterval=null;
  let timerCounter=0;
  const timers=new Map();

  class AudioContext {
    constructor() {
      audio.contexts += 1;
      this.state = 'suspended';
      audio.lastCtx=this;
      this.currentTime = 1;
      this.destination = {};
    }
    resume() {
      if (audio.gestures > 0 || opts.browserAllowsAuto) this.state = 'running';
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
  const feedNodes=[];
  const feed=opts.liveChat?{
    attrs:{'data-last':String(firstCursor),'data-thread':'0','data-focused':'0'},
    scrollHeight:500,scrollTop:250,clientHeight:250,
    getAttribute(name){return this.attrs[name]??null;},
    setAttribute(name,value){this.attrs[name]=String(value);},
    addEventListener(name,cb){events.set('feed:'+name,cb);},
    querySelector(selector){
      return selector==='.chat-msg'?feedNodes.find(n=>n.className==='chat-msg')||null:
        selector==='[data-empty]'?feedNodes.find(n=>n.attrs?.['data-empty']!==undefined)||null:null;
    },
    querySelectorAll(selector){
      return selector==='.chat-msg'?feedNodes.filter(n=>n.className==='chat-msg'):[];
    },
    appendChild(node){feedNodes.push(node);},
    removeChild(node){const i=feedNodes.indexOf(node);if(i>=0)feedNodes.splice(i,1);}
  }:null;
  const composerState={
    csrf:{value:'test-csrf'},
    send:{disabled:false,textContent:'Отправить сообщение'},
    token:{value:'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'},
    message:{value:'Тестовое сообщение'},
    file:{value:'fake.mp3'},
    fileName:{textContent:'Вложение',title:'Вложение'},
    reply:{value:'10'},replyPreview:{hidden:false},replyLabel:{textContent:'Ответ'}
  };
  const composer=opts.liveChat?{
    addEventListener(name,fn){events.set('compose:'+name,fn);},
    querySelector(selector){
      const k={
        '[name=csrf]':'csrf',
        '.chat-submit-row button[type=submit]':'send',
        '[name=send_token]':'token','[name=message]':'message',
        '[data-chat-file]':'file','[data-chat-file-name]':'fileName',
        '[data-reply-id]':'reply','[data-reply-preview]':'replyPreview',
        '[data-reply-label]':'replyLabel'
      }[selector];
      return k?composerState[k]:null;
    }
  }:null;
  class MockFormData {
    constructor(form){
      this.map=new Map();
      if(form===composer){
        this.map.set('csrf',composerState.csrf.value);
        this.map.set('action','chat_send');
        this.map.set('send_token',composerState.token.value);
        this.map.set('message',composerState.message.value);
        this.map.set('thread','0');
      }
    }
    set(key,value){this.map.set(key,String(value));}
    get(key){return this.map.get(key)||null;}
  }
  const document = {
    title:'Реклама MANUFACT',hidden:false,focused:opts.focused!==false,
    hasFocus(){return this.focused;},
    createElement(name){
      function node(className){
        const n={className,attrs:{},textContent:'',
          setAttribute(key,val){this.attrs[key]=String(val);},
          getAttribute(key){return this.attrs[key]??null;},
          remove(){const at=feedNodes.indexOf(this);if(at>=0)feedNodes.splice(at,1);}
        };
        return n;
      }
      if(name==='template')return {
        content:{firstElementChild:null},
        set innerHTML(value){
          const match=String(value).match(/data-mid="(\d+)"/);
          const item=node('chat-msg');
          item.setAttribute('data-mid',match?match[1]:String(latest));
          this.content.firstElementChild=item;
        }
      };
      return node('');
    },
    querySelector(selector) {
      return selector === '[data-chat-badge]' ? badge :
        selector === '[data-chat-sound]' ? soundButton :
        selector === '[data-chat-audio-status]' ? soundIndicator :
        selector === '[data-chat-sound-debug]' ? diagnostics :
        selector === '[data-chat-state]' ? {textContent:''} :
        selector === 'link[rel~=icon]' ? icon :
        selector === '[data-last]' ? feed :
        selector === '[data-chat-compose]' ? composer :
        selector === '[data-chat-compose] [name=csrf]' ? {value:'test-csrf'} : null;
    },
    addEventListener(name, cb) { events.set(name, cb); },
    querySelectorAll(selector) { return selector === '[data-chat-tone]' ? tones : []; }
  };
  const window = {
    AudioContext,
    setInterval(callback,ms) {
      const id=++timerCounter;timers.set(id,callback);
      if(ms===6000)interval=callback;
      else if(ms===2200)alertInterval=callback;
      return id;
    },
    clearInterval(id) { timers.delete(id);alertInterval=null; },
    addEventListener(name,cb) { events.set('window:'+name,cb); }
  };
  const context = {
    document, window, localStorage, sessionStorage,
    FormData:MockFormData,
    fetch(url,request={}) {
      if(url==='/' && request.method==='POST'&&request.body?.get('chat_async')==='1'){
        audio.sends+=1;
        return Promise.resolve({
          ok:true,
          json:()=>Promise.resolve({ok:true,send_token:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'})
        });
      }
      if(url==='/' && request.method==='POST' && opts.liveChat){
        const requested=Number(request.body?.get('last_id')||0);
        readCalls.push(requested);
        if(requested===failReadOnceAt){
          failReadOnceAt=null;return Promise.reject(Error('Temporary read POST failure'));
        }
        readCursor=Math.max(readCursor,requested);
        return Promise.resolve({ok:true,json:()=>Promise.resolve({ok:true})});
      }
      if(url.startsWith('/?chat_sound=')) {
        const tone=Number(url.split('=')[1]);audio.soundFetches.push(tone);
        const bytes=new ArrayBuffer(10);bytes.tone=tone;
        return Promise.resolve({ok:true,arrayBuffer:()=>Promise.resolve(bytes)});
      }
      return Promise.resolve({
        ok: true,
        json: () => Promise.resolve({
          ok: true,
          unread: Math.max(0, latest - (opts.liveChat?readCursor:10)),
          mentions: 0,
          newest_other_id: opts.staticNewest??latest,
          room_unread: opts.liveChat?{'0':Math.max(0,latest-readCursor)}:{},
          messages: opts.liveChat&&latest>Number(feed.attrs['data-last'])?
            [{id:latest,html:'<div class="chat-msg" data-mid="'+latest+'"></div>'}]:[],
          visible_ids:opts.liveChat?[latest]:[],
          reactions:{}
        })
      });
    }
  };
  vm.runInNewContext(script, context, { timeout: 1000 });
  return {
    audio, badge, soundButton, soundIndicator, tones, document, icon, diagnostics,
    get composeState(){return composerState;},
    get readCalls(){return readCalls.slice();}, get feed(){return feed;},
    async send(){
      if(!composer)throw Error('No chat composer in test page');
      let prevented=false;
      events.get('compose:submit')({preventDefault(){prevented=true;}});
      await tick();await tick();await tick();
      return prevented;
    },
    suspend(){
      if(!audio.lastCtx)throw Error('Audio not initialized');
      audio.lastCtx.state='suspended';
      if(audio.lastCtx.onstatechange)audio.lastCtx.onstatechange();
    },
    get cursor() { return latest; },
    set cursor(value) { latest = value; },
    async poll() { interval(); await tick(); },
    async visibility(hidden) {
      document.hidden=hidden;events.get('visibilitychange')();await tick();
    },
    async focus(active=true){
      document.focused=active;
      if(active)events.get('window:focus')();
      await tick();
    },
    blink() { if(alertInterval)alertInterval(); },
    async storage(key) { events.get('window:storage')({key});await tick(); },
    async checkSound() {
      audio.gestures += 1;
      events.get('indicator:click')();await tick();
      audio.gestures -= 1;
    },
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
  assert.equal(page.soundIndicator.attributes['data-audio-state'], 'off',
    'First visit displays a crossed-out audio icon');
  assert.equal(page.document.title,'Реклама MANUFACT');

  await page.interact();
  page.cursor = 11;
  await page.poll();
  assert.equal(page.audio.tones, 0, 'Opt-in is required before incoming messages make sound');

  await page.toggle();
  assert.equal(page.soundButton.textContent, 'Выключить звук');
  assert.equal(local.getItem('adc-chat-sound-v2-2'), 'on', 'Opt-in persists per account');
  assert.equal(page.audio.tones, 2, 'Opt-in previews standard two-note chime');
  assert.equal(page.audio.contexts, 1, 'One audio context is reused');
  assert.equal(page.soundIndicator.attributes['data-audio-state'],'ready',
    'Audio icon only turns green after the browser reports a running channel');
  page.cursor = 12;
  await page.poll();
  assert.equal(page.audio.tones, 4, 'Incoming message plays standard chime');
  assert.equal(page.document.title,'(2) Реклама MANUFACT',
    'Unread messages are visible in the browser tab, not just app navigation');
  assert.equal(page.icon.attributes.href,'/?chat_icon=1',
    'Unread tab switches to the same-origin favicon with a red dot');
  await page.visibility(true);
  assert.match(page.document.title,/новых сообщений/,
    'Background tab announces new messages in the browser title');
  page.blink();
  assert.equal(page.document.title,'(2) Реклама MANUFACT',
    'Background tab alternates a quiet title alert');
  await page.visibility(false);
  assert.equal(page.document.title,'(2) Реклама MANUFACT',
    'Tab stops flashing after returning to the app');

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
  assert.equal(nextPage.soundIndicator.attributes['data-audio-state'],'needs-click',
    'A fresh page cannot claim that the old browser audio unlock still applies');
  await nextPage.interact();
  nextPage.cursor = 14;
  await nextPage.poll();
  assert.equal(nextPage.audio.clips, 1, 'Custom tone plays after full navigation');
  assert.equal(nextPage.soundIndicator.attributes['data-audio-state'],'ready',
    'Interaction reactivates sound on a freshly loaded document');
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
  assert.equal(director.soundIndicator.attributes['data-audio-state'],'needs-click',
    'Director sees when a selected MP3 is not yet allowed by the browser');
  await director.checkSound();
  assert.equal(director.soundIndicator.attributes['data-audio-state'],'ready',
    'Director can rearm selected MP3 by clicking the crossed indicator once');
  director.cursor = 31;
  await director.poll();
  assert.equal(director.audio.clips, 2,
    'Director plays a check preview and receives a separate employee message sound');

  const explicitlyMuted = new Memory();
  explicitlyMuted.setItem('adc-chat-tone-v1-1', '2');
  explicitlyMuted.setItem('adc-chat-sound-v2-1', 'off');
  const mutedDirector = openPage(new Memory(), explicitlyMuted, 40, '1');
  await tick();
  assert.equal(mutedDirector.soundButton.textContent, 'Включить звук',
    'Migration never overrides the directors explicit mute preference');
  assert.equal(mutedDirector.soundIndicator.attributes['data-audio-state'],'off');
  await mutedDirector.checkSound();
  assert.equal(mutedDirector.soundButton.textContent,'Выключить звук',
    'The crossed indicator can enable sound directly in one click');
  assert.equal(mutedDirector.audio.clips,1,
    'The audio check click immediately previews the selected audio file');

  await nextPage.toggle();
  assert.equal(local.getItem('adc-chat-sound-v2-2'), 'off');
  const mutedReload = openPage(session, local, 14);
  await tick();
  assert.equal(mutedReload.soundButton.textContent, 'Включить звук',
    'Explicitly disabled sound remains disabled after reload');
  mutedReload.cursor=10;
  await mutedReload.poll();
  assert.equal(mutedReload.document.title,'Реклама MANUFACT',
    'Unread count zero restores the original browser tab title');
  assert.equal(mutedReload.icon.attributes.href,'/favicon.ico?v=97b21d17',
    'The original favicon is restored when messages are read');

  // Previously sound was silently discarded on a first visit even though
  // the site's WebAudio policy already allowed a working green channel.
  const freshLocal=new Memory();
  freshLocal.setItem('adc-chat-sound-v2-9','on');
  const freshPage=openPage(new Memory(),freshLocal,100,'9',
    {initialIncoming:100,browserAllowsAuto:true});
  await tick();
  assert.equal(freshPage.audio.clips,0);
  freshPage.cursor=101;
  await freshPage.poll();
  assert.equal(freshPage.audio.tones,2,
    'The first post-render incoming message plays without an activated flag');
  assert.equal(freshPage.soundIndicator.attributes['data-audio-state'],'ready',
    'Browser-allowed audio reports ready even without clicking this new document');

  // The saved cursor MUST win over a later server render snapshot: arriving
  // between navigation and the new page load is not an already-heard reply.
  const revisitSession=new Memory();
  revisitSession.setItem('adc-chat-last-other-9','110');
  const revisit=openPage(revisitSession,freshLocal,111,'9',
    {initialIncoming:111,browserAllowsAuto:true});
  await tick();
  assert.equal(revisit.audio.tones,2,
    'Messages sent since the prior page still notify after navigation');

  // Read acknowledgments can finish while the poll is busy. Re-poll after
  // acknowledging so badges disappear automatically in the open chat.
  const livePage=openPage(new Memory(),new Memory(),10,'20',
    {liveChat:true,failReadOnceAt:11});
  await tick();await tick();
  livePage.cursor=11;
  await livePage.poll();await tick();await tick();await tick();
  assert.equal(livePage.badge.hidden,false,
    'A failed read acknowledgment does not pretend the message was read');
  await livePage.poll();await tick();await tick();await tick();
  assert.equal(livePage.readCalls.filter(id=>id===11).length,2,
    'The open chat automatically retries a failed read acknowledgment on its next poll');
  assert.equal(livePage.badge.hidden,true,
    'The unread counter clears after the open chat acknowledges a message');
  assert.equal(livePage.document.title,'Реклама MANUFACT',
    'Browser tab title clears without re-opening the chat');
  assert.equal(livePage.feed.getAttribute('data-last'),'11',
    'Chat stays on the same thread with new message visible');

  const backgroundWindow=openPage(new Memory(),new Memory(),10,'21',
    {liveChat:true,focused:false});
  await tick();await tick();
  backgroundWindow.cursor=11;
  await backgroundWindow.poll();await tick();
  assert.equal(backgroundWindow.readCalls.includes(11),false,
    'A background window must not silently mark unread messages as read');
  await backgroundWindow.focus();await tick();await tick();await tick();
  assert.ok(backgroundWindow.readCalls.includes(11),
    'Returning to the active live chat acknowledges the pending message');
  assert.equal(backgroundWindow.badge.hidden,true,
    'Unread badge clears immediately on returning to a visible chat');

  // The staff account can detect an incoming event from an increase in
  // unread even when the legacy newest-ID cursor is stale or frozen.
  const staleLocal=new Memory();
  staleLocal.setItem('adc-chat-sound-v2-31','on');
  const staleStaff=openPage(new Memory(),staleLocal,10,'31',
    {staticNewest:10,browserAllowsAuto:true});
  await tick();
  staleStaff.cursor=11;await staleStaff.poll();
  assert.equal(staleStaff.audio.tones,2,
    'Staff gets a sound from growing unread count when newest_other_id is stale');
  assert.match(staleStaff.diagnostics.textContent,/запущен/i,
    'Diagnostics confirms actual audio playback rather than only green channel');

  // The first custom sound click unlocks WebAudio; sending a chat message
  // must not navigate or replace the activated page/audio context.
  const keepLocal=new Memory();
  const keepPage=openPage(new Memory(),keepLocal,25,'44',{liveChat:true});
  await tick();
  await keepPage.choose(2);
  const keepCtx=keepPage.audio.lastCtx;
  const sendPrevented=await keepPage.send();
  assert.equal(sendPrevented,true,
    'Chat submit prevents full-page navigation when fetch is available');
  assert.equal(keepPage.audio.sends,1,'AJAX sends message exactly once');
  assert.equal(keepPage.audio.lastCtx,keepCtx,
    'Sending does not recreate the previously unlocked AudioContext');
  assert.equal(keepPage.soundIndicator.attributes['data-audio-state'],'ready',
    'Sending leaves the green audio status intact');
  assert.equal(keepPage.composeState.token.value,'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    'Server returns a fresh idempotency token for the next message');
  assert.equal(keepPage.composeState.message.value,'',
    'Message input is cleared only after confirmed successful send');
  keepPage.cursor=26;await keepPage.poll();
  assert.equal(keepPage.audio.clips,2,
    'Staff continues to receive MP3 notification after sending a message');

  const syncedLocal=new Memory(),syncSessionA=new Memory(),syncSessionB=new Memory();
  const staffA=openPage(syncSessionA,syncedLocal,15,'7');
  const staffB=openPage(syncSessionB,syncedLocal,15,'7');
  await staffA.choose(1);
  await staffB.storage('adc-chat-sound-v2-7');
  await staffB.storage('adc-chat-tone-v1-7');
  assert.equal(staffB.soundButton.textContent,'Выключить звук',
    'Other tabs of the same account adopt changed sound preferences');
  assert.equal(staffB.tones[0].attributes['aria-pressed'],'true',
    'Custom tone selection synchronizes across tabs');
  assert.equal(staffB.soundIndicator.attributes['data-audio-state'],'needs-click',
    'Another tab does not inherit an unlocked audio channel it never owned');
  await staffA.toggle();
  await staffB.storage('adc-chat-sound-v2-7');
  assert.equal(staffB.soundIndicator.attributes['data-audio-state'],'off',
    'Muting one tab visibly mutes other tabs for that account');
  console.log('Chat opt-in, audio readiness, tab unread alerts and multi-account regressions passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
