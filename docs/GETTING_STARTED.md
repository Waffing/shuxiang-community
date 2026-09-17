# 部署与首次使用

本指南区分两种环境：**新服务器由 Docker Nginx 接管 80/443**，以及**已有宝塔 / 宿主机 Nginx**。两种方式不能直接混用。

## 全新独立服务器

### 前置条件

- Linux、Bash、Docker Engine、Docker Compose 插件、Git 和 OpenSSL。
- 自有域名的 A 记录已指向服务器；存在 AAAA 记录时，IPv6 也应正确到达该服务器。
- 80/443 端口空闲，对公网开放；防火墙允许证书申请和 HTTPS 访问。
- 当前操作用户有 Docker 权限，工作目录及备份目录有写权限。

```bash
git clone https://github.com/Waffing/shuxiang-community.git
cd shuxiang-community
cp .env.example .env
read -r -p '站点域名: ' DOMAIN
read -r -p '证书通知邮箱: ' CERTBOT_EMAIL
DOMAIN="$DOMAIN" CERTBOT_EMAIL="$CERTBOT_EMAIL" bash deploy.sh
```

`.env` 内的 `replace-*` 密钥会由部署脚本替换为随机值。保留已有环境的密钥与数据库密码；不要为升级重新生成 `.env`。该文件不属于开源交付内容。

### 实际执行顺序

1. 检查已有数据库；如存在，要求备份服务运行，执行备份并用 `gzip -t` 校验。
2. 有 Git 跟踪分支时执行 `git pull --ff-only`。
3. 校验域名、邮箱及 Compose 配置，启动数据库和 Redis。
4. 按迁移账本运行 `migrate.sh`，随后构建应用、Nginx 和备份镜像。
5. 启动 HTTP 入口，通过 Certbot 申请证书，再启动完整服务并检查 Nginx。

证书申请依赖真实 DNS 和网络连通性。首次安装只创建完整表结构，不创建默认管理员，也不自动导入示例数据。

### 验收

```bash
docker compose --env-file .env config --quiet
docker compose --env-file .env ps
docker compose --env-file .env exec -T nginx nginx -t
curl -fsS "https://${DOMAIN}/health.php"
curl -fsS "https://${DOMAIN}/api.php?action=list"
```

健康接口应同时返回 `status: "ok"`、`database: true` 和 `redis: true`。新站列表为空是正常状态。再用浏览器确认首次声明、注册登录、搜索和资源提交；仅有容器 `healthy` 不等于业务验收。

## 仅本地 HTTP 预览

在一个**全新的检出目录**执行以下命令。它生成本机随机密钥，不申请证书；仍使用宿主机 80/443，因此应确认没有其他服务占用。

```bash
python3 - <<'PY'
from pathlib import Path
import secrets

target = Path('.env')
if target.exists():
    raise SystemExit('.env 已存在；请保留现有配置或另建检出目录。')
values = {}
for line in Path('.env.example').read_text().splitlines():
    if line and not line.startswith('#'):
        key, value = line.split('=', 1)
        values[key] = value
values['DOMAIN'] = 'localhost'
values['COMPOSE_PROJECT_NAME'] = 'shuxiang_local'
for key in ('DB_PASSWORD', 'DB_ROOT_PASSWORD', 'REDIS_PASSWORD', 'APP_KEY'):
    values[key] = secrets.token_hex(32)
target.write_text(''.join(f'{key}={value}\n' for key, value in values.items()))
target.chmod(0o600)
PY

docker compose --env-file .env config --quiet
docker compose --env-file .env up -d --wait db redis
bash migrate.sh
docker compose --env-file .env up -d --build --wait app nginx
curl -fsS http://localhost/health.php
```

打开 `http://localhost/`。此流程不启动证书与定时备份服务，不用于公网生产。停止预览用 `docker compose --env-file .env stop`，数据卷仍保留。

需要演示种子时，在**首次创建测试数据卷之前**添加 `-f docker-compose.yml -f docker-compose.dev.yml` 启动数据库。种子脚本不会自动在已有数据库中重跑；示例压缩包仅含演示说明，不是真实软件安装包。

## 初始化管理员

