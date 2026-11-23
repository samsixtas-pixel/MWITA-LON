<?php
declare(strict_types=1);

$code = (int) ($errorCode ?? 500);
$title = (string) ($errorTitle ?? 'Something went wrong');
$message = (string) ($errorMessage ?? 'We couldn’t complete your request. Please try again.');
$homeUrl = '/loan/';
$loginUrl = '/loan/login.php';
$referrer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$referrerParts = $referrer !== '' ? parse_url($referrer) : false;
$requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$referrerHost = is_array($referrerParts) ? strtolower((string) ($referrerParts['host'] ?? '')) : '';
$safeBackUrl = is_array($referrerParts)
    && in_array((string) ($referrerParts['scheme'] ?? ''), ['http', 'https'], true)
    && $referrerHost !== ''
    && hash_equals($requestHost, $referrerHost)
    ? $referrer
    : $homeUrl;
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#0249e1">
  <title><?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
  <style>
    :root{color-scheme:light;--blue:#0249e1;--blue-dark:#0137a6;--ink:#111827;--muted:#667085;--lime:#dafb71;--line:#e5eaf2}
    *{box-sizing:border-box}html{min-height:100%;-webkit-text-size-adjust:100%}body{margin:0;min-height:100vh;min-height:100dvh;display:grid;place-items:center;padding:clamp(14px,4vw,32px);background:radial-gradient(ellipse at 15% 10%,#e6effc 0,transparent 38%),#f5f7fb;color:var(--ink);font:16px/1.55 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
    main{width:min(100%,520px);padding:clamp(24px,7vw,52px);text-align:center;background:#fff;border:1px solid var(--line);border-radius:clamp(14px,4vw,20px);box-shadow:0 20px 60px #173a7114}
    .mark{width:clamp(46px,12vw,54px);height:clamp(46px,12vw,54px);margin:0 auto 22px;display:grid;place-items:center;border-radius:15px;background:var(--blue);color:var(--lime);font-size:24px;font-weight:800;box-shadow:0 8px 20px #0249e133}
    .code{margin:0;color:var(--blue);font-size:clamp(3.25rem,17vw,5.5rem);font-weight:800;line-height:1;letter-spacing:-.07em}
    h1{margin:18px 0 8px;font-size:clamp(1.3rem,5vw,1.7rem);line-height:1.25;letter-spacing:-.035em}main>p:not(.code){margin:0 auto;color:var(--muted);max-width:36ch}
    nav{display:flex;justify-content:center;flex-wrap:wrap;gap:10px;margin-top:28px}.button{display:inline-flex;min-height:46px;align-items:center;justify-content:center;padding:0 18px;border:1px solid var(--line);border-radius:10px;color:var(--ink);font:inherit;font-size:.92rem;font-weight:650;text-decoration:none;transition:background .16s,border-color .16s,color .16s,transform .16s}.button.primary{border-color:var(--blue);background:var(--blue);color:#fff}.button:hover{transform:translateY(-1px)}.button.primary:hover{border-color:var(--blue-dark);background:var(--blue-dark)}.button:focus-visible{outline:3px solid #80b0ec;outline-offset:3px}
    footer{margin-top:30px;color:#98a2b3;font-size:.78rem}
    @media(max-width:420px){body{padding:12px}main{padding:28px 20px}nav{display:grid;grid-template-columns:1fr;gap:9px;margin-top:23px}.button{width:100%}footer{margin-top:24px}}
    @media(max-height:560px){body{align-items:start}main{margin:auto 0}}
    @media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;transition:none!important}}
  </style>
</head>
<body>
  <main>
    <div class="mark" aria-hidden="true">L</div>
    <p class="code" aria-label="Error <?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $code, ENT_QUOTES, 'UTF-8') ?></p>
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    <nav aria-label="Helpful links">
      <a class="button" href="<?= htmlspecialchars($safeBackUrl, ENT_QUOTES, 'UTF-8') ?>">Go back</a>
      <a class="button primary" href="<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>">Go to home</a>
      <a class="button" href="<?= htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') ?>">Sign in</a>
    </nav>
    <footer>Loan Manager · Weekly Loan Management System</footer>
  </main>
</body>
</html>
