<?php
/**
 * login.php — sign in
 */

require_once __DIR__ . '/includes/autoload.php';

if (User::isLoggedIn()) {
    redirect(User::homeUrl());
}

$error    = '';
$username = '';

$flashedError = $_GET['error'] ?? '';
if ($flashedError !== '') {
    $error = (string) $flashedError;
}

if (isPost()) {
    if (!Security::verifyCsrf()) {
        $error = t('Your session expired. Please try again.');
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ip       = clientIp();

        if ($username === '' || $password === '') {
            $error = t('Enter your username and password.');
        } else {
            $limit = Security::checkLoginRateLimit($username, $ip);

            if (!$limit['allowed']) {
                $minutes = max(1, (int) ceil($limit['retry_after'] / 60));
                $error   = t('Too many failed attempts. Try again in :n minute(s).', ['n' => $minutes]);
            } else {
                $userModel = new User();
                $user      = $userModel->login($username, $password);

                Security::recordLoginAttempt($username, $ip, $user !== false);

                if ($user === false) {
                    $error = t('Invalid username or password.');
                    AuditLog::record('auth.failed', 'users', null, 'Failed login for "' . $username . '"');
                } else {
                    Security::regenerateSession();
                    $userModel->createSession($user);
                    $userModel->touchLastLogin((int) $user['id']);

                    $next = (string) ($_POST['next'] ?? '');

                    if ($next !== '' && str_starts_with($next, BASE_URL) && !str_contains($next, '//')) {
                        redirect($next);
                    }

                    redirect(User::homeUrl());
                }
            }
        }
    }
}

$next = (string) ($_GET['next'] ?? '');
$lang = current_lang();
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= t('Sign in') ?> | <?= e(Settings::businessName()) ?></title>
<meta name="theme-color" content="#f5f7fb">
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/loan-manager-icon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons.css">
<style>
  :root{--blue:#0249e1;--blue-dark:#0137a6;--ink:#111827;--muted:#667085;--line:#d9e1ed;--paper:#f5f7fb;--lime:#dafb71}
  *{box-sizing:border-box}html,body{min-height:100%;margin:0}
  body{font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink);-webkit-font-smoothing:antialiased}
  .scene{min-height:100vh;display:grid;place-items:center;padding:28px;background:linear-gradient(145deg,#f8faff 0%,#eef3fc 100%)}
  .login-shell{width:min(100%,440px)}
  .login-card{background:#fff;border:1px solid #e4eaf3;border-radius:18px;padding:clamp(26px,6vw,42px);box-shadow:0 18px 55px rgba(20,44,84,.09),0 2px 8px rgba(20,44,84,.04)}
  .wordmark{display:flex;align-items:center;gap:11px;margin-bottom:34px;color:var(--ink);font-size:16px;font-weight:700;letter-spacing:-.02em}
  .wordmark b{color:var(--blue);font-weight:750}
  .brand-badge{width:42px;height:42px;display:grid;place-items:center;border-radius:12px;background:var(--blue);color:var(--lime);font-size:21px}
  .intro h1{margin:0 0 7px;font-size:28px;line-height:1.2;letter-spacing:-.04em;font-weight:750}
  .intro p{margin:0 0 26px;color:var(--muted);font-size:14px}
  .alert-box{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;margin:0 0 18px;border:1px solid #f2c7cf;border-radius:10px;background:#fff5f7;color:#9e243b;font-size:13.5px}
  .alert-box i{margin-top:2px;flex-shrink:0}
  .login-form{display:grid;gap:15px}
  .field{position:relative;display:block}
  .field>i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#7b8798;font-size:16px;pointer-events:none}
  .field input{width:100%;height:50px;padding:0 46px 0 42px;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink);font:inherit;font-size:14px;outline:none;transition:border-color .16s,box-shadow .16s}
  .field input::placeholder{color:#8a94a3}
  .field input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(2,73,225,.12)}
  .field:focus-within>i{color:var(--blue)}
  .reveal{position:absolute;right:7px;top:50%;transform:translateY(-50%);padding:8px;border:0;border-radius:7px;background:transparent;color:#707b8b;font-size:16px;cursor:pointer}
  .reveal:hover{color:var(--blue)}
  .submit{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:50px;margin-top:3px;border:0;border-radius:9px;background:var(--blue);color:#fff;font:inherit;font-size:14px;font-weight:650;cursor:pointer;transition:background .16s,transform .16s}
  .submit:hover{background:var(--blue-dark)}.submit:active{transform:translateY(1px)}.submit:disabled{opacity:.7;cursor:wait}
  .meta-row{display:flex;justify-content:center;margin-top:22px}
  .lang-pill{display:flex;padding:3px;gap:3px;border:1px solid var(--line);border-radius:9px;background:#f8faff}
  .lang-pill a{padding:5px 11px;border-radius:6px;color:var(--muted);font-size:12px;font-weight:650;text-decoration:none}
  .lang-pill a.on{background:#e6effc;color:var(--blue-dark)}
  .login-foot{margin-top:20px;text-align:center;color:#7b8798;font-size:12px}
  @media(max-width:480px){.scene{padding:16px}.login-card{border-radius:14px}.wordmark{margin-bottom:28px}.intro h1{font-size:25px}}
  @media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;transition-duration:.01ms!important}}
</style>
</head>
<body>

<div class="scene">
  <div class="login-shell">
    <div class="login-card">

      <div class="wordmark">
        <span class="brand-badge"><i class="bi bi-cash-coin"></i></span>
        <span>Loan <b>Manager</b></span>
      </div>

      <div class="intro">

        <h1><?= t('Welcome back') ?></h1>
        <p><?= t('Enter your details to continue.') ?></p>
      </div>

      <?php if ($error !== ''): ?>
        <div class="alert-box" role="alert">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form class="login-form" method="POST" action="<?= BASE_URL ?>login.php" autocomplete="on" novalidate>
        <?= Security::csrfField() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <label class="field">
          <i class="bi bi-person"></i>
          <input type="text" name="username" value="<?= e($username) ?>" placeholder="<?= t('Username') ?>"
                 aria-label="<?= t('Username') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </label>

        <label class="field">
          <i class="bi bi-lock"></i>
          <input type="password" name="password" id="password" placeholder="<?= t('Password') ?>"
                 aria-label="<?= t('Password') ?>" autocomplete="current-password" required>
          <button type="button" class="reveal" id="revealBtn" aria-label="<?= t('Show password') ?>" aria-pressed="false">
            <i class="bi bi-eye"></i>
          </button>
        </label>

        <button type="submit" class="submit" id="submitBtn">
          <span><?= t('Sign in') ?></span>
        </button>
      </form>

      <div class="meta-row">
        <span class="lang-pill">
          <?php foreach (available_languages() as $code => $label): ?>
            <a class="<?= $code === $lang ? 'on' : '' ?>" href="?lang=<?= e($code) ?>"><?= strtoupper($code) ?></a>
          <?php endforeach; ?>
        </span>
      </div>

    </div>

    <div class="login-foot"><?= e(Settings::businessName()) ?></div>
  </div>
</div>

<script>
document.getElementById('revealBtn').addEventListener('click', function () {
  const input = document.getElementById('password');
  const icon  = this.querySelector('i');
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  this.setAttribute('aria-pressed', show ? 'true' : 'false');
});

document.querySelector('.login-form').addEventListener('submit', function () {
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.querySelector('span').textContent = <?= json_encode(t('Signing in…')) ?>;
});
</script>

</body>
</html>
