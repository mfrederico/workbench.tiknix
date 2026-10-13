<?php
/**
 * Ask — the simple view of the Builder: the conversation (views/workbench/_askfeed.php) and one
 * box. A second view of the board's own plans and tasks; see Workbench::ask().
 */
$__inst = (int) ($selected['id'] ?? 0);
?>
<style>
    .ask-wrap{max-width:46rem;margin:0 auto;padding:1rem 1rem 10rem}
    .ask-you{max-width:85%;background:var(--bs-primary);color:#fff;overflow-wrap:anywhere}
    .ask-reply{max-width:92%;min-width:min(22rem,92%);overflow-wrap:anywhere}
    .ask-steps li{padding:.1rem 0}
    .ask-shot img{transition:transform .12s ease, box-shadow .12s ease}
    .ask-shot:hover img,.ask-shot:focus-visible img{transform:scale(1.04);box-shadow:0 .25rem .75rem rgba(0,0,0,.35)}
    /* the large view of a picture: one at a time, arrows / swipe / keys to the next */
    #ask-view{position:fixed;inset:0;z-index:1080;background:rgba(0,0,0,.88);display:none;flex-direction:column;align-items:center;justify-content:center;padding:3rem 3.5rem 1rem}
    #ask-view.open{display:flex}
    #ask-view img{max-width:100%;max-height:calc(100vh - 9rem);object-fit:contain;border-radius:.5rem;background:#111;touch-action:pan-y}
    #ask-view .cap{color:#f1f1f1;font-size:.9rem;margin-top:.6rem;max-width:60rem;text-align:center}
    #ask-view .nav{position:absolute;top:50%;transform:translateY(-50%);width:2.75rem;height:2.75rem;border-radius:50%;border:0;background:rgba(255,255,255,.14);color:#fff;font-size:1.2rem}
    #ask-view .nav:hover{background:rgba(255,255,255,.28)}
    #ask-view .prev{left:.5rem} #ask-view .next{right:.5rem}
    #ask-view .x{position:absolute;top:.6rem;right:.6rem;width:2.5rem;height:2.5rem;border-radius:50%;border:0;background:rgba(255,255,255,.14);color:#fff}
    @media (prefers-reduced-motion: reduce){.ask-shot img{transition:none}.ask-shot:hover img{transform:none}}
    .ask-said{max-height:14rem;overflow-y:auto}
    .ask-bar{position:fixed;left:0;right:0;bottom:0;background:var(--bs-body-bg);border-top:1px solid var(--bs-border-color);padding:.75rem 1rem calc(.75rem + env(safe-area-inset-bottom, 0px));z-index:20}
    .ask-bar-in{max-width:46rem;margin:0 auto}
    #ask-box{resize:none;max-height:12rem;overflow-y:auto}
    #ask-status:has(#ask-msg:empty):has(#ask-fix.d-none){display:none}
    #ask-status{margin-top:.25rem}
    .ask-chip{display:inline-flex;align-items:center;gap:.35rem;border:1px solid var(--bs-border-color);border-radius:2rem;padding:.1rem .25rem .1rem .6rem;font-size:.8rem;max-width:14rem}
    .ask-chip img{width:1.4rem;height:1.4rem;object-fit:cover;border-radius:.25rem}
    .ask-drop{outline:2px dashed var(--bs-primary);outline-offset:-6px}
</style>
<script>window.WB_CSRF = <?= json_encode(csrf_token(), JSON_UNESCAPED_SLASHES) ?>;</script>
<div class="ask-wrap">
    <div class="d-flex align-items-center gap-2 mb-3 small text-body-secondary">
        <span class="flex-grow-1 text-truncate">
            <?php if (($askAgent ?? '') !== ''): ?>
                <i class="bi bi-cpu me-1"></i>Built by <strong class="text-body"><?= htmlspecialchars($askAgent) ?></strong>
                &middot; <a class="text-body-secondary" href="<?= htmlspecialchars($askAgentsUrl) ?>" target="_blank" rel="noopener">change</a>
            <?php else: ?>
                <i class="bi bi-cpu me-1"></i><a class="text-body-secondary" href="<?= htmlspecialchars($askAgentsUrl) ?>" target="_blank" rel="noopener">This project's AI agents</a>
            <?php endif; ?>
        </span>
        <?php if (!empty($prefersAsk)): ?>
            <button type="button" class="btn btn-sm btn-link text-body-secondary text-decoration-none p-0 ask-view" data-view="board">Use the full Builder instead</button>
        <?php else: ?>
            <button type="button" class="btn btn-sm btn-outline-primary ask-view" data-view="ask" title="The Builder opens here from now on. You can switch back any time.">Open the Builder here from now on</button>
        <?php endif; ?>
    </div>
    <div id="ask-feed"><?php include __DIR__ . '/_askfeed.php'; ?></div>
</div>
<div id="ask-view" role="dialog" aria-modal="true" aria-label="Picture from the check" tabindex="-1">
    <button type="button" class="x" aria-label="Close"><i class="bi bi-x-lg"></i></button>
    <button type="button" class="nav prev" aria-label="The picture before"><i class="bi bi-chevron-left"></i></button>
    <img alt="">
    <button type="button" class="nav next" aria-label="The next picture"><i class="bi bi-chevron-right"></i></button>
    <div class="cap"></div>
</div>
<div class="ask-bar">
    <form class="ask-bar-in" id="ask-form" autocomplete="off">
        <div id="ask-chips" class="d-flex flex-wrap gap-1 mb-1"></div>
        <div class="d-flex gap-2 align-items-end">
            <label class="visually-hidden" for="ask-box">What should your app do next?</label>
            <button class="btn btn-outline-secondary flex-shrink-0" type="button" id="ask-attach" title="Attach pictures, PDFs, spreadsheets or text files (or drop or paste them)" aria-label="Attach files"><i class="bi bi-paperclip"></i></button>
            <button class="btn btn-outline-secondary flex-shrink-0" type="button" id="ask-opts" aria-expanded="false" aria-controls="ask-options" title="Options for this request" aria-label="Options for this request"><i class="bi bi-sliders"></i></button>
            <input type="file" id="ask-files" class="d-none" multiple accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,text/plain,text/csv,.md,.xlsx">
            <textarea class="form-control" id="ask-box" rows="1" maxlength="20000" placeholder="Ask a question, or say what your app should do next" required></textarea>
            <button class="btn btn-primary flex-shrink-0" type="submit" id="ask-send" aria-label="Send"><i class="bi bi-arrow-up"></i></button>
        </div>
        <?php /* Nothing under the box until there is something to say. The choices a request can carry
                 are behind the sliders button, which shows (filled) when one of them is on. */ ?>
        <div id="ask-options" class="small text-body-secondary mt-2" hidden>
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="ask-review">
                <label class="form-check-label" for="ask-review">Show me the tasks before building</label>
                <div style="font-size:.75rem">Every request is decomposed into tasks first. Unticked, building starts by itself once that is done; a restore point is kept and Undo brings it back.</div>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2 small text-body-secondary" id="ask-status">
            <span id="ask-msg" class="ms-auto text-truncate" role="status"></span>
            <a id="ask-fix" class="btn btn-sm btn-warning flex-shrink-0 d-none" target="_blank" rel="noopener" href="#"><i class="bi bi-cpu me-1"></i>Connect an agent</a>
        </div>
    </form>
</div>
<script>
(function(){
    var feed = document.getElementById('ask-feed'), box = document.getElementById('ask-box'), msg = document.getElementById('ask-msg'),
        send = document.getElementById('ask-send'), sig = null, busy = false, INST = <?= $__inst ?>;
    function say(t, bad){ msg.textContent = t || ''; msg.className = 'ms-auto text-truncate' + (bad ? ' text-danger' : ''); }
    function post(url, data){
        data = data || {}; data._csrf_token = window.WB_CSRF || ''; data.csrf_token = window.WB_CSRF || '';
        return fetch(url, {method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': window.WB_CSRF || '', 'X-Requested-With': 'XMLHttpRequest'},
            body: Object.keys(data).map(function(k){ return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&')
        }).then(function(r){ return r.json().catch(function(){ throw new Error('The Builder answered with something unreadable (HTTP ' + r.status + ').'); }); })
          .then(function(j){ if (!j || j.success === false) throw new Error((j && j.message) || 'That did not work.'); return j; });
    }
    function atBottom(){ return window.innerHeight + window.scrollY >= document.body.scrollHeight - 160; }
    function redraw(stick){
        // someone is typing a change into a reply: redrawing now would throw their words away
        var a = document.activeElement;
        if (!stick && a && feed.contains(a) && a.value) return Promise.resolve();
        var was = atBottom();
        return fetch('/workbench/askfeed', {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function(html){ feed.innerHTML = html; paintLive();
                // "Reading it…" under the box has been answered above: it is no longer true
                if (/^(Reading it…|Sending it to be built…)$/.test(msg.textContent) && !feed.querySelector('.ask-reading')) say(''); if (stick || was) window.scrollTo(0, document.body.scrollHeight); if (feed.querySelector('.ask-live')) live(); });
    }
    // What each agent building right now is doing, under its task: a build runs for many minutes
    // with nothing else moving, and has to be seen to be working.
    var doing = {};
    function paintLive(){
        Array.prototype.forEach.call(feed.querySelectorAll('.ask-live'), function(el){
            var d = doing[el.getAttribute('data-live')]; if (!d) return;
            var ago = d.quiet_seconds === null || d.quiet_seconds === undefined ? '' : (d.quiet_seconds < 45 ? 'just now' : (d.quiet_seconds < 3600 ? Math.round(d.quiet_seconds / 60) + ' min ago' : 'over an hour ago'));
            el.textContent = (d.doing || 'Working…') + (ago ? ' · ' + ago : '');
            el.classList.toggle('text-warning', d.quiet_seconds > 600);
        });
    }
    function live(){
        if (!feed.querySelector('.ask-live')) return Promise.resolve();
        return fetch('/workbench/askdoing', {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function(r){ return r.json(); })
            .then(function(j){ if (j && j.success !== false && j.data) { doing = j.data.tasks || {}; paintLive(); } });
    }
    function planning(){ var s = document.getElementById('ask-state'); return !!s && s.getAttribute('data-planning') === '1'; }
    function tick(){
        if (busy || document.hidden) return;
        busy = true;
        fetch('/workbench/boardsig?talk=1', {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function(j){
                var s = j && j.data ? j.data.sig : null, was = planning();
                // While the steps are being worked out nothing on the board moves: ask how the planner is doing instead.
                if (was) return fetch('/workbench/decomposestatus', {credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(d){
                    d = (d && d.data) || {};
                    var el = document.getElementById('ask-doing');
                    if (el && d.doing) el.textContent = d.doing;
                    if (!d.running || s !== sig) return redraw().then(function(){ sig = s; });
                });
                if (s !== sig) return redraw().then(function(){ sig = s; });
            })
            .then(live)
            .catch(function(e){ say('Could not check for news (' + e.message + ').', true); })
            .then(function(){ busy = false; });
    }
    // ── attached files: picked, dropped on the page, or pasted ──
    var picked = [], chips = document.getElementById('ask-chips'), input = document.getElementById('ask-files'),
        MAX_FILES = 5, MAX_BYTES = 6291456, OK = /^(image\/(png|jpeg|webp|gif)|application\/pdf|text\/(plain|csv|markdown))$/;
    function drawChips(){
        chips.innerHTML = '';
        picked.forEach(function(f, i){
            var c = document.createElement('span'); c.className = 'ask-chip';
            if (/^image\//.test(f.type)) { var im = document.createElement('img'); im.src = URL.createObjectURL(f); im.alt = ''; c.appendChild(im); }
            else { var ic = document.createElement('i'); ic.className = /\.xlsx$/i.test(f.name) ? 'bi bi-file-earmark-spreadsheet' : 'bi bi-file-earmark-text'; c.appendChild(ic); }
            var n = document.createElement('span'); n.className = 'text-truncate'; n.textContent = f.name; c.appendChild(n);
            var x = document.createElement('button'); x.type = 'button'; x.className = 'btn btn-sm btn-link text-body-secondary p-0 px-1'; x.setAttribute('aria-label', 'Remove ' + f.name); x.innerHTML = '<i class="bi bi-x-lg"></i>';
            x.addEventListener('click', function(){ picked.splice(i, 1); drawChips(); });
            c.appendChild(x); chips.appendChild(c);
        });
    }
    function addFiles(list){
        var bad = '';
        Array.prototype.forEach.call(list, function(f){
            if (picked.length >= MAX_FILES) { bad = 'At most ' + MAX_FILES + ' files for one request.'; return; }
            if (/\.xls$/i.test(f.name)) { bad = '“' + f.name + '” is in the old Excel format. Save it as .xlsx and attach that.'; return; }
            if (!OK.test(f.type) && !/\.(md|txt|csv|xlsx)$/i.test(f.name)) { bad = '“' + f.name + '” cannot be attached — pictures, PDFs, Excel workbooks and text files only.'; return; }
            if (f.size > MAX_BYTES) { bad = '“' + f.name + '” is too large — 6 MB at most.'; return; }
            picked.push(f);
        });
        drawChips(); say(bad, !!bad);
    }
    document.getElementById('ask-attach').addEventListener('click', function(){ input.click(); });
    input.addEventListener('change', function(){ addFiles(input.files); input.value = ''; });
    box.addEventListener('paste', function(e){ var fs = (e.clipboardData && e.clipboardData.files) || []; if (fs.length) { e.preventDefault(); addFiles(fs); } });
    ['dragenter', 'dragover'].forEach(function(ev){ document.addEventListener(ev, function(e){ if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types, 'Files') >= 0) { e.preventDefault(); document.body.classList.add('ask-drop'); } }); });
    ['dragleave', 'drop'].forEach(function(ev){ document.addEventListener(ev, function(e){ document.body.classList.remove('ask-drop'); if (ev === 'drop' && e.dataTransfer && e.dataTransfer.files.length) { e.preventDefault(); addFiles(e.dataTransfer.files); } }); });
    function sendAsk(text, review){
        var fd = new FormData();
        fd.append('message', text); fd.append('review', review ? '1' : ''); fd.append('_csrf_token', window.WB_CSRF || '');
        picked.forEach(function(f){ fd.append('files[]', f, f.name); });
        return fetch('/workbench/asksend', {method: 'POST', credentials: 'same-origin', headers: {'X-CSRF-TOKEN': window.WB_CSRF || '', 'X-Requested-With': 'XMLHttpRequest'}, body: fd})
            .then(function(r){ return r.json().catch(function(){ throw new Error('The Builder answered with something unreadable (HTTP ' + r.status + ').'); }); })
            .then(function(j){
                var fix = document.getElementById('ask-fix'), url = j && j.data && j.data.agents_url;
                fix.classList.toggle('d-none', !url); if (url) fix.href = url;
                if (!j || j.success === false) throw new Error((j && j.message) || 'That did not work.');
                return j;
            });
    }
    // The options: folded behind their button, which stays lit while one of them is on — a choice
    // that changes what Send does must not be invisible.
    var opts = document.getElementById('ask-opts'), optBox = document.getElementById('ask-options'), review = document.getElementById('ask-review');
    function lit(){ opts.classList.toggle('btn-primary', review.checked); opts.classList.toggle('btn-outline-secondary', !review.checked);
        opts.title = review.checked ? 'Options — on: show me the tasks before building' : 'Options for this request'; }
    opts.addEventListener('click', function(){ optBox.hidden = !optBox.hidden; opts.setAttribute('aria-expanded', String(!optBox.hidden)); });
    review.addEventListener('change', lit);
    function grow(){ box.style.height = 'auto'; box.style.height = Math.min(box.scrollHeight + 2, 192) + 'px'; }
    box.addEventListener('input', grow);
    box.addEventListener('keydown', function(e){ if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); document.getElementById('ask-form').requestSubmit(); } });
    document.getElementById('ask-form').addEventListener('submit', function(e){
        e.preventDefault();
        var text = box.value.trim(); if (!text) return;
        send.disabled = true; say(picked.length ? 'Sending your files…' : 'Sending…');
        sendAsk(text, document.getElementById('ask-review').checked)
            .then(function(j){ box.value = ''; picked = []; drawChips(); grow(); say(j.message || ''); return redraw(true); })
            .catch(function(e){ say(e.message, true); })
            .then(function(){ send.disabled = false; });
    });
    feed.addEventListener('click', async function(e){
        var b = e.target.closest('.ask-act'); if (!b) return;
        var act = b.getAttribute('data-act'), plan = b.getAttribute('data-plan');
        // a waiting request: its words open for editing, in place
        if (act === 'editq') { var ef = b.closest('.ask-reply').querySelector('.ask-edit'); if (ef) { ef.hidden = false; ef.elements.message.focus(); } return; }
        try {
            if (act === 'askas' && b.getAttribute('data-as') === 'build' && b.textContent.indexOf('wanted this built') >= 0
                && !await tkConfirm('Build this? It will be planned and built as a change to your app, like any request.', {okText: 'Build it'})) return;
            if (act === 'unqueue' && !await tkConfirm('Remove this waiting request? It will not be built. What you wrote stays in Prompts.', {okText: 'Remove it', danger: true})) return;
            if (act === 'decline' && !await tkConfirm('Discard what this task built? Its work is not put in your app. You can ask for it again.', {okText: 'Discard it', danger: true})) return;
            if (act === 'undo') {
                if (!await tkConfirm('Put your app back to how it was before this request? Its code and its data go back to that moment. How it is now is kept, so this can itself be undone from the Terminal page.', {okText: 'Undo it', danger: true})) return;
            }
            b.disabled = true; say('Working…');
            var j;
            if (act === 'go')        { await post('/workbench/planapprove', {plan_id: plan}); j = await post('/workbench/planbuild', {plan_id: plan}); }
            else if (act === 'build')    j = await post('/workbench/planbuild', {plan_id: plan});
            else if (act === 'retry')    j = await post('/workbench/taskretry', {task_id: b.getAttribute('data-task')});
            else if (act === 'stopplan') j = await post('/workbench/decomposestop', {});
            else if (act === 'start')    j = await post('/workbench/run', {id: b.getAttribute('data-task')});
            else if (act === 'fix')      j = await post('/workbench/askfix', {id: b.getAttribute('data-task')});
            else if (act === 'unqueue')  j = await post('/workbench/promptunqueue', {prompt_id: b.getAttribute('data-prompt')});
            else if (act === 'askas')    j = await post('/workbench/askas', {prompt_id: b.getAttribute('data-prompt'), as: b.getAttribute('data-as')});
            else if (act === 'asknote')  j = await post('/workbench/asknote', {prompt_id: b.getAttribute('data-prompt')});
            else if (act === 'approve')  j = await post('/workbench/approve', {id: b.getAttribute('data-task')});
            else if (act === 'decline')  j = await post('/workbench/decline', {id: b.getAttribute('data-task')});
            else if (act === 'undo')     j = await post('/aibuilder/rollback/' + encodeURIComponent(b.getAttribute('data-checkpoint')), {id: INST});
            say((j && j.message) || '');
            if (act === 'undo') await tkAlert((j && j.message) || 'Undone.');
            // what was pressed has started something: show it, at the bottom, where a new request shows
            await redraw(act === 'fix' || act === 'go' || act === 'build' || act === 'askas');
            if (act === 'asknote') await redraw();   // its link becomes "In the notebook", where it is
        } catch (err) { say(err.message, true); b.disabled = false; }
    });
    // The planner's question, answered: the request is planned again with the answer in it.
    feed.addEventListener('submit', function(e){
        var f = e.target.closest('.ask-plan-answer'); if (!f) return;
        e.preventDefault();
        var btn = f.querySelector('button'); btn.disabled = true; say('Sending your answer…');
        post('/workbench/askanswer', {prompt_id: f.getAttribute('data-prompt'), message: f.elements.message.value.trim()})
            .then(function(j){ f.elements.message.value = ''; say((j && j.message) || 'Got it.'); return redraw(true); })
            .catch(function(err){ say(err.message, true); btn.disabled = false; });
    });
    // A waiting request, edited in place: saved while it still waits (the page says so when it has started).
    feed.addEventListener('click', function(e){ var c = e.target.closest('.ask-edit [data-cancel]'); if (c) { c.closest('.ask-edit').hidden = true; redraw(true); } });
    feed.addEventListener('submit', function(e){
        var f = e.target.closest('.ask-edit'); if (!f) return;
        e.preventDefault();
        var btn = f.querySelector('button[type=submit]'); btn.disabled = true; say('Saving…');
        post('/workbench/askedit', {prompt_id: f.getAttribute('data-prompt'), message: f.elements.message.value.trim()})
            .then(function(j){ say((j && j.message) || 'Changed.'); f.elements.message.blur(); return redraw(true); })
            .catch(function(err){ say(err.message, true); btn.disabled = false; });
    });
    // "Say what to change": the words go on the task (they are part of its brief from then on) and it is built again.
    feed.addEventListener('submit', function(e){
        var f = e.target.closest('.ask-change'); if (!f) return;
        e.preventDefault();
        var text = f.elements.change.value.trim(), id = f.getAttribute('data-task'), plan = f.getAttribute('data-kind') === 'plan', btn = f.querySelector('button');
        // a task of a plan may simply be tried again; one built on its own is only sent back with words
        if (!text && !plan) return;
        btn.disabled = true; say(text ? 'Sending your answer…' : 'Trying it again…');
        (text ? post('/workbench/comment', {id: id, content: text}) : Promise.resolve())
            .then(function(){ return plan ? post('/workbench/taskretry', {task_id: id}) : post('/workbench/rerun', {id: id}); })
            .then(function(j){ var wasQ = !!f.closest('.ask-question'); f.elements.change.value = ''; say(wasQ ? 'Got it — the build goes on with your answer.' : ((j && j.message) || 'Building it again with what you said.')); return redraw(true); })
            .catch(function(err){ say(err.message, true); btn.disabled = false; });
    });

    // ── a picture, large: the pictures of ONE reply, one at a time ──
    var view = document.getElementById('ask-view'), vImg = view.querySelector('img'), vCap = view.querySelector('.cap'), set = [], at = 0, opener = null;
    function show(i){
        at = (i + set.length) % set.length;
        var s = set[at];
        // a thumbnail (data-full, data-label, data-ok) or a picture inside a written-out report (its own src)
        var label = s.getAttribute('data-label') || (s.tagName === 'IMG' && s.alt !== 'screenshot' ? s.alt : '') || '', ok = s.getAttribute('data-ok');
        vImg.src = s.getAttribute('data-full') || s.currentSrc || s.src; vImg.alt = label || 'A screenshot from the check';
        vCap.textContent = (set.length > 1 ? (at + 1) + ' of ' + set.length + (label ? ' — ' : '') : '') + (ok === '1' ? '✓ ' : (ok === '0' ? '✗ ' : '')) + label;
        Array.prototype.forEach.call(view.querySelectorAll('.nav'), function(b){ b.style.display = set.length > 1 ? '' : 'none'; });
    }
    function closeView(){ view.classList.remove('open'); vImg.removeAttribute('src'); if (opener && document.contains(opener)) opener.focus(); }
    feed.addEventListener('click', function(e){
        var b = e.target.closest('.ask-shot'), group = b ? b.parentNode.querySelectorAll('.ask-shot') : null;
        // pictures inside "Read the whole report" open the same way, as one set
        if (!b && e.target.tagName === 'IMG' && e.target.closest('.ask-report')) { b = e.target; group = b.closest('.ask-report').querySelectorAll('img'); e.preventDefault(); }
        if (!b) return;
        opener = b; set = Array.prototype.slice.call(group);
        view.classList.add('open'); show(set.indexOf(b)); view.focus();
    });
    view.querySelector('.prev').addEventListener('click', function(){ show(at - 1); });
    view.querySelector('.next').addEventListener('click', function(){ show(at + 1); });
    view.querySelector('.x').addEventListener('click', closeView);
    view.addEventListener('click', function(e){ if (e.target === view) closeView(); });
    document.addEventListener('keydown', function(e){
        if (!view.classList.contains('open')) return;
        if (e.key === 'Escape') closeView(); else if (e.key === 'ArrowLeft') show(at - 1); else if (e.key === 'ArrowRight') show(at + 1);
    });
    var touchX = null;
    view.addEventListener('touchstart', function(e){ touchX = e.touches[0].clientX; }, {passive: true});
    view.addEventListener('touchend', function(e){
        if (touchX === null) return;
        var dx = e.changedTouches[0].clientX - touchX; touchX = null;
        if (Math.abs(dx) > 45 && set.length > 1) show(at + (dx < 0 ? 1 : -1));
    });
    Array.prototype.forEach.call(document.querySelectorAll('.ask-view'), function(b){
        b.addEventListener('click', function(){
            post('/workbench/askview', {view: b.getAttribute('data-view')}).then(function(j){ window.location.href = j.data.url; }).catch(function(e){ say(e.message, true); });
        });
    });
    window.scrollTo(0, document.body.scrollHeight);
    setInterval(tick, 5000); tick();
    // While something typed is being read, look every second and a half: an answer is seconds away, and five more is long.
    setInterval(function(){ if (feed.querySelector('.ask-reading')) tick(); }, 1500);
})();
</script>
