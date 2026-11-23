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
<meta name="theme-color" content="#eef3fc">
<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>assets/loan-manager-icon.svg">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons.css">
<style>
  :root{
    --page:#eef3fc;
    --card:#0f1b33;
    --card-edge:#22345c;
    --field:#0b1526;
    --field-edge:#2a3f6e;
    --accent:#7CFF6B;
    --accent-dark:#5ee04d;
    --accent2:#3b6fe0;
    --text:#eef2ff;
    --muted:#93a1c4;
    --blue-1:#4f8dff;
    --blue-2:#2f5fd0;
    --blue-3:#9dc0ff;
  }
  *{margin:0;padding:0;box-sizing:border-box}
  html,body{height:100%}
  body{
    font-family:"Segoe UI",system-ui,-apple-system,sans-serif;
    background:var(--page);
    color:var(--text);
  }

  /* ---- white background with blue color variations ---- */
  .scene{
    min-height:100vh;
    display:grid;
    place-items:center;
    padding:24px;
    position:relative;
    overflow:hidden;
    background:
      radial-gradient(560px 420px at 12% 8%,  rgba(79,141,255,.35), transparent 65%),
      radial-gradient(520px 460px at 88% 18%, rgba(47,95,208,.28),  transparent 65%),
      radial-gradient(640px 520px at 80% 92%, rgba(157,192,255,.45), transparent 65%),
      radial-gradient(480px 420px at 10% 88%, rgba(63,110,224,.22),  transparent 65%),
      var(--page);
  }

  .login-shell{
    position:relative;
    z-index:1;
    width:min(400px,100%);
    animation:rise .45s ease-out both;
  }
  @keyframes rise{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}

  .login-card{
    background:var(--card);
    border:1px solid var(--card-edge);
    border-radius:16px;
    padding:38px 32px 26px;
    box-shadow:0 24px 60px rgba(30,60,130,.28);
  }

  .wordmark{
    display:flex;align-items:center;gap:12px;
    font-size:15px;font-weight:600;letter-spacing:.22em;
    color:var(--text);
    margin-bottom:28px;
  }
  .wordmark b{color:var(--accent);font-weight:700}
  .brand-badge{
    width:46px;height:46px;border-radius:12px;
    display:grid;place-items:center;
    background:var(--accent);
    color:#0a0f1c;font-size:22px;
    flex-shrink:0;
  }

  .intro h1{font-size:26px;font-weight:700;margin-bottom:6px}
  .intro p{color:var(--muted);font-size:13.5px;line-height:1.55;margin-bottom:24px}

  .alert-box{
    display:flex;align-items:flex-start;gap:10px;
    background:rgba(255,90,90,.1);
    border:1px solid rgba(255,90,90,.35);
    color:#ff9d9d;
    border-radius:10px;
    padding:12px 14px;
    font-size:13.5px;
    margin-bottom:18px;
  }
  .alert-box i{color:#ff7b7b;flex-shrink:0;margin-top:1px}

  .field{
    position:relative;
    display:block;
    margin-bottom:14px;
  }
  .field > i{
    position:absolute;left:16px;top:50%;transform:translateY(-50%);
    font-size:17px;color:var(--muted);
    transition:color .2s;
    pointer-events:none;
  }
  .field input{
    width:100%;
    background:var(--field);
    border:1px solid var(--field-edge);
    border-radius:10px;
    padding:14px 46px 14px 46px;
    color:var(--text);
    font-size:15px;
    font-family:inherit;
    outline:none;
    transition:border-color .2s, box-shadow .2s;
  }
  .field input::placeholder{color:var(--muted)}
  .field input:focus{
    border-color:var(--accent);
    box-shadow:0 0 0 3px rgba(124,255,107,.15);
  }
  .field:focus-within > i{color:var(--accent)}
  .reveal{
    position:absolute;right:8px;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;
    color:var(--muted);
    font-size:17px;
    padding:8px;
    border-radius:8px;
  }
  .reveal:hover{color:var(--text)}

  .submit{
    width:100%;
    margin-top:10px;
    padding:15px;
    border:none;border-radius:10px;
    background:var(--accent);
    color:#0a0f1c;
    font-size:15.5px;font-weight:700;
    font-family:inherit;
    cursor:pointer;
    display:flex;align-items:center;justify-content:center;gap:8px;
    transition:background .15s, transform .15s;
  }
  .submit:hover{background:var(--accent-dark);transform:translateY(-1px)}
  .submit:active{transform:translateY(0) scale(.98)}
  .submit:disabled{opacity:.7;cursor:default;transform:none}

  .meta-row{
    display:flex;justify-content:space-between;align-items:center;
    margin-top:22px;gap:10px;flex-wrap:wrap;
  }
  .meta-row > span:first-child{
    display:flex;align-items:center;gap:6px;
    color:var(--muted);font-size:12px;
  }
  .lang-pill{
    display:flex;background:var(--field);
    border:1px solid var(--field-edge);
    border-radius:8px;padding:3px;gap:2px;
  }
  .lang-pill a{
    color:var(--muted);
    font-size:12px;font-weight:600;
    padding:5px 12px;border-radius:6px;
    text-decoration:none;
    transition:all .15s;
  }
  .lang-pill a.on{background:var(--accent2);color:#fff}

  .login-foot{
    text-align:center;
    color:#7d8db3;
    font-size:12px;
    margin-top:22px;
  }
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
        <p><?= t('Sign in to record payments, follow up arrears and manage the loan book.') ?></p>
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
                 autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </label>

        <label class="field">
          <i class="bi bi-lock"></i>
          <input type="password" name="password" id="password" placeholder="<?= t('Password') ?>"
                 autocomplete="current-password" required>
          <button type="button" class="reveal" id="revealBtn" aria-label="<?= t('Show password') ?>">
            <i class="bi bi-eye"></i>
          </button>
        </label>

        <button type="submit" class="submit" id="submitBtn">
          <i class="bi bi-box-arrow-in-right"></i>
          <span><?= t('Sign in') ?></span>
        </button>
      </form>

      <div class="meta-row">
        <span><i class="bi bi-shield-lock"></i> <?= t('') ?></span>
        <span class="lang-pill">
          <?php foreach (available_languages() as $code => $label): ?>
            <a class="<?= $code === $lang ? 'on' : '' ?>" href="?lang=<?= e($code) ?>"><?= strtoupper($code) ?></a>
          <?php endforeach; ?>
        </span>
      </div>

    </div>

    <div class="login-foot">
    </div>
  </div>
</div>

<script>
document.getElementById('revealBtn').addEventListener('click', function () {
  const input = document.getElementById('password');
  const icon  = this.querySelector('i');
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
});

document.querySelector('.login-form').addEventListener('submit', function () {
  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.querySelector('span').textContent = <?= json_encode(t('Signing in…')) ?>;
});
</script>

</body>
</html>