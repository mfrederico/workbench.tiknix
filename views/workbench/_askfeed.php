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
$steps = function (array $list, int $show = 7) use ($h, $marks): string {
    if (!$list) return '';
    $o = '<ul class="list-unstyled small mb-0 mt-2 ask-steps">';
    foreach (array_slice($list, 0, $show) as $s) {
        [$icon, $tone, $say] = $marks[$s['mark']];
        $o .= '<li class="d-flex gap-2 align-items-baseline" title="' . $h($say) . '">'
            . ($s['mark'] === 'now' ? '<span class="spinner-border spinner-border-sm text-primary flex-shrink-0" style="width:.75rem;height:.75rem" role="status"></span>'
                                    : '<i class="bi bi-' . $icon . ' ' . $tone . ' flex-shrink-0" aria-hidden="true"></i>')
            . '<a class="text-reset text-decoration-none' . ($s['mark'] === 'next' ? ' text-body-secondary' : '') . '" href="/workbench/view?id=' . (int) $s['id'] . '">' . $h($s['title']) . '</a>'
            . '<span class="visually-hidden"> — ' . $h($say) . '</span></li>';
    }
    if (count($list) > $show) $o .= '<li class="text-body-secondary ps-4">and ' . (count($list) - $show) . ' more</li>';
    return $o . '</ul>';
};
$btn = fn(string $cls, string $act, string $icon, string $label, array $data = []) =>
    '<button type="button" class="btn btn-sm ' . $cls . ' ask-act" data-act="' . $act . '"' . implode('', array_map(fn($k, $v) => ' data-' . $k . '="' . htmlspecialchars((string) $v) . '"', array_keys($data), $data)) . '>'
    . '<i class="bi bi-' . $icon . ' me-1"></i>' . htmlspecialchars($label) . '</button>';