先在自己的站点注册一个账户。下面的命令按输入的**精确用户名**提升已存在、处于 active 状态的账户，不修改密码、不创建隐藏账户。执行前确认账户确实属于站点维护者。

```bash
read -r -p '要设为管理员的已注册用户名: ' ADMIN_USERNAME
export ADMIN_USERNAME
docker compose --env-file .env exec -T -e ADMIN_USERNAME app php <<'PHP'
<?php
require 'src/bootstrap.php';
$db = App\Database::connection();
$query = $db->prepare('SELECT id, username, role FROM users WHERE username = ? AND status = "active"');
$query->execute([getenv('ADMIN_USERNAME')]);
$user = $query->fetch();
if (!$user) {
    fwrite(STDERR, "未找到处于 active 状态的账户。\n");
    exit(1);
}
$db->prepare('UPDATE users SET role = "admin" WHERE id = ?')->execute([$user['id']]);
echo "管理员设置完成：{$user['username']}\n";
PHP
unset ADMIN_USERNAME
```

登录该账户后打开 `/#/admin`，验证普通用户访问管理员 API 为 `403`，管理员可查看审核队列。普通投稿在审核通过前不进入公开列表。

## 配置在哪里

| 需要调整 | 文件 / 位置 |
| :--- | :--- |
| 域名、证书邮箱、数据库、缓存密钥、备份保留期 | `.env`，字段说明见 [`.env.example`](../.env.example) |
| 页面品牌、导航、首次声明 | [`app/public/index.php`](../app/public/index.php) |
| 交互、图标与视觉样式 | [`app/public/assets/app.js`](../app/public/assets/app.js)、[`styles.css`](../app/public/assets/styles.css) |
| 投稿、积分、下载与审核规则 | [`app/src/ForumService.php`](../app/src/ForumService.php) |
| 数据结构与增量升级 | [`app/database/`](../app/database/) |
| Nginx、PHP、备份计划 | [`infra/`](../infra/) |
| Android 站点、版本与更新清单 | [`android/app/build.gradle.kts`](../android/app/build.gradle.kts)、[`app/public/app-update.json`](../app/public/app-update.json) |

业务规则目前由代码维护，不存在覆盖所有选项的后台配置面板。修改前端源码后，必须按[开发手册](DEVELOPMENT.md)重新构建压缩产物与缓存版本。

## 备份与升级

```bash
# 定时备份之外，再主动生成一份备份
docker compose --env-file .env exec -T backup /usr/local/bin/backup.sh

# 查看现有备份并校验压缩文件
find backups -maxdepth 1 -name '*.sql.gz' -print
find backups -maxdepth 1 -name '*.sql.gz' -exec gzip -t {} \;
```

上例使用默认 `BACKUP_DIR=./backups`；更改目录后同步调整检查路径。备份服务按 `BACKUP_RETENTION_DAYS` 清理旧备份，因此需要异地留存时应另行配置。

升级前保存已验证的数据库备份、当前版本和部署配置。迁移执行失败时停止发布；MariaDB 的 DDL 可能隐式提交，不能把迁移失败等同于数据库完整回滚。恢复应先停止写入，在隔离库验证指定备份，再安排业务切换。

## 已有宝塔或宿主机 Nginx

仓库提供 [`docker-compose.bt.yml`](../docker-compose.bt.yml)、[`infra/nginx/forum.example.com.conf`](../infra/nginx/forum.example.com.conf) 和 [`scripts/deploy-bt-release.sh`](../scripts/deploy-bt-release.sh)。它们用于**已有站点的蓝绿发布**，不是宝塔首次安装向导。

使用前需要按自己的站点核对模板域名、证书路径、站点根目录、当前版本链接、共享上传 / 下载目录、现有 Compose 项目与环境文件。候选 PHP 只监听回环地址；切换前检查数据库、Redis、Nginx 与公开 HTTPS 路由。不要把示例模板直接覆盖到其他站点配置。

发布脚本保留前一版本与 Nginx 配置，并生成回滚脚本和状态文件。回滚只针对这次应用 / Nginx 切换，不自动倒退数据库结构。第一次接入应在独立站点完成演练，再纳入正式发布流程。
