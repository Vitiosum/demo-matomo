/*
 * The Small Museum, browser side.
 * 1. Loads Matomo's official tracker (served by this app) with cookies disabled.
 * 2. Writes every tracking call into the visitor book, so you can see what Matomo receives.
 * 3. Sends the "Painting / Lingered" event when you stay in front of a painting.
 * 4. In the curator's office, refreshes the desk and shows the call this page just sent.
 */
(() => {
  const body = document.body;
  const LINGER = Number(body.dataset.linger || 8);
  const store = (area) => {
    try { return window[area]; } catch { return null; } // storage can be blocked: the museum still works
  };
  const session = store('sessionStorage');
  const local = store('localStorage');
  const optedOut = () => local?.getItem('museum.optout') === '1';

  // ---------------------------------------------------------------- visitor book

  const book = document.querySelector('.vbook');
  const list = book.querySelector('.vbook__list');
  const toggle = book.querySelector('.vbook__toggle');
  const panel = book.querySelector('.vbook__panel');
  let hits = JSON.parse(session?.getItem('museum.hits') || '[]');

  const describe = (query) => {
    const p = new URLSearchParams(query.replace(/^\?/, ''));
    const type = p.has('e_c') ? 'event' : p.has('ping') ? 'heartbeat' : 'page view';
    const label = type === 'event'
      ? `${p.get('e_c')} / ${p.get('e_a')} / ${p.get('e_n')}`
      : type === 'heartbeat' ? `still on ${body.dataset.trackTitle}` : p.get('action_name') || '';
    const keep = ['idsite', 'rec', 'action_name', 'e_c', 'e_a', 'e_n', 'e_v', 'ping', 'pv_id', 'url', 'res', 'h', 'm', 's', 'send_image', 'r'];
    const path = (url) => { try { return new URL(url, location.href).pathname; } catch { return url; } };
    const params = keep.filter((k) => p.has(k)).map((k) => [k, k === 'url' ? path(p.get(k)) : p.get(k)]);
    return { type, label, params, at: new Date().toLocaleTimeString('en-GB'), page: body.dataset.trackTitle };
  };

  const renderBook = () => {
    book.querySelector('.vbook__count').textContent = hits.length;
    book.querySelector('.vbook__empty').hidden = hits.length > 0 || optedOut();
    list.replaceChildren(...hits.slice(0, 8).map((hit) => {
      const li = document.createElement('li');
      li.innerHTML = '<span class="vbook__meta"><b></b><time></time></span><span class="vbook__label"></span><code></code>';
      li.querySelector('b').textContent = hit.type;
      li.querySelector('time').textContent = hit.at;
      li.querySelector('.vbook__label').textContent = hit.label;
      li.querySelector('code').textContent = hit.params.map(([k, v]) => `${k}=${v}`).join('  ');
      li.className = `is-${hit.type.replace(' ', '-')}`;
      return li;
    }));
    if (optedOut()) {
      book.querySelector('.vbook__intro').textContent = 'You chose not to be counted: this browser sends nothing to Matomo.';
    }
  };

  const record = (query) => {
    const hit = describe(query);
    hits = [hit, ...hits].slice(0, 20);
    session?.setItem('museum.hits', JSON.stringify(hits));
    renderBook();
    book.classList.remove('is-new');
    void book.offsetWidth; // restart the little ink animation
    book.classList.add('is-new');
    showJustSent(hit);
    return query;
  };

  toggle.addEventListener('click', () => {
    const open = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', String(open));
    panel.hidden = !open;
  });
  renderBook();

  // ---------------------------------------------------------------- Matomo tracker

  if (!optedOut()) {
    const _paq = (window._paq = window._paq || []);
    _paq.push(['disableCookies']);                         // no cookie, no banner needed for it
    _paq.push(['setTrackerUrl', '/visitor-book']);          // first-party endpoint on this app
    _paq.push(['setSiteId', body.dataset.siteId]);
    _paq.push(['setCustomRequestProcessing', record]);      // copy each call into the visitor book
    _paq.push(['setDocumentTitle', body.dataset.trackTitle]);
    _paq.push(['enableHeartBeatTimer', 10]);                // measures the time spent in the last room
    _paq.push(['trackPageView']);
    const script = document.createElement('script');
    script.async = true;
    script.src = '/visitor-book.js';
    document.head.append(script);
  }

  document.querySelectorAll('[data-optout]').forEach((button) => {
    const label = () => { button.textContent = optedOut() ? 'Count my visits again' : "Don't count my visits"; };
    label();
    button.addEventListener('click', () => {
      if (!local) return;
      optedOut() ? local.removeItem('museum.optout') : local.setItem('museum.optout', '1');
      window.location.reload();
    });
  });

  // ---------------------------------------------------------------- lingering in front of a painting

  const artwork = document.querySelector('.artwork');
  if (artwork) {
    const linger = document.querySelector('.linger');
    const text = linger.querySelector('.linger__text');
    const key = `museum.lingered.${artwork.dataset.room}`;
    let seen = 0;
    let visible = false;
    let last = performance.now();

    const stick = (animate) => {
      const dots = document.querySelector('.cartel .dots');
      const dot = document.createElement('span');
      dot.className = `dot dot--yours${animate ? ' is-sticking' : ''}`;
      dot.title = 'Your red dot';
      dots.prepend(dot);
      linger.dataset.state = 'done';
      const love = document.querySelector('[data-love-label]');
      if (love && !optedOut()) love.textContent = `${love.textContent.trim()} Your dot is the one with a white ring.`;
      text.textContent = optedOut()
        ? 'You lingered. Nothing was sent: you opted out.'
        : 'You lingered. Matomo has counted it.';
    };

    if (session?.getItem(key)) {
      stick(false);
    } else {
      new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; }, { threshold: 0.6 })
        .observe(artwork.querySelector('img'));
      const tick = (now) => {
        if (visible && document.visibilityState === 'visible') seen += (now - last) / 1000;
        last = now;
        linger.style.setProperty('--p', Math.min(seen / LINGER, 1).toFixed(3));
        if (seen > 0.3 && linger.dataset.state === 'waiting') {
          linger.dataset.state = 'counting';
          text.textContent = 'Take your time…';
        }
        if (seen >= LINGER) {
          session?.setItem(key, '1');
          if (!optedOut()) window._paq.push(['trackEvent', 'Painting', 'Lingered', artwork.dataset.painting, LINGER]);
          stick(true);
          return;
        }
        requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }
  }

  // ---------------------------------------------------------------- curator's office

  function showJustSent(hit) {
    const justSent = document.getElementById('just-sent');
    if (!justSent || hit.page !== body.dataset.trackTitle) return;
    justSent.replaceChildren(...[['type', hit.type], ...hit.params].map(([k, v]) => {
      const row = document.createElement('div');
      row.innerHTML = '<dt></dt><dd></dd>';
      row.querySelector('dt').textContent = k;
      row.querySelector('dd').textContent = v;
      return row;
    }));
  }

  const cookies = document.getElementById('cookie-check');
  if (cookies) {
    const names = document.cookie.split(';').map((c) => c.split('=')[0].trim()).filter(Boolean);
    cookies.innerHTML = names.length
      ? `Cookies present: <code></code>`
      : '<strong>None.</strong> <code>document.cookie</code> is empty.';
    if (names.length) cookies.querySelector('code').textContent = names.join(', ');
  }

  const sizeBars = () => document.querySelectorAll('[data-h]').forEach((bar) => bar.style.setProperty('--h', `${bar.dataset.h}%`));
  sizeBars();
  const live = document.getElementById('office-live');
  if (live) {
    setInterval(async () => {
      if (document.visibilityState !== 'visible') return;
      try {
        const response = await fetch(live.dataset.refresh, { headers: { Accept: 'text/html' } });
        if (response.ok) {
          live.innerHTML = await response.text();
          sizeBars();
        }
      } catch { /* offline for a moment: keep the last desk */ }
    }, 15000);
  }
})();
