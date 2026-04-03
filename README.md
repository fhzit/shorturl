# ShortURL (PHP + SQLite)

仅包含自用场景的极简后台和短链跳转：

- 访问 `/{短链码}` 直接跳转
- 访问 `/admin` 进入管理后台
- 无面向访客的首页

## 运行

```bash
php -S 127.0.0.1:8000 router.php
```

打开：

- `http://127.0.0.1:8000/admin`

## serv00 等虚拟主机部署说明

- 确保站点根目录包含 `index.php`、`lib.php`、`config.php`、`assets/`、`data/`。
- 项目已提供 `.htaccess`，用于把 `/admin`、`/{code}` 这类请求重写到 `index.php`。
- 如果你的环境无法启用重写，也可以临时访问 `.../index.php/admin`。

本项目已支持子目录部署（例如 `https://example.com/shorturl/admin`），后台表单、退出登录、静态资源路径会自动带上部署前缀。

## 默认后台账号

- 用户名: `admin`
- 密码: `admin123`

可通过环境变量覆盖：

```bash
export SHORTURL_ADMIN_USERNAME=admin
export SHORTURL_ADMIN_PASSWORD='your-strong-password'
php -S 127.0.0.1:8000 router.php
```

也支持从 `.env` 文件自动加载环境变量（程序启动时读取项目根目录 `.env`）。

```bash
cp .env.example .env
```

然后按需修改 `.env` 中的配置项即可。

可选安全参数：

```bash
export SHORTURL_LOGIN_MAX_ATTEMPTS=8
export SHORTURL_LOGIN_WINDOW_SECONDS=900
export SHORTURL_TURNSTILE_SITE_KEY='your-site-key'
export SHORTURL_TURNSTILE_SECRET_KEY='your-secret-key'
```

- `SHORTURL_LOGIN_MAX_ATTEMPTS`：同一用户名 + IP 在时间窗口内允许的最大失败次数。
- `SHORTURL_LOGIN_WINDOW_SECONDS`：失败次数统计窗口（秒）。
- `SHORTURL_TURNSTILE_SITE_KEY`：Cloudflare Turnstile 站点公钥。
- `SHORTURL_TURNSTILE_SECRET_KEY`：Cloudflare Turnstile 服务端密钥。

## 数据库存储

SQLite 文件自动创建在 `data/shorturl.sqlite`。

## 安全防护

- SQL 查询统一使用 PDO 预处理语句（防 SQL 注入）。
- 管理后台已启用 CSRF 校验。
- 会话 Cookie 已启用 `HttpOnly`、`SameSite=Lax`，并在 HTTPS 下启用 `Secure`。
- 登录成功后会重新生成会话 ID（防会话固定攻击）。
- 新增登录失败限流（同一用户名 + IP）。
- 后台登录强制 Cloudflare Turnstile 验证，验证码通过后才会校验账号密码。
- 仅允许 `http/https` 目标链接，拒绝控制字符与非常见协议。
- 默认发送基础安全响应头：`X-Frame-Options`、`X-Content-Type-Options`、`Referrer-Policy`、`Permissions-Policy`。
