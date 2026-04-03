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

## 数据库存储

SQLite 文件自动创建在 `data/shorturl.sqlite`。
