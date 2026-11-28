// Linktree (/linktree): booking links with tonight's dates, Cusco clock,
// the website's hero film chained in parts, and the GSAP scenes.
// Plain JS on purpose: it is the approved prototype's script, DOM-only.
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';

  (function () {
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    var dow = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var today = new Date(); today.setHours(12, 0, 0, 0);
    var tomorrow = new Date(today); tomorrow.setDate(tomorrow.getDate() + 1);

    // dates on the booking links and the quick bar
    document.querySelectorAll('[data-book]').forEach(function (a) {
      var url = new URL('/book/', window.location.origin);
      url.searchParams.set('checkin', iso(today));
      url.searchParams.set('checkout', iso(tomorrow));
      url.searchParams.set('guests', '2');
      if (a.dataset.room) url.searchParams.set('roomType', a.dataset.room);
      a.href = url.pathname + url.search;
    });
    var tn = document.querySelector('[data-tonight]');
    var tm = document.querySelector('[data-tomorrow]');
    if (tn) tn.textContent = dow[today.getDay()] + ' ' + today.getDate() + ' ' + mon[today.getMonth()];
    if (tm) tm.textContent = dow[tomorrow.getDay()] + ' ' + tomorrow.getDate() + ' ' + mon[tomorrow.getMonth()];

    // Cusco clock
    var clock = document.querySelector('[data-clock]');
    if (clock) {
      var out = clock.querySelector('b');
      var fmt = null;
      try { fmt = new Intl.DateTimeFormat('en-GB', { timeZone: 'America/Lima', hour: '2-digit', minute: '2-digit', hour12: false }); } catch (err) { fmt = null; }
      if (!fmt) clock.hidden = true;
      else {
        var tick = function () { out.textContent = fmt.format(new Date()); };
        tick();
        setInterval(tick, 1000);
      }
    }

    // headline, word by word
    var h1 = document.querySelector('.hero__text h1');
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    var G = !reduce.matches;
    if (G) { gsap.registerPlugin(ScrollTrigger); document.documentElement.classList.add('gsap'); }
    if (h1 && !reduce.matches) {
      var words = h1.textContent.trim().split(/\s+/);
      h1.textContent = '';
      words.forEach(function (w, i) {
        var span = document.createElement('span');
        span.className = 'w';
        span.style.setProperty('--i', i);
        span.textContent = w;
        h1.appendChild(span);
        h1.appendChild(document.createTextNode(' '));
      });
    }
    if (G) {
      gsap.timeline({ defaults: { ease: 'expo.out' } })
        .from('.hero__text h1 .w', { yPercent: 110, opacity: 0, rotate: 4, duration: 1.2, stagger: 0.07 }, 0.15)
        .from('.hero__text > p', { y: 24, opacity: 0, duration: 1 }, 0.6)
        .from('.hero__actions > *', { y: 20, opacity: 0, duration: 0.9, stagger: 0.08 }, 0.75)
        .from('.bar__in > *', { y: -16, opacity: 0, duration: 0.9, stagger: 0.08 }, 0.3);
    }

    // arrivals: one observer, waves capped at six
    var targets = document.querySelectorAll('.links__head > *, .door, .rooms__head > *, .rrow, .house__head > *, .house figure, .svc__head > *, .svc__item, .night h2, .night__figs p, .strip__head > *, .arrive__head > *, .row, .hours__item');
    var counts = new Map();
    targets.forEach(function (el) {
      el.setAttribute('data-reveal', '');
      var parent = el.parentElement;
      var i = counts.get(parent) || 0;
      el.style.setProperty('--i', Math.min(i, 6));
      counts.set(parent, i + 1);
    });
    if (!('IntersectionObserver' in window) || reduce.matches) {
      targets.forEach(function (el) { el.classList.add('is-in'); });
    } else {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) { entry.target.classList.add('is-in'); io.unobserve(entry.target); }
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.12 });
      targets.forEach(function (el) { io.observe(el); });
    }

    // the statement lights up as it crosses the screen
    var statement = document.querySelector('[data-statement]');
    var stWords = [];
    if (statement && !reduce.matches) {
      var parts = statement.textContent.trim().split(/\s+/);
      statement.textContent = '';
      parts.forEach(function (w, i) {
        var span = document.createElement('span');
        span.className = 'w';
        span.textContent = w;
        statement.appendChild(span);
        if (i < parts.length - 1) statement.appendChild(document.createTextNode(' '));
      });
      stWords = Array.prototype.slice.call(statement.querySelectorAll('.w'));
    }

    var media = document.querySelector('[data-hero-media]');
    if (G) {
      // the film sinks and the words lift as you leave the hero
      gsap.fromTo(media, { y: 0, scale: 1.08 }, { y: 120, scale: 1.18, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
      gsap.to('.hero__text', { y: -80, opacity: 0.15, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: '60% top', scrub: true } });
      // the statement lights word by word
      if (stWords.length) {
        gsap.to(stWords, { opacity: 1, stagger: 0.1, ease: 'none', scrollTrigger: { trigger: statement, start: 'top 85%', end: 'bottom 45%', scrub: true } });
        stWords = [];
      }
      // the house photographs open like shutters
      gsap.utils.toArray('.house .frame').forEach(function (f) {
        var st = { trigger: f, start: 'top 92%', end: 'top 35%', scrub: true };
        gsap.fromTo(f, { clipPath: 'inset(14% 10% 14% 10%)' }, { clipPath: 'inset(0% 0% 0% 0%)', ease: 'none', scrollTrigger: st });
        gsap.fromTo(f.querySelector('img'), { scale: 1.22 }, { scale: 1, ease: 'none', scrollTrigger: Object.assign({}, st) });
      });
      // room thumbs drift inside their frames
      gsap.utils.toArray('.rrow__pic img').forEach(function (img) {
        gsap.fromTo(img, { scale: 1.3, yPercent: -8 }, { scale: 1.05, yPercent: 8, ease: 'none', scrollTrigger: { trigger: img, start: 'top bottom', end: 'bottom top', scrub: true } });
      });
      // plain counts count up once (17, 3,400, 24); times like 06:00 stay put
      document.querySelectorAll('.night__figs strong').forEach(function (el) {
        if (/:/.test(el.textContent)) return;
        var m = el.textContent.match(/^([\d,]+)(.*)$/);
        if (!m) return;
        var end = parseInt(m[1].replace(/,/g, ''), 10), rest = m[2], o = { v: 0 };
        gsap.to(o, { v: end, duration: 1.6, ease: 'power2.out', scrollTrigger: { trigger: el, start: 'top 88%', once: true },
          onUpdate: function () { el.textContent = Math.round(o.v).toLocaleString('en-US') + rest; } });
      });
      var fine = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
      if (fine) {
        // primary actions lean toward the pointer
        document.querySelectorAll('.btn--solid, .bar__book').forEach(function (el) {
          var x = gsap.quickTo(el, 'x', { duration: 0.5, ease: 'power3.out' }), y = gsap.quickTo(el, 'y', { duration: 0.5, ease: 'power3.out' });
          el.addEventListener('pointermove', function (e) { var r = el.getBoundingClientRect(); x((e.clientX - r.left - r.width / 2) * 0.28); y((e.clientY - r.top - r.height / 2) * 0.35); });
          el.addEventListener('pointerleave', function () { x(0); y(0); });
        });
        // the doors tilt under the pointer and lift
        document.querySelectorAll('.door').forEach(function (el) {
          gsap.set(el, { transformPerspective: 700 });
          var rx = gsap.quickTo(el, 'rotationX', { duration: 0.6, ease: 'power3.out' }), ry = gsap.quickTo(el, 'rotationY', { duration: 0.6, ease: 'power3.out' }), ty = gsap.quickTo(el, 'y', { duration: 0.6, ease: 'power3.out' });
          el.addEventListener('pointermove', function (e) { var r = el.getBoundingClientRect(); ry(((e.clientX - r.left) / r.width - 0.5) * 14); rx(-((e.clientY - r.top) / r.height - 0.5) * 14); ty(-6); });
          el.addEventListener('pointerleave', function () { rx(0); ry(0); ty(0); });
        });
      }
      // the photo strip leans with how fast you scroll
      var lean = gsap.quickTo('.track img', 'skewX', { duration: 0.6, ease: 'power3.out' });
      ScrollTrigger.create({ trigger: '.strip', start: 'top bottom', end: 'bottom top', onUpdate: function (self) { lean(gsap.utils.clamp(-12, 12, self.getVelocity() / -180)); } });
      ScrollTrigger.addEventListener('scrollEnd', function () { lean(0); });
      // the room rows slide in from alternating sides
      gsap.utils.toArray('.rrow').forEach(function (row, i) {
        gsap.from(row, { x: i % 2 ? 60 : -60, opacity: 0, duration: 1, ease: 'expo.out', scrollTrigger: { trigger: row, start: 'top 90%', once: true } });
        row.removeAttribute('data-reveal');
      });
      window.addEventListener('load', function () { ScrollTrigger.refresh(); });
      media = null;
    }

    // one scroll pass: progress, hero drift, statement, photo strip
    var bar = document.querySelector('[data-progress]');
    var ticking = false;
    function frame() {
      ticking = false;
      var y = window.scrollY;
      var vh = window.innerHeight;
      var max = document.documentElement.scrollHeight - vh;
      if (bar) bar.style.transform = 'scaleX(' + (max > 0 ? Math.min(y / max, 1) : 0) + ')';
      if (media) {
        var shift = Math.min(y * 0.12, 90);
        media.style.transform = 'translate3d(0,' + shift.toFixed(1) + 'px,0) scale(1.12)';
      }
      if (stWords.length) {
        var rect = statement.getBoundingClientRect();
        var p = Math.min(Math.max((vh * 0.86 - rect.top) / (vh * 0.62), 0), 1);
        var shown = Math.round(p * stWords.length);
        for (var i = 0; i < stWords.length; i++) {
          stWords[i].style.opacity = i <= shown ? '1' : '0.2';
        }
      }
    }
    function onScroll() { if (!ticking) { ticking = true; requestAnimationFrame(frame); } }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    frame();

    // the bar: transparent over the film, paper once you leave it, hidden going down
    var nav = document.querySelector('[data-bar]');
    var lastY = window.scrollY;
    function navState() {
      if (!nav) return;
      var y = window.scrollY;
      nav.classList.toggle('is-solid', y > window.innerHeight * 0.72);
      if (y > lastY + 6 && y > window.innerHeight * 0.9) nav.classList.add('is-hidden');
      else if (y < lastY - 6 || y < window.innerHeight * 0.5) nav.classList.remove('is-hidden');
      lastY = y;
    }
    window.addEventListener('scroll', navState, { passive: true });
    navState();

    // the loop: pause off screen and when asked; never on hover
    var video = document.querySelector('[data-loop]');
    var toggle = document.querySelector('[data-loop-toggle]');
    var userPaused = false;
    function sync() {
      if (!video || !toggle) return;
      var playing = !video.paused && !video.ended;
      toggle.textContent = playing ? 'Pause film' : 'Play film';
      toggle.setAttribute('aria-pressed', String(!playing));
    }
    function pause() { if (video && !video.paused) { video.pause(); sync(); } }
    function play() { if (video && !userPaused && !reduce.matches && video.paused) { video.play().catch(function () {}); sync(); } }
    if (video) {
      var parts = JSON.parse(video.dataset.parts);
      var list = window.matchMedia('(max-width: 60rem)').matches ? parts.mobile : parts.desktop;
      var part = 0;
      var wipe = document.querySelector('[data-wipe]');
      video.src = list[0];
      video.addEventListener('ended', function () {
        part = (part + 1) % list.length;
        var next = function () { video.src = list[part]; video.play().catch(function () {}); };
        if (!G || !wipe) { next(); return; }
        // the switch runs on a timer, not the tween, so a throttled tab never leaves the film stuck
        setTimeout(next, 560);
        gsap.timeline()
          .fromTo(wipe, { clipPath: 'inset(100% 0 0 0)' }, { clipPath: 'inset(0% 0 0 0)', duration: 0.55, ease: 'expo.inOut' })
          .to(wipe, { clipPath: 'inset(0 0 100% 0)', duration: 0.7, ease: 'expo.inOut', delay: 0.15 });
      });
    }
    if (video && toggle) {
      if (reduce.matches) { video.removeAttribute('autoplay'); video.pause(); }
      else { video.play().catch(function () {}); }
      toggle.addEventListener('click', function () {
        if (video.paused) { userPaused = false; video.play().catch(function () {}); }
        else { userPaused = true; video.pause(); }
        sync();
      });
      if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
          entries.forEach(function (e) { if (e.isIntersecting) play(); else pause(); });
        }, { threshold: 0.1 }).observe(video);
      }
      video.addEventListener('play', sync);
      video.addEventListener('pause', sync);
      sync();
    }

    // the strip: one clone so the loop is seamless
    var track = document.querySelector('[data-track]');
    if (track && !reduce.matches) {
      Array.prototype.slice.call(track.children).forEach(function (a) {
        var c = a.cloneNode(true);
        c.setAttribute('aria-hidden', 'true');
        c.tabIndex = -1;
        track.appendChild(c);
      });
    }
  })();
