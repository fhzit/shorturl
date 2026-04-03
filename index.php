<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

$pdo = app_db();
app_init($pdo);
app_start_session();
app_send_security_headers();

$path = app_request_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok']);
    exit;
}

if ($path === '/admin/login' && $method === 'POST') {
    if (!app_check_csrf($_POST['csrf'] ?? null)) {
        app_flash('CSRF 校验失败', 'err');
        header('Location: ' . app_url('/admin'));
        exit;
    }

    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $ip = app_client_ip();

    if (!app_turnstile_is_configured()) {
        app_flash('验证码服务未配置，请联系管理员设置 Turnstile Key', 'err');
        header('Location: ' . app_url('/admin'));
        exit;
    }

    if (!app_verify_turnstile($_POST['cf-turnstile-response'] ?? null, $ip)) {
        app_flash('请先完成验证码再登录', 'err');
        header('Location: ' . app_url('/admin'));
        exit;
    }

    if (app_login_is_blocked($pdo, $username, $ip)) {
        app_flash('登录失败次数过多，请稍后再试', 'err');
        header('Location: ' . app_url('/admin'));
        exit;
    }

    if (app_login($pdo, $username, $password)) {
        app_login_clear_failures($pdo, $username, $ip);
        app_flash('已登录');
    } else {
        app_login_record_failure($pdo, $username, $ip);
        app_flash('账号或密码错误', 'err');
    }

    header('Location: ' . app_url('/admin'));
    exit;
}

if ($path === '/admin/logout' && $method === 'POST') {
    if (app_check_csrf($_POST['csrf'] ?? null)) {
        app_logout();
    }

    header('Location: ' . app_url('/admin'));
    exit;
}

if ($path === '/admin/create' && $method === 'POST') {
    app_require_admin();

    if (!app_check_csrf($_POST['csrf'] ?? null)) {
        app_flash('CSRF 校验失败', 'err');
        header('Location: ' . app_url('/admin'));
        exit;
    }

    $result = app_create_link($pdo, (string) ($_POST['long_url'] ?? ''), (string) ($_POST['custom_code'] ?? ''));
    if ($result['ok']) {
        app_flash('已创建: ' . $result['short_url']);
    } else {
        app_flash((string) $result['message'], 'err');
    }

    header('Location: ' . app_url('/admin'));
    exit;
}

if ($path === '/admin/delete' && $method === 'POST') {
    app_require_admin();

    if (!app_check_csrf($_POST['csrf'] ?? null)) {
        app_flash('CSRF 校验失败', 'err');
        header('Location: ' . app_url('/admin'));
        exit;
    }

    $code = trim((string) ($_POST['code'] ?? ''));
    if ($code !== '') {
        app_delete_link($pdo, $code);
        app_flash('已删除: ' . $code);
    }

    header('Location: ' . app_url('/admin'));
    exit;
}