$details = fn(int $id) => '<a class="btn btn-sm btn-link text-body-secondary text-decoration-none px-1" href="/workbench/view?id=' . $id . '">Details</a>';
$you = function (string $text, string $meta) use ($h): string {
    $long = mb_strlen($text) > 420;
    return '<div class="d-flex justify-content-end mb-2"><div class="ask-you rounded-4 px-3 py-2">'
         . '<div class="ask-text">' . nl2br($h($long ? mb_substr($text, 0, 400) . '…' : $text)) . '</div>'
         . ($meta !== '' ? '<div class="small opacity-75 mt-1 text-end">' . $h($meta) . '</div>' : '') . '</div></div>';
};
$reply = fn(string $body) => '<div class="d-flex mb-4"><div class="ask-reply rounded-4 px-3 py-2 border bg-body">' . $body . '</div></div>';
?>
<?php if (!empty($feed['more'])): ?>
<div class="text-center small mb-3"><a class="text-body-secondary" href="/workbench?status=all"><?= (int) $feed['more'] ?> earlier request<?= $feed['more'] === 1 ? '' : 's' ?> — on the Task Board</a></div>
<?php endif; ?>
<?php foreach ($feed['entries'] as $e):
    $id = (int) $e['id'];
    echo $you($e['replan_of'] ? "(Tiknix re-planned what was left of #{$e['replan_of']})" : $e['asked'], $when($e['at']));
    $count = $e['total'] ? $e['built'] . ' of ' . $e['total'] . ' steps built' : '';
    $b = '';
    switch ($e['state']) {
        case 'draft':
            $b = '<div class="fw-semibold">Here is how I would do it' . ($e['total'] ? ' — ' . $e['total'] . ' step' . ($e['total'] === 1 ? '' : 's') : '') . '.</div>' . $steps($e['steps'])
               . '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">' . $btn('btn-primary', 'go', 'play-fill', 'Build it', ['plan' => $id]) . $details($id) . '</div>';
            break;
        case 'approved':
            $b = '<div class="fw-semibold">Ready when you are.</div>' . $steps($e['steps'])
               . '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">' . $btn('btn-primary', 'build', 'play-fill', 'Build it', ['plan' => $id]) . $details($id) . '</div>';
            break;
        case 'queued':
            $b = '<div class="fw-semibold"><i class="bi bi-hourglass-split me-1 text-info"></i>Waiting its turn.</div><div class="small text-body-secondary">' . $h(preg_replace('/^Queued: /', 'It ', $e['message'])) . ' It starts by itself.</div>' . $steps($e['steps'], 4)
               . '<div class="mt-2">' . $details($id) . '</div>';
            break;
        case 'building':
            $pct = $e['total'] ? (int) round($e['built'] * 100 / $e['total']) : 0;
            $b = '<div class="fw-semibold d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-primary" role="status"></span>Building — ' . $h($count) . '</div>'
               . '<div class="progress mt-2" style="height:4px" role="progressbar" aria-label="Built" aria-valuenow="' . $pct . '" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:' . $pct . '%"></div></div>'
               . ($e['needs'] ? '<div class="alert alert-warning py-2 px-3 small mt-2 mb-0"><i class="bi bi-chat-dots-fill me-1"></i>It needs an answer from you on “' . $h($e['needs']['title']) . '”. <a class="alert-link" href="/workbench/view?id=' . (int) $e['needs']['id'] . '">Answer it</a></div>' : '')
               . $steps($e['steps']) . '<div class="mt-2">' . $details($id) . '</div>';
            break;
        case 'done':
            $b = '<div class="fw-semibold"><i class="bi bi-check-circle-fill text-success me-1"></i>Done — ' . ($e['total'] === 1 ? 'it is in your app.' : $h((string) $e['total']) . ' steps, all in your app.') . '</div>' . $steps($e['steps'])
               . ($e['audit'] !== '' && !in_array($e['audit'], ['passed', 'clean', 'done'], true) ? '<div class="small text-body-secondary mt-2">Checked afterwards: ' . $h($e['audit']) . '.</div>' : '')
               . '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">'
               . ($appUrl !== '' ? '<a class="btn btn-sm btn-primary" href="' . $h($appUrl) . '" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Look at it</a>' : '')
               . ($e['undo'] !== '' ? $btn('btn-outline-secondary', 'undo', 'arrow-counterclockwise', 'Undo', ['checkpoint' => $e['undo']]) : '')
               . $details($id) . '</div>';
            break;
        case 'attention': case 'stalled':
            $why = $e['trouble'] ? '“' . $e['trouble']['title'] . '”: ' . mb_strimwidth($e['trouble']['why'], 0, 260, '…') : ($e['message'] !== '' ? $e['message'] : $e['error']);
            $b = '<div class="fw-semibold"><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>It stopped' . ($count !== '' ? ' — ' . $h($count) : '') . '.</div>'
               . ($why !== '' ? '<div class="small text-body-secondary mt-1">' . $h($why) . '</div>' : '') . $steps($e['steps'])
               . '<div class="mt-2 d-flex flex-wrap gap-1 align-items-center">'
               . ($e['trouble'] ? $btn('btn-primary', 'retry', 'arrow-repeat', 'Try again', ['task' => (int) $e['trouble']['id']]) : $btn('btn-primary', 'build', 'play-fill', 'Go on', ['plan' => $id]))
               . $details($id) . '</div>';
            break;
        default:   // a task made on its own
            $st = substr($e['state'], 5);
            $say = ['pending' => 'Not started yet.', 'running' => 'Being built now.', 'awaiting' => 'It needs an answer from you.', 'completed' => 'Built — waiting for you to approve it.',
                    'merged' => 'Done — it is in your app.', 'failed' => 'It stopped.', 'conflict' => 'It stopped at the merge.'][$st] ?? ucfirst($st) . '.';
            $b = '<div class="fw-semibold">' . $h($say) . '</div>' . ($e['error'] !== '' && in_array($st, ['failed', 'conflict'], true) ? '<div class="small text-body-secondary mt-1">' . $h(mb_strimwidth($e['error'], 0, 260, '…')) . '</div>' : '')
               . '<div class="mt-2"><a class="btn btn-sm btn-outline-primary" href="/workbench/view?id=' . $id . '">Open it</a></div>';
    }
    echo $reply($b);
endforeach; ?>
<?php foreach ($feed['waiting'] as $w): ?>
    <?= $you(trim(\app\GoalBrief::split((string) $w['body'])['goal']) ?: (string) $w['title'], '') ?>
    <?= $reply('<div class="fw-semibold"><i class="bi bi-hourglass-split me-1 text-info"></i>Waiting for the project to be free.</div><div class="small text-body-secondary">It starts by itself.</div>') ?>
<?php endforeach; ?>
<?php if ($feed['planning']): ?>
    <?= $you($feed['planning']['title'] . ($feed['planning']['excerpt'] !== '' ? "\n" . $feed['planning']['excerpt'] : ''), '') ?>
    <?= $reply('<div class="fw-semibold d-flex align-items-center gap-2"><span class="spinner-border spinner-border-sm text-primary" role="status"></span>Working out the steps…</div>'
        . '<div class="small text-body-secondary mt-1" id="ask-doing">Reading your app first. This takes a few minutes.</div>'
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
