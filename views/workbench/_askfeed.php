<?php
/**
 * The conversation of the Ask view: each request with what became of it, then what is being
 * planned now and what waits for the project to be free. Needs $feed (Workbench::conversation())
 * and $appUrl. Drawn in the page and again by /workbench/askfeed when something moved.
 */
$h = fn($s) => htmlspecialchars((string) $s);
$when = function (string $at): string {
    $t = strtotime($at); if (!$t) return '';
    return date('Y-m-d') === date('Y-m-d', $t) ? date('g:i a', $t) : date('M j', $t);
};
$marks = ['done' => ['check-circle-fill', 'text-success', 'Built'], 'now' => ['', '', 'Being built now'], 'trouble' => ['exclamation-triangle-fill', 'text-danger', 'Stopped'],
          'you' => ['chat-dots-fill', 'text-warning', 'Waiting for your answer'], 'next' => ['circle', 'text-body-tertiary', 'Not started yet']];
$steps = function (array $list, int $show = 7, bool $decomposed = true) use ($h, $marks): string {
    if (!$list) return '';
    // The first thing done for every request, said as the first step: it was decomposed into these tasks.
    $o = '<ul class="list-unstyled small mb-0 mt-2 ask-steps">'
       . ($decomposed ? '<li class="d-flex gap-2 align-items-baseline text-body-secondary"><i class="bi bi-check-circle-fill text-success flex-shrink-0" aria-hidden="true"></i>'
                      . '<span>Decomposed into ' . count($list) . ' task' . (count($list) === 1 ? '' : 's') . '</span></li>' : '');
    foreach (array_slice($list, 0, $show) as $s) {
        [$icon, $tone, $say] = $marks[$s['mark']];
        $o .= '<li class="d-flex gap-2 align-items-baseline" title="' . $h($say) . '">'
            . ($s['mark'] === 'now' ? '<span class="spinner-border spinner-border-sm text-primary flex-shrink-0" style="width:.75rem;height:.75rem" role="status"></span>'
                                    : '<i class="bi bi-' . $icon . ' ' . $tone . ' flex-shrink-0" aria-hidden="true"></i>')
            . '<span class="flex-grow-1" style="min-width:0"><a class="text-reset text-decoration-none' . ($s['mark'] === 'next' ? ' text-body-secondary' : '') . '" href="/workbench/view?id=' . (int) $s['id'] . '">' . $h($s['title']) . '</a>'
            . '<span class="visually-hidden"> — ' . $h($say) . '</span>'
            // what its agent is doing right now (filled and kept current by the page: /workbench/askdoing)
            . ($s['mark'] === 'now' ? '<span class="d-block text-body-secondary ask-live" data-live="' . (int) $s['id'] . '" style="font-size:.8rem">Working…</span>' : '')
            . '</span></li>';
    }
    if (count($list) > $show) $o .= '<li class="text-body-secondary ps-4">and ' . (count($list) - $show) . ' more</li>';
    return $o . '</ul>';
};
$btn = fn(string $cls, string $act, string $icon, string $label, array $data = []) =>
    '<button type="button" class="btn btn-sm ' . $cls . ' ask-act" data-act="' . $act . '"' . implode('', array_map(fn($k, $v) => ' data-' . $k . '="' . htmlspecialchars((string) $v) . '"', array_keys($data), $data)) . '>'
    . '<i class="bi bi-' . $icon . ' me-1"></i>' . htmlspecialchars($label) . '</button>';