if ($path === '/admin/change-password' && $method === 'POST') {
    app_require_admin();

    if (!app_check_csrf($_POST['csrf'] ?? null)) {
        app_flash('CSRF 校验失败', 'err');
        header('Location: ' . app_url('/admin'));
        exit;
    }

    $oldPassword = (string) ($_POST['old_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $newPasswordConfirm = (string) ($_POST['new_password_confirm'] ?? '');

    if ($newPassword !== $newPasswordConfirm) {
        app_flash('新密码两次输入不一致', 'err');
        header('Location: ' . app_url('/admin'));
        exit;
    }

    $result = app_change_password($pdo, (int) $_SESSION['admin_id'], $oldPassword, $newPassword);
    if ($result['ok']) {
        app_flash('密码已成功修改');
    } else {
        app_flash($result['message'], 'err');
    }

    header('Location: ' . app_url('/admin'));
    exit;
}

if ($path === '/admin') {
    $flash = app_flash();
    $csrf = app_csrf();
    $turnstileSiteKey = app_turnstile_site_key();

    if (!app_is_admin()) {
        ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理后台</title>
    <link rel="stylesheet" href="<?= app_h(app_url('/assets/style.css')) ?>">
</head>
<body class="login-page">
<main class="wrap">
    <section class="card">
        <h1>短链后台</h1>
        <?php if ($flash): ?><p class="msg <?= app_h($flash['type']) ?>"><?= app_h($flash['message']) ?></p><?php endif; ?>
        <form method="post" action="<?= app_h(app_url('/admin/login')) ?>" class="form">
            <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
            <input name="username" type="text" placeholder="用户名" required>
            <input name="password" type="password" placeholder="密码" required>
            <?php if ($turnstileSiteKey !== ''): ?>
                <div class="cf-turnstile" data-sitekey="<?= app_h($turnstileSiteKey) ?>"></div>
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            <?php else: ?>
                <p class="msg err">验证码服务未配置，当前禁止登录。</p>
            <?php endif; ?>
            <button type="submit">登录</button>
        </form>
    </section>
</main>
</body>
</html>
        <?php
        exit;
    }

    $stats = app_stats($pdo);
    $links = app_links($pdo);
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管理后台</title>
    <link rel="stylesheet" href="<?= app_h(app_url('/assets/style.css')) ?>">
</head>
<body>
<main class="wrap">
    <section class="card">
        <div class="row between">
            <h1>短链后台</h1>
            <div class="row" style="gap: 10px;">
                <button type="button" class="btn-light" onclick="document.getElementById('pwd-modal').style.display='flex'">修改密码</button>
                <form method="post" action="<?= app_h(app_url('/admin/logout')) ?>">
                    <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                    <button type="submit" class="btn-light">退出</button>
                </form>
            </div>
        </div>

        <p class="tiny">总短链: <?= $stats['count'] ?> | 总点击: <?= $stats['clicks'] ?></p>

        <div id="pwd-modal" class="modal" style="display:none;">
            <div class="modal-card">
                <div class="modal-head">
                    <h2>修改密码</h2>
                    <button type="button" class="modal-close" onclick="document.getElementById('pwd-modal').style.display='none'">✕</button>
                </div>
                <form method="post" action="<?= app_h(app_url('/admin/change-password')) ?>" class="form">
                    <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                    <label>
                        旧密码
                        <input type="password" name="old_password" required>
                    </label>
                    <label>
                        新密码
                        <input type="password" name="new_password" required>
                    </label>
                    <label>
                        确认新密码
                        <input type="password" name="new_password_confirm" required>
                    </label>
                    <button type="submit">修改密码</button>
                </form>
            </div>
        </div>

        <?php if ($flash): ?><p class="msg <?= app_h($flash['type']) ?>"><?= app_h($flash['message']) ?></p><?php endif; ?>

        <form method="post" action="<?= app_h(app_url('/admin/create')) ?>" class="form">
            <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
            <input name="long_url" type="text" placeholder="长链接，例如 https://example.com/a" required>
            <input name="custom_code" type="text" placeholder="短链码(可选)">
            <button type="submit">创建</button>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>短链</th>
                        <th>目标地址</th>
                        <th>点击</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($links as $item): ?>
                        <tr>
                            <td data-label="短链"><a href="<?= app_h(app_short_url($item['code'])) ?>" target="_blank" rel="noreferrer"><?= app_h($item['code']) ?></a></td>
                            <td class="url" data-label="目标地址"><?= app_h($item['long_url']) ?></td>
                            <td data-label="点击"><?= (int) $item['clicks'] ?></td>
                            <td data-label="操作">
                                <form method="post" action="<?= app_h(app_url('/admin/delete')) ?>" onsubmit="return confirm('确认删除?');">
                                    <input type="hidden" name="csrf" value="<?= app_h($csrf) ?>">
                                    <input type="hidden" name="code" value="<?= app_h($item['code']) ?>">
                                    <button type="submit" class="btn-danger">删</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$links): ?>
                        <tr><td colspan="4" class="tiny">暂无短链</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
    <?php
    exit;
}

if (preg_match('#^/(.+)$#', $path, $m)) {
    app_redirect_by_code($pdo, rawurldecode($m[1]));
}

http_response_code(404);
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 - 页面未找到</title>
    <link rel="stylesheet" href="<?= app_h(app_url('/assets/style.css')) ?>">
    <style>
        body.not-found-page {
            min-height: 100vh;
            display: grid;
            place-items: center;
        }
        .not-found-page .wrap {
            margin: 0 auto;
            width: min(460px, 94%);
        }
        .not-found-card {
            text-align: center;
        }
        .not-found-card h1 {
            font-size: 3.6rem;
            margin: 0 0 12px;
            color: #c0392b;
        }
        .not-found-card p {
            margin: 12px 0;
            color: #666;
            font-size: 15px;
        }
        .not-found-card a {
            display: inline-block;
            margin-top: 20px;
        }
    </style>
</head>
<body class="not-found-page">
<main class="wrap">
    <section class="card not-found-card">
        <h1>404</h1>
        <p>页面未找到</p>
        <p class="tiny">访问的链接或资源不存在</p>
    </section>
</main>
</body>
</html>
<?php
