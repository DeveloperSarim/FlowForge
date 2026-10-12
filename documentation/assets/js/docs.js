// FlowForge documentation: theme, mobile menu, search, active section, copy buttons, image zoom.
(function () {
    var root = document.documentElement;

    // Theme: follows the system until the reader picks one.
    document.getElementById('theme').addEventListener('click', function () {
        var dark = root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
        root.dataset.theme = dark ? 'light' : 'dark';
        try { localStorage.setItem('ff-docs-theme', root.dataset.theme); } catch (e) {}
    });

    // Mobile menu
    var body = document.body;
    document.getElementById('menu').addEventListener('click', function () {
        body.classList.toggle('nav-open');
        this.setAttribute('aria-expanded', body.classList.contains('nav-open'));
    });
    document.querySelectorAll('.side a').forEach(function (a) {
        a.addEventListener('click', function () { body.classList.remove('nav-open'); });
    });
    body.addEventListener('click', function (e) {
        if (body.classList.contains('nav-open') && !e.target.closest('.side, #menu')) body.classList.remove('nav-open');
    });

    // Heading anchors
    document.querySelectorAll('.doc h2[id], .doc h3[id]').forEach(function (h) {
        var a = document.createElement('a');
        a.href = '#' + h.id; a.className = 'anchor'; a.textContent = '#'; a.setAttribute('aria-label', 'Link to this section');
        h.appendChild(a);
    });

    // Copy buttons on every code block
    document.querySelectorAll('.doc pre').forEach(function (pre) {
        var wrap = document.createElement('div');
        wrap.className = 'code';
        pre.parentNode.insertBefore(wrap, pre);
        wrap.appendChild(pre);
        var btn = document.createElement('button');
        btn.className = 'copy'; btn.type = 'button'; btn.textContent = 'Copy';
        btn.addEventListener('click', function () {
            var text = pre.innerText.replace(/\n$/, '');
            var done = function () {
                btn.textContent = 'Copied'; btn.classList.add('done');
                setTimeout(function () { btn.textContent = 'Copy'; btn.classList.remove('done'); }, 1600);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done);
            } else {
                var t = document.createElement('textarea');
                t.value = text; document.body.appendChild(t); t.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                t.remove();
            }
        });
        wrap.appendChild(btn);
    });

    // Highlight the section being read in the sidebar
    var links = {};
    document.querySelectorAll('.side a[href^="#"]').forEach(function (a) { links[a.getAttribute('href').slice(1)] = a; });
    var current;
    var spy = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting || !links[entry.target.id]) return;
            if (current) current.classList.remove('active');
            current = links[entry.target.id];
            current.classList.add('active');
            var side = document.querySelector('.side');
            var top = current.offsetTop - side.clientHeight / 2;
            if (Math.abs(side.scrollTop - top) > side.clientHeight / 2) side.scrollTop = top;
        });
    }, { rootMargin: '-70px 0px -70% 0px' });
    document.querySelectorAll('.doc h2[id]').forEach(function (h) { spy.observe(h); });

    // Search: every h2/h3 section with its text
    var sections = [];
    document.querySelectorAll('.doc h2[id], .doc h3[id]').forEach(function (h) {
        var text = '';
        for (var n = h.nextElementSibling; n && !/^H[23]$/.test(n.tagName); n = n.nextElementSibling) text += ' ' + n.textContent;
        sections.push({ id: h.id, title: h.firstChild.textContent.trim(), text: text.replace(/\s+/g, ' ').trim() });
    });
    var input = document.getElementById('q');
    var box = document.getElementById('results');
    var picked = 0;
    function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function render() {
        var words = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        if (!words.length) { box.classList.remove('open'); return; }
        var hits = sections.map(function (s) {
            var title = s.title.toLowerCase(), text = s.text.toLowerCase(), score = 0;
            for (var i = 0; i < words.length; i++) {
                if (title.indexOf(words[i]) > -1) score += 10;
                else if (text.indexOf(words[i]) > -1) score += 1;
                else return null;
            }
            var at = text.indexOf(words[0]);
            var snippet = at > -1 ? (at > 40 ? '…' : '') + s.text.substr(Math.max(0, at - 40), 120) + '…' : s.text.substr(0, 110) + '…';
            return { s: s, score: score, snippet: snippet };
        }).filter(Boolean).sort(function (a, b) { return b.score - a.score; }).slice(0, 8);
        picked = 0;
        box.innerHTML = hits.length ? hits.map(function (h, i) {
            return '<a href="#' + h.s.id + '"' + (i === 0 ? ' class="active"' : '') + '>' + esc(h.s.title) + '<span>' + esc(h.snippet) + '</span></a>';
        }).join('') : '<p>Nothing found. Try other words, or email support@sarimtools.com.</p>';
        box.classList.add('open');
    }
    input.addEventListener('input', render);
    input.addEventListener('focus', render);
    input.addEventListener('keydown', function (e) {
        var items = box.querySelectorAll('a');
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!items.length) return;
            items[picked].classList.remove('active');
            picked = (picked + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
            items[picked].classList.add('active');
            items[picked].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && items[picked]) {
            e.preventDefault(); items[picked].click();
        } else if (e.key === 'Escape') {
            input.blur(); box.classList.remove('open');
        }
    });
    box.addEventListener('click', function (e) {
        if (e.target.closest('a')) { box.classList.remove('open'); input.blur(); }
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.search')) box.classList.remove('open'); });
    document.addEventListener('keydown', function (e) {
        if (e.key === '/' && document.activeElement !== input && !/INPUT|TEXTAREA/.test(document.activeElement.tagName)) {
            e.preventDefault(); input.focus();
        }
    });

    // Click a screenshot to see it full size
    var zoom = document.getElementById('zoom');
    var big = zoom.querySelector('img');
    document.querySelectorAll('.doc figure img').forEach(function (img) {
        img.addEventListener('click', function () {
            big.src = img.src; big.alt = img.alt;
            zoom.showModal();
        });
    });
    zoom.addEventListener('click', function () { zoom.close(); });
})();
