<?php
/**
 * Ask — the simple view of the Builder: the conversation (views/workbench/_askfeed.php) and one
 * box. A second view of the board's own plans and tasks; see Workbench::ask().
 */
$__inst = (int) ($selected['id'] ?? 0);
?>
<style>
    .ask-wrap{max-width:46rem;margin:0 auto;padding:1rem 1rem 11.5rem}
    .ask-you{max-width:85%;background:var(--bs-primary);color:#fff;overflow-wrap:anywhere}
    .ask-reply{max-width:92%;min-width:min(22rem,92%);overflow-wrap:anywhere}
    .ask-steps li{padding:.1rem 0}
    .ask-bar{position:fixed;left:0;right:0;bottom:0;background:var(--bs-body-bg);border-top:1px solid var(--bs-border-color);padding:.75rem 1rem;z-index:20}
    .ask-bar-in{max-width:46rem;margin:0 auto}
    #ask-box{resize:none;max-height:12rem;overflow-y:auto}
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
<div class="ask-bar">
    <form class="ask-bar-in" id="ask-form" autocomplete="off">
        <div id="ask-chips" class="d-flex flex-wrap gap-1 mb-1"></div>
        <div class="d-flex gap-2 align-items-end">
            <label class="visually-hidden" for="ask-box">What should your app do next?</label>
            <button class="btn btn-outline-secondary flex-shrink-0" type="button" id="ask-attach" title="Attach pictures, PDFs, spreadsheets or text files (or drop or paste them)" aria-label="Attach files"><i class="bi bi-paperclip"></i></button>
            <input type="file" id="ask-files" class="d-none" multiple accept="image/png,image/jpeg,image/webp,image/gif,application/pdf,text/plain,text/csv,.md,.xlsx">
            <textarea class="form-control" id="ask-box" rows="1" maxlength="20000" placeholder="What should your app do next?" required></textarea>
            <button class="btn btn-primary flex-shrink-0" type="submit" id="ask-send" aria-label="Send"><i class="bi bi-arrow-up"></i></button>
        </div>
        <div class="d-flex align-items-center gap-3 mt-1 small text-body-secondary">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="ask-review">
                <label class="form-check-label" for="ask-review" title="Every request is decomposed into tasks first. Otherwise building starts as soon as that is done. Every build starts from a restore point, and Undo brings it back.">Show me the tasks before building</label>
            </div>
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
        data = data || {}; data._csrf_token = window.WB_CSRF || '';
        return fetch(url, {method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': window.WB_CSRF || '', 'X-Requested-With': 'XMLHttpRequest'},
            body: Object.keys(data).map(function(k){ return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&')
        }).then(function(r){ return r.json().catch(function(){ throw new Error('The Builder answered with something unreadable (HTTP ' + r.status + ').'); }); })
          .then(function(j){ if (!j || j.success === false) throw new Error((j && j.message) || 'That did not work.'); return j; });
    }
    function atBottom(){ return window.innerHeight + window.scrollY >= document.body.scrollHeight - 160; }
    function redraw(stick){
        var was = atBottom();
        return fetch('/workbench/askfeed', {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function(html){ feed.innerHTML = html; if (stick || was) window.scrollTo(0, document.body.scrollHeight); });
    }
    function planning(){ var s = document.getElementById('ask-state'); return !!s && s.getAttribute('data-planning') === '1'; }
    function tick(){
        if (busy || document.hidden) return;
        busy = true;
        fetch('/workbench/boardsig', {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
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
        try {
            if (act === 'undo') {
                if (!await tkConfirm('Put your app back to how it was before this request? Its code and its data go back to that moment. How it is now is kept, so this can itself be undone from the Terminal page.', {okText: 'Undo it', danger: true})) return;
            }
            b.disabled = true; say('Working…');
            var j;
            if (act === 'go')        { await post('/workbench/planapprove', {plan_id: plan}); j = await post('/workbench/planbuild', {plan_id: plan}); }
            else if (act === 'build')    j = await post('/workbench/planbuild', {plan_id: plan});
            else if (act === 'retry')    j = await post('/workbench/taskretry', {task_id: b.getAttribute('data-task')});
            else if (act === 'stopplan') j = await post('/workbench/decomposestop', {});
            else if (act === 'undo')     j = await post('/aibuilder/rollback/' + encodeURIComponent(b.getAttribute('data-checkpoint')), {id: INST});
            say((j && j.message) || '');
            if (act === 'undo') await tkAlert((j && j.message) || 'Undone.');
            await redraw();
        } catch (err) { say(err.message, true); b.disabled = false; }
    });
    Array.prototype.forEach.call(document.querySelectorAll('.ask-view'), function(b){
        b.addEventListener('click', function(){
            post('/workbench/askview', {view: b.getAttribute('data-view')}).then(function(j){ window.location.href = j.data.url; }).catch(function(e){ say(e.message, true); });
        });
    });
    window.scrollTo(0, document.body.scrollHeight);
    setInterval(tick, 5000); tick();
})();
</script>
