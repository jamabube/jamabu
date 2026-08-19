<?php
/**
 * The error page rendered by the error handler.
 *
 * Deliberately self-contained — its own document, its own inline styling, no
 * layout, no helpers beyond escaping. It has to render when the session, the
 * database or the asset pipeline is the thing that failed, so it cannot depend
 * on any of them.
 *
 * @var int             $status
 * @var string          $title
 * @var string          $message
 * @var string          $reference
 * @var \Throwable|null $exception  Set only when debug mode is on.
 * @var string          $requestId
 */
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $escape($status) ?> · <?= $escape($title) ?></title>
<style>
    :root { color-scheme: light dark; --ink:#1f2933; --muted:#6b7785; --bg:#f4f6f8; --panel:#fff; --line:#e2e8f0; --accent:#1d6f42; }
    @media (prefers-color-scheme: dark) {
        :root { --ink:#e6eaf0; --muted:#9aa5b1; --bg:#12171d; --panel:#1a2027; --line:#2b333c; --accent:#4ba26b; }
    }
    * { box-sizing: border-box; }
    body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:2rem;
           font-family: "Segoe UI", system-ui, -apple-system, sans-serif; background:var(--bg); color:var(--ink); }
    .panel { width:100%; max-width:44rem; background:var(--panel); border:1px solid var(--line);
             border-radius:.75rem; padding:2.5rem; box-shadow:0 1px 3px rgba(0,0,0,.08); }
    .code { font-size:3.5rem; font-weight:700; line-height:1; color:var(--accent); margin:0; }
    h1 { font-size:1.4rem; margin:.75rem 0 .5rem; }
    p { margin:.5rem 0; line-height:1.6; }
    .muted { color:var(--muted); font-size:.9rem; }
    code { background:var(--bg); border:1px solid var(--line); border-radius:.25rem; padding:.1rem .35rem; font-size:.85em; }
    .actions { margin-top:1.75rem; display:flex; gap:.75rem; flex-wrap:wrap; }
    a.button { display:inline-block; background:var(--accent); color:#fff; text-decoration:none;
               padding:.6rem 1.1rem; border-radius:.4rem; font-weight:600; }
    a.button.secondary { background:transparent; color:var(--ink); border:1px solid var(--line); }
    details { margin-top:1.75rem; border-top:1px solid var(--line); padding-top:1.25rem; }
    summary { cursor:pointer; font-weight:600; }
    pre { overflow:auto; background:var(--bg); border:1px solid var(--line); border-radius:.4rem;
          padding:1rem; font-size:.8rem; line-height:1.5; max-height:24rem; }
</style>
</head>
<body>
<div class="panel">
    <p class="code"><?= $escape($status) ?></p>
    <h1><?= $escape($title) ?></h1>
    <p><?= $escape($message) ?></p>

    <?php if ($reference !== ''): ?>
        <p class="muted">Reference <code><?= $escape($reference) ?></code> — quote this when reporting the problem.</p>
    <?php endif; ?>
    <?php if ($requestId !== ''): ?>
        <p class="muted">Request <code><?= $escape($requestId) ?></code></p>
    <?php endif; ?>

    <div class="actions">
        <a class="button" href="/">Back to the dashboard</a>
        <a class="button secondary" href="/login">Sign in</a>
    </div>

    <?php if ($exception !== null): ?>
        <?php /* Only ever reached with APP_DEBUG on, which must never be true in production. */ ?>
        <details open>
            <summary><?= $escape($exception::class) ?></summary>
            <p class="muted"><?= $escape($exception->getMessage()) ?></p>
            <p class="muted"><?= $escape($exception->getFile()) ?>:<?= $escape($exception->getLine()) ?></p>
            <pre><?= $escape($exception->getTraceAsString()) ?></pre>
        </details>
    <?php endif; ?>
</div>
</body>
</html>