$details = fn(int $id) => '<a class="btn btn-sm btn-link text-body-secondary text-decoration-none px-1" href="/workbench/view?id=' . $id . '">Details</a>';
// The audit's verdict and what it saw: a strip of its screenshots, each with the check it proves.
$proof = function (array $p) use ($h): string {
    if ($p['passed'] === null && !$p['shots']) return '';
    $o = '';
    if ($p['passed'] !== null) {
        $o .= '<div class="small mt-2"><i class="bi bi-' . ($p['passed'] ? 'patch-check-fill text-success' : 'patch-exclamation-fill text-danger') . ' me-1"></i>'
            . '<span class="fw-semibold">' . ($p['passed'] ? 'Checked afterwards in a browser: it works.' : 'Checked afterwards in a browser: something is not right.') . '</span>'
            . ($p['verdict'] !== '' ? ' <span class="text-body-secondary">' . $h($p['verdict']) . '</span>' : '') . '</div>';
    }
    if ($p['shots']) {
        $n = count($p['shots']);
        $o .= '<div class="small text-body-secondary mt-2">' . $n . ' picture' . ($n === 1 ? '' : 's') . ' from the check — click one to see it large' . ($n > 1 ? ', then swipe or use the arrows' : '') . '.</div>'
            . '<div class="ask-shots" style="display:flex;gap:.5rem;overflow-x:auto;padding:.25rem 0 .35rem;scroll-snap-type:x proximity">';
        foreach ($p['shots'] as $sh) {
            $alt = $sh['label'] !== '' ? $sh['label'] : 'A screenshot from the check';
            $o .= '<button type="button" class="ask-shot" data-full="' . $h($sh['url']) . '" data-label="' . $h($sh['label']) . '" data-ok="' . ($sh['ok'] ? 1 : 0) . '" title="' . $h($alt) . '"'
                . ' style="flex:0 0 auto;width:7.5rem;padding:0;border:0;background:none;text-align:left;scroll-snap-align:start;cursor:zoom-in">'
                . '<img src="' . $h($sh['url']) . '" alt="' . $h($alt) . '" loading="lazy" width="120" height="80"'
                . ' style="display:block;width:7.5rem;height:5rem;object-fit:cover;object-position:top;border-radius:.4rem;border:1px solid var(--bs-border-color);background:var(--bs-tertiary-bg)">'
                . '<span class="d-block text-body-secondary mt-1 text-truncate" style="font-size:.72rem"><i class="bi bi-' . ($sh['ok'] ? 'check-lg text-success' : 'x-lg text-danger') . ' me-1"></i>' . $h($sh['label']) . '</span></button>';
        }
        $o .= '</div>';
    }
    return $o;
};
// Answering a task from here: the words go on the task and it is tried again with them (data-kind says how).
$answer = fn(int $taskId, string $kind, string $placeholder, string $button) =>
    '<form class="ask-change d-flex gap-2 mt-2" data-task="' . $taskId . '" data-kind="' . $kind . '"><input class="form-control form-control-sm" name="change" maxlength="2000" placeholder="' . htmlspecialchars($placeholder) . '" aria-label="' . htmlspecialchars($placeholder) . '">'
    . '<button class="btn btn-sm btn-outline-primary text-nowrap" type="submit">' . htmlspecialchars($button) . '</button></form>';
$you = function (string $text, string $meta) use ($h): string {
    // what was typed is kept with its special characters written as entities (&raquo;): shown as typed
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $long = mb_strlen($text) > 420;
    return '<div class="d-flex justify-content-end mb-2"><div class="ask-you rounded-4 px-3 py-2">'
         . ($long ? '<details class="ask-more"><summary style="list-style:none;cursor:pointer"><span class="ask-text">' . nl2br($h(mb_substr($text, 0, 400))) . '… </span><u class="small">Show all</u></summary>'
                    . '<div class="ask-text mt-2 pt-2" style="border-top:1px solid rgba(255,255,255,.35)">' . nl2br($h($text)) . '</div></details>'
                  : '<div class="ask-text">' . nl2br($h($text)) . '</div>')
         . ($meta !== '' ? '<div class="small opacity-75 mt-1 text-end">' . $h($meta) . '</div>' : '') . '</div></div>';
};
$reply = fn(string $body) => '<div class="d-flex mb-4"><div class="ask-reply rounded-4 px-3 py-2 border bg-body">' . $body . '</div></div>';
?>
<?php if (!empty($feed['more'])): ?>
<div class="text-center small mb-3"><a class="text-body-secondary" href="/workbench?status=all"><?= (int) $feed['more'] ?> earlier request<?= $feed['more'] === 1 ? '' : 's' ?> — on the Task Board</a></div>
<?php endif; ?>
<style>
    /* a report written out in full: its pictures and code stay inside the bubble */
    .ask-report img{max-width:100% !important;max-height:12rem !important;width:auto !important;height:auto !important;border-radius:.4rem;border:1px solid var(--bs-border-color)}
    .ask-report img{cursor:zoom-in}
    .ask-report pre{white-space:pre-wrap;overflow-wrap:anywhere;font-size:.8rem;background:var(--bs-tertiary-bg);padding:.5rem;border-radius:.4rem}
    .ask-report h1,.ask-report h2,.ask-report h3{font-size:.95rem;margin:.75rem 0 .25rem}
    .ask-report{overflow-wrap:anywhere}
    details.ask-more[open] > summary > .ask-text, details.ask-more[open] > summary > u{display:none}
