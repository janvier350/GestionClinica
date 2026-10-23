<?php
ob_start();
session_start();
if (isset($_SESSION["loggedin"])) {
    // OJO: en el servidor (Linux) las mayúsculas importan; el archivo real es home.php
    header("Location: home.php");
    exit();
}
require_once(__DIR__ . '/lang/i18n.php');
require_once(__DIR__ . '/class/mailer.php');   // nombre de la clínica configurable
$lang = current_lang();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <!-- Favicon de la app -->
    <link rel="icon" type="image/svg+xml" href="images/favicon.svg">
    <link rel="alternate icon" type="image/png" href="images/favicon.png">
    <link rel="apple-touch-icon" href="images/favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php te('login.pageTitle'); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(ellipse at center, #0264d6 1%, #1c2b5a 100%);
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        .login-card {
            display: flex;
            width: 580px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        }

        /* ── Panel izquierdo: formulario ─── */
        .login-form-panel {
            flex: 1;
            background: #fff;
            padding: 44px 36px;
        }

        .login-lang { text-align: right; margin-bottom: 8px; font-size: .78rem; }
        .login-lang a {
            text-decoration: none; color: #5a2d82; font-weight: 700;
            padding: 2px 7px; border-radius: 6px; margin-left: 2px;
        }
        .login-lang a.active { background: #5a2d82; color: #fff; }

        .login-form-panel h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1c2b5a;
            margin-bottom: 6px;
        }

        .login-form-panel p.subtitle {
            font-size: .83rem;
            color: #888;
            margin-bottom: 28px;
        }

        .input-group {
            display: flex;
            align-items: center;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 16px;
            overflow: hidden;
            transition: border-color .2s;
        }

        .input-group:focus-within { border-color: #5a2d82; }

        .input-group .icon {
            width: 46px;
            text-align: center;
            color: #aaa;
            font-size: .95rem;
            flex-shrink: 0;
        }

        .input-group input {
            flex: 1;
            border: none;
            outline: none;
            padding: 13px 12px 13px 0;
            font-size: .9rem;
            color: #333;
            background: transparent;
        }

        .login-extras {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            font-size: .8rem;
        }

        .login-extras a { color: #888; text-decoration: none; }
        .login-extras a:hover { color: #5a2d82; }

        .btn-login {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #5a2d82, #7b3fa8);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .95rem;
            font-weight: 600;
            letter-spacing: .04em;
            cursor: pointer;
            transition: opacity .2s, transform .15s;
        }

        .btn-login:hover { opacity: .92; transform: translateY(-1px); }
        .btn-login:active { transform: translateY(0); }

        /* ── Panel derecho: logo ──────────── */
        .login-logo-panel {
            width: 220px;
            background: #82C5CC;   /* color de marca (L'Chaim) */
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 24px;
            gap: 20px;
        }

        /* ── SVG Logo ────────────────────── */
        .logo-img { width: 170px; height: 170px; display: block; }

        .logo-wordmark {
            text-align: center;
            color: #fff;
        }

        .logo-wordmark .brand {
            display: block;
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: .12em;
            line-height: 1.1;
        }

        .logo-wordmark .tagline {
            display: block;
            font-size: .6rem;
            letter-spacing: .25em;
            color: rgba(255,255,255,.55);
            text-transform: uppercase;
            margin-top: 4px;
        }

        .logo-divider {
            width: 32px;
            height: 1px;
            background: rgba(14,60,66,.25);
        }

        .logo-caption {
            font-size: .68rem;
            color: rgba(14,60,66,.75);
            text-align: center;
            letter-spacing: .05em;
        }

        @media (max-width: 620px) {
            .login-card { flex-direction: column; width: 92%; }
            .login-logo-panel { width: 100%; padding: 28px; flex-direction: row; gap: 16px; }
            .logo-img { width: 72px; height: 72px; }
        }
    </style>
</head>
<body>

<div class="login-card">

    <!-- ── FORMULARIO ───────────────────────────────── -->
    <div class="login-form-panel">
        <div class="login-lang">
            <a href="set_lang.php?lang=en&redir=index.php" class="<?php echo $lang === 'en' ? 'active' : ''; ?>">EN</a>
            <a href="set_lang.php?lang=es&redir=index.php" class="<?php echo $lang === 'es' ? 'active' : ''; ?>">ES</a>
        </div>
        <h2><?php te('login.welcome'); ?></h2>
        <p class="subtitle"><?php te('login.subtitle'); ?></p>

        <form action="class/checkLogin.php" method="post">

            <div class="input-group">
                <span class="icon"><i class="fa fa-user"></i></span>
                <input type="text" name="user" placeholder="<?php te('login.user'); ?>" required autofocus>
            </div>

            <div class="input-group">
                <span class="icon"><i class="fa fa-lock"></i></span>
                <input type="password" name="password" placeholder="<?php te('login.password'); ?>" required>
            </div>

            <div class="login-extras">
                <a href="#"><?php te('login.forgot'); ?></a>
            </div>

            <button type="submit" class="btn-login"><?php te('login.signin'); ?></button>

        </form>
    </div>

    <!-- ── LOGO PANEL ───────────────────────────────── -->
    <div class="login-logo-panel">

        <img class="logo-img" src="images/logo_clinica.png" alt="<?php echo htmlspecialchars(mail_brand(), ENT_QUOTES, 'UTF-8'); ?>">

        <div class="logo-divider"></div>

        <span class="logo-caption"><?php echo t('login.caption'); ?></span>

    </div>

</div>

</body>
</html>
<?php ob_end_flush(); ?>