</style>
<?php foreach ($feed['entries'] as $e):
    $id = (int) $e['id'];
    if (!empty($e['found'])):
        // Tiknix speaking first: it found this itself. One bubble — what is wrong, the pictures, the whole report folded, and what can be done.
        $fd = $e['found']; $st = substr($e['state'], 5);
        $b = '<div class="fw-semibold"><i class="bi bi-bug-fill text-danger me-1"></i>Tiknix found a problem' . ($when($e['at']) !== '' ? ' <span class="fw-normal small text-body-secondary">· ' . $h($when($e['at'])) . '</span>' : '') . '</div>'
           . '<div class="mt-1">' . $h($fd['message'] !== '' ? $fd['message'] : $e['title']) . '</div>'
           . ($fd['full'] !== '' && $fd['full'] !== $fd['message'] ? '<div class="small text-body-secondary mt-1">' . nl2br($h($fd['full'])) . '</div>' : '')
           . ($fd['where'] !== '' ? '<div class="small text-body-secondary mt-1">Seen on <code>' . $h($fd['where']) . '</code></div>' : '')
           . $proof(['passed' => null, 'verdict' => '', 'shots' => $fd['shots']])
           . '<details class="small mt-2"><summary class="text-body-secondary" style="cursor:pointer">Read the whole report</summary><div class="ask-report mt-2">' . $fd['html'] . '</div></details>';
        if ($e['review'] !== null) {
            $rv = $e['review'];
            $b .= '<hr class="my-2"><div class="fw-semibold"><i class="bi bi-hourglass-split text-warning me-1"></i>A fix is built — waiting for you.</div>'
                . ($rv['said'] !== '' ? '<div class="ask-said ask-report small border-start border-3 ps-2 mt-2">' . \app\MarkdownParser::parseSafe($rv['said']) . '</div>' : '')
                . ($e['can_approve'] ? '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">' . $btn('btn-success', 'approve', 'check-lg', 'Put it in my app', ['task' => $id]) . $btn('btn-outline-secondary', 'decline', 'x-lg', 'Discard it', ['task' => $id]) . $details($id) . '</div>' : '')
                . $answer($id, 'solo', 'Or say what to change, and it builds it again…', 'Build again');
        } else {
            if ($st === 'running') {
                $b .= '<hr class="my-2"><div class="fw-semibold d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-primary" role="status"></span>Building a fix…</div>'
                    . '<div class="small text-body-secondary ask-live mt-1" data-live="' . $id . '">Working…</div>';
            }
            $say = ['pending' => '', 'running' => '', 'merged' => 'Fixed — it is in your app.', 'resolved' => 'Looked at: nothing needed changing.', 'failed' => 'The fix stopped: ' . mb_strimwidth($e['error'], 0, 240, '…'), 'declined' => 'Left as it is.'][$st] ?? ucfirst($st) . '.';
            $b .= ($say !== '' ? '<div class="small fw-semibold mt-2">' . $h($say) . '</div>' : '')
                . '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">'
                . (in_array($st, ['pending', 'failed'], true) ? $btn('btn-primary', 'fix', 'wrench-adjustable', $st === 'failed' ? 'Try the fix again' : 'Fix it', ['task' => $id]) : '')
                . $details($id) . '</div>';
        }
        echo $reply($b);
        continue;
    endif;
    echo $you($e['replan_of'] ? "(Tiknix re-planned what was left of #{$e['replan_of']})" : $e['asked'], trim(($e['files'] ? $e['files'] . ($e['files'] === 1 ? ' file attached' : ' files attached') . ' · ' : '') . $when($e['at']), ' ·'));
    $count = $e['total'] ? $e['built'] . ' of ' . $e['total'] . ' tasks built' : '';
    $b = '';
    switch ($e['state']) {
        case 'draft':
            $b = '<div class="fw-semibold">Here is how I would do it' . ($e['total'] ? ' — ' . $e['total'] . ' task' . ($e['total'] === 1 ? '' : 's') : '') . '.</div>' . $steps($e['steps'], 7, empty($e['direct']))
               . '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">' . $btn('btn-primary', 'go', 'play-fill', 'Build it', ['plan' => $id]) . $details($id) . '</div>';
            break;
        case 'approved':
            $b = '<div class="fw-semibold">Ready when you are.</div>' . $steps($e['steps'], 7, empty($e['direct']))
               . '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">' . $btn('btn-primary', 'build', 'play-fill', 'Build it', ['plan' => $id]) . $details($id) . '</div>';
            break;
        case 'queued':
            $b = '<div class="fw-semibold"><i class="bi bi-hourglass-split me-1 text-info"></i>Waiting its turn.</div><div class="small text-body-secondary">' . $h(preg_replace('/^Queued: /', 'It ', $e['message'])) . ' It starts by itself.</div>' . $steps($e['steps'], 4, empty($e['direct']))
               . '<div class="mt-2">' . $details($id) . '</div>';
            break;
        case 'building':
            $pct = $e['total'] ? (int) round($e['built'] * 100 / $e['total']) : 0;
            $b = '<div class="fw-semibold d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-primary" role="status"></span>Building — ' . $h($count) . '</div>'
               . '<div class="progress mt-2" style="height:4px" role="progressbar" aria-label="Built" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:' . $pct . '%"></div></div>'
               . ($e['needs'] ? '<div class="alert alert-warning py-2 px-3 small mt-2 mb-0"><i class="bi bi-chat-dots-fill me-1"></i>It needs an answer from you on “' . $h($e['needs']['title']) . '”' . (($e['needs']['about'] ?? '') !== '' ? ': ' . $h($e['needs']['about']) : '.')
                    . $answer((int) $e['needs']['id'], 'plan', 'Your answer…', 'Answer') . '</div>' : '')
               . ($e['trouble'] ? '<div class="alert alert-danger py-2 px-3 small mt-2 mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i>“' . $h($e['trouble']['title']) . '” stopped: ' . $h(mb_strimwidth($e['trouble']['why'], 0, 300, '…'))
                    . $answer((int) $e['trouble']['id'], 'plan', 'Tell it what to do about that, or leave empty to just try again…', 'Try again') . '</div>' : '')
               . $steps($e['steps'], 7, empty($e['direct'])) . '<div class="mt-2">' . $details($id) . '</div>';
            break;
        case 'done':
            $b = '<div class="fw-semibold"><i class="bi bi-check-circle-fill text-success me-1"></i>Done — ' . ($e['total'] === 1 ? 'it is in your app.' : $h((string) $e['total']) . ' tasks, all in your app.') . '</div>' . $steps($e['steps'], 7, empty($e['direct']))
               . $proof($e['proof'])
               . '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">'
               . ($appUrl !== '' ? '<a class="btn btn-sm btn-primary" href="' . $h($appUrl) . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Look at it</a>' : '')
               . ($e['undo'] !== '' ? $btn('btn-outline-secondary', 'undo', 'arrow-counterclockwise', 'Undo', ['checkpoint' => $e['undo']]) : '')
               . $details($id) . '</div>';
            break;
        case 'attention': case 'stalled':
            $why = $e['trouble'] ? '“' . $e['trouble']['title'] . '”: ' . mb_strimwidth($e['trouble']['why'], 0, 260, '…') : ($e['message'] !== '' ? $e['message'] : $e['error']);
            $b = '<div class="fw-semibold"><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>It stopped' . ($count !== '' ? ' — ' . $h($count) : '') . '.</div>'
               . ($why !== '' ? '<div class="small text-body-secondary mt-1">' . $h($why) . '</div>' : '') . $steps($e['steps'], 7, empty($e['direct'])) . $proof($e['proof'])
               . '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">'
               . ($e['trouble'] ? '' : $btn('btn-primary', 'build', 'play-fill', 'Go on', ['plan' => $id]))
               . $details($id) . '</div>'
               . ($e['trouble'] ? $answer((int) $e['trouble']['id'], 'plan', 'Tell it what to do about that, or leave empty to just try again…', 'Try again') : '');
            break;
        default:   // a task made on its own
            $st = substr($e['state'], 5);
            $say = ['pending' => 'Not started yet.', 'running' => 'Being built now.', 'awaiting' => 'It needs an answer from you.', 'completed' => 'Built — waiting for you to approve it.',
                    'merged' => 'Done — it is in your app.', 'failed' => 'It stopped.', 'conflict' => 'It stopped at the merge.'][$st] ?? ucfirst($st) . '.';
            if ($e['review'] !== null) {
                // Built, and waiting for its person: what it did and what it changed, and the three answers.
                $rv = $e['review'];
                $b = '<div class="fw-semibold"><i class="bi bi-hourglass-split text-warning me-1"></i>Built — waiting for you.</div>'
                   . '<div class="small text-body-secondary">It is not in your app yet. Read what it did, then put it in, send it back, or ask for a change.</div>'
                   . ($rv['said'] !== '' ? '<div class="ask-said ask-report small border-start border-3 ps-2 mt-2">' . \app\MarkdownParser::parseSafe($rv['said']) . '</div>' : '')
                   . ($rv['changed'] !== '' ? '<details class="small mt-2"><summary class="text-body-secondary" style="cursor:pointer">Files it changed</summary><pre class="small mb-0 mt-1 p-2 rounded bg-body-tertiary" style="white-space:pre-wrap">' . $h($rv['changed']) . '</pre></details>' : '')
                   . ($e['can_approve']
                        ? '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">' . $btn('btn-success', 'approve', 'check-lg', 'Put it in my app', ['task' => $id]) . $btn('btn-outline-secondary', 'decline', 'x-lg', 'Discard it', ['task' => $id]) . $details($id) . '</div>'
                        : '<div class="small text-body-secondary mt-2">An admin of this project approves it. ' . $details($id) . '</div>')
                   . $answer($id, 'solo', 'Or say what to change, and it builds it again…', 'Build again');
                break;
            }
            $b = '<div class="fw-semibold' . ($st === 'running' ? ' d-flex align-items-center gap-2' : '') . '">' . ($st === 'running' ? '<span class="spinner-border spinner-border-sm text-primary" role="status"></span>' : '') . $h($say) . '</div>'
               . ($st === 'running' ? '<div class="small text-body-secondary ask-live mt-1" data-live="' . $id . '">Working…</div>' : '') . ($e['error'] !== '' && in_array($st, ['failed', 'conflict'], true) ? '<div class="small text-body-secondary mt-1">' . $h(mb_strimwidth($e['error'], 0, 260, '…')) . '</div>' : '')
               . '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">' . ($st === 'pending' ? $btn('btn-primary', 'start', 'play-fill', 'Build it', ['task' => $id]) : '') . '<a class="btn btn-sm btn-outline-primary" href="/workbench/view?id=' . $id . '">Open it</a></div>';
    }
    echo $reply($b);
endforeach; ?>
<?php foreach ($feed['waiting'] as $w): ?>
    <?= $you(trim(\app\GoalBrief::split((string) $w['body'])['goal']) ?: (string) $w['title'], '') ?>
    <?= $reply('<div class="fw-semibold"><i class="bi bi-hourglass-split me-1 text-info"></i>Waiting for the project to be free.</div><div class="small text-body-secondary">It starts by itself.</div>') ?>
<?php endforeach; ?>
<?php if ($feed['planning']): ?>
    <?= $you($feed['planning']['title'] . ($feed['planning']['excerpt'] !== '' ? "\n" . $feed['planning']['excerpt'] : ''), '') ?>
    <?= $reply('<div class="fw-semibold d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-primary" role="status"></span>Decomposing into appropriate tasks…</div>'
        . '<div class="small text-body-secondary mt-1" id="ask-doing">Reading your app first, then writing the tasks. This takes a few minutes; building starts by itself after it.</div>'
        . '<div class="mt-2">' . $btn('btn-outline-secondary', 'stopplan', 'stop-fill', 'Stop') . '</div>') ?>
<?php endif; ?>
<?php if (!$feed['entries'] && !$feed['planning'] && !$feed['waiting']): ?>
<div class="text-center text-body-secondary py-5" id="ask-empty">
    <i class="bi bi-chat-square-text fs-1 d-block mb-2"></i>
    <div class="fw-semibold text-body">What should your app do next?</div>
    <div class="small">Say it in your own words. Tiknix works out the steps, builds them, and shows you the result here.</div>
</div>
<?php endif; ?>
<span id="ask-state" data-planning="<?= $feed['planning'] ? 1 : 0 ?>" hidden></span>
