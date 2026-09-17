# 开发手册

## 结构与调用关系

| 模块 | 文件 | 职责 |
| :--- | :--- | :--- |
| 页面入口 | `app/public/index.php` | 页面结构、导航、资源版本 URL、服务端详情 |
| 浏览器交互 | `app/public/assets/app.js` / `styles.css` | 路由、请求、表单、抽屉与视觉 |
| API | `app/public/api.php` | 动作分发、参数接收、认证与响应 |
| 核心业务 | `app/src/ForumService.php` | 资源、搜索、下载资格、积分、审核 |
| 身份与请求 | `app/src/Auth.php` / `Http.php` | Cookie / Bearer、同源校验、会话与输入 |
| 下载 | `app/src/DownloadToken.php` / `app/public/download.php` | HMAC 令牌、期限、受保护文件转发 |
| 数据与缓存 | `app/src/Database.php` / `Cache.php` | PDO、Redis、缓存失效与限流 |
| 图标与 SEO | `app/src/IconService.php` / `Seo.php` | WebP 图标处理、元信息、结构化数据 |
| 数据库 | `app/database/schema.sql` / `migrations/` | 全新结构、增量升级、迁移账本 |
| PWA | `app/public/sw.js` / `manifest.json` | 安装、缓存、离线与更新 |
| Android | `android/app/src/main/java/com/resourceforum/app/` | WebView、下载、缓存与系统集成 |

页面通过 `/api.php?action=...` 调用业务。普通投稿先进入 `draft`；管理员通过后为 `published`；下架为 `disabled`。作者修改已发布内容会重新送审。公开列表、详情、SEO 与下载接口需要一致地遵守内容状态。

## 常用 API

写操作使用 JSON；图标上传使用 `multipart/form-data`。浏览器由 HttpOnly Cookie 维持会话，独立客户端可使用 `Authorization: Bearer`。Cookie 写请求需要有效同源 `Origin`；携带 `Origin` 的 Bearer 请求也接受同源检查。

| 动作 | 方法 | 用途 |
| :--- | :--- | :--- |
| `list` / `search` / `detail` / `rankings` | GET | 检索、详情与榜单 |
| `register` / `login` / `logout` / `change_password` | POST | 身份与会话 |
| `me` / `profile_dashboard` / `my_resources` | GET | 账户与作者工作区 |
| `publish` / `update_resource` / `upload_icon` | POST | 投稿、修改与图标 |
| `reply` / `checkin` / `favorite_toggle` | POST | 社区互动 |
| `favorites` / `favorite_status` | GET | 收藏列表与状态 |
| `download_token` | POST | 校验资源资格、下载额度并解析下载来源 |
| `report` / `dmca` | POST | 失效与版权反馈 |
| `admin_queue` / `admin_resolve` | GET / POST | 管理员处理队列 |

只读示例：

```bash
curl -fsS 'http://localhost/api.php?action=list&platform=android&sort=popular&page=1&limit=12'
```

响应中的 `total`、`total_pages`、`has_more` 用于分页。不要通过前端隐藏按钮代替服务端权限校验，也不要把下载源 URL 直接嵌回公开列表。

## 修改前端

Node.js 仅用于资产构建与测试，不参与线上 PHP 请求处理。

```bash
npm ci --ignore-scripts
npm run build
node --check app/public/assets/app.js
node --check app/public/sw.js
node tests/frontend_release_test.js
npm run test:pwa
```

编辑 `app.js` / `styles.css` 后，生成 `.min.js` / `.min.css` 与对应 `.br`，同时更新 `index.php` 的资源版本 URL 和 `sw.js` 的版本 / 资源引用。构建脚本检查引用一致性，但不会替你选择新版本号。

用 375px 和桌面视口检查横向溢出、表单、焦点和控制台。PWA 变更还需验证新用户注册 Worker、旧 Worker 升级和离线页面；API、下载附件、`no-store` 响应不得进入 Cache Storage。

## 数据库变更

新增结构必须同时更新 `schema.sql` 和 `app/database/migrations/` 内的增量 SQL。迁移名保持可排序且唯一，不再把增量文件放到数据库目录根部。

最低验证：空库初始化、已有库升级、重复执行、失败路径及数据保持。写业务使用事务；DDL 的隐式提交边界需要单独考虑。不要以生产库运行测试 fixture。

## 验证

### 不需要业务数据的检查

以下命令在仓库根目录执行。PHP 需要 8.2 及项目扩展；部署镜像已安装这些扩展。

```bash
find app tests -name '*.php' -print0 | xargs -0 -n1 php -l
php -d zend.assertions=1 tests/token_test.php
php -d zend.assertions=1 tests/security_boundary_test.php
php tests/runtime_security_test.php
php -n tests/cache_invalidation_test.php
php tests/backend_release_test.php
python3 tests/migration_test.py
python3 tests/nginx_release_test.py
python3 tests/android_release_config_test.py
```

`cache_invalidation_test.php` 特意使用 `php -n`，因为它用测试替身模拟 Redis SCAN 游标。Nginx 发布 / 回滚测试需要 Linux 环境；它不替代真实 `nginx -t`、证书与 HTTPS 验收。

没有本机 PHP 时，可用已配置好的 Compose 镜像运行单个测试。例如：

```bash
docker compose --env-file .env run --rm --no-deps \
  --workdir /workspace -v "$PWD:/workspace:ro" \
  app php -d zend.assertions=1 tests/token_test.php
```

挂载整个仓库是为了让 `tests/` 能找到相邻的 `app/src/`，而不是仅挂载测试目录。

### 隔离数据库与 HTTP 集成

下列示例需要 Linux、Docker Compose 和 Python 3，在独立开发检出目录运行。测试会创建、编辑与删除测试数据，数据库名称必须包含 `test`。

```bash
python3 - <<'PY'
from pathlib import Path
import secrets

target = Path('.env.test')
if target.exists():
    raise SystemExit('.env.test 已存在；保留原配置，或使用新的检出目录。')
values = {}
for line in Path('.env.example').read_text().splitlines():
    if line and not line.startswith('#'):
        key, value = line.split('=', 1)
        values[key] = value
values.update(COMPOSE_PROJECT_NAME='shuxiang_tests', DB_DATABASE='shuxiang_test', DOMAIN='localhost')
for key in ('DB_PASSWORD', 'DB_ROOT_PASSWORD', 'REDIS_PASSWORD', 'APP_KEY'):
    values[key] = secrets.token_hex(32)
target.write_text(''.join(f'{key}={value}\n' for key, value in values.items()))
target.chmod(0o600)
PY

docker compose --env-file .env.test -p shuxiang_tests up -d --wait db redis
docker compose --env-file .env.test -p shuxiang_tests build app

docker compose --env-file .env.test -p shuxiang_tests run --rm --no-deps \
  --workdir /workspace -v "$PWD:/workspace" \
  app php tests/backend_release_test.php --database

docker compose --env-file .env.test -p shuxiang_tests run --rm --no-deps \
  --workdir /workspace -v "$PWD:/workspace" \
  app php tests/api_fixture.php

docker compose --env-file .env.test -p shuxiang_tests run -d --no-deps \
  --name shuxiang-http-tests -p 127.0.0.1:18790:18790 \
  --workdir /workspace -v "$PWD:/workspace" \
  -e PHP_CLI_SERVER_WORKERS=4 \
  app php -S 0.0.0.0:18790 -t app/public app/public/router.php

curl -fsS http://127.0.0.1:18790/health.php
python3 tests/api_integration_test.py
```

这里创建了独立 Compose 项目、独立数据库和 Redis，HTTP 服务只映射到本机回环地址。PHP CLI 服务仅用于测试，不取代生产 Nginx / FPM。首次 schema 已登记当前迁移；已有数据升级仍需要独立的旧结构快照测试。

### 浏览器与 PWA 升级

保持上一步的服务运行，并先完成 `api_fixture.php` 与 `api_integration_test.py`。浏览器脚本依赖这一步生成的作者、管理员和已审批资源。

```bash
npm install --no-save --package-lock=false playwright
npx playwright install chromium
node tests/browser_test.cjs
node tests/pwa_upgrade_test.cjs
```

Playwright 是可选测试依赖；Linux 上还需安装浏览器要求的系统库。浏览器测试会修改隔离资源和审核状态；重复运行整组前重新初始化 fixture。PWA 升级测试使用仓库中的旧 Worker fixture，临时写入测试 Worker，并在结束时清理。

完成后停止本轮测试服务，保留数据库卷便于检查：

```bash
docker stop shuxiang-http-tests
docker compose --env-file .env.test -p shuxiang_tests stop
```

再次创建相同名称的 HTTP 测试容器前，先检查并移除自己已停止的旧测试容器，或使用新的唯一名称。不要用批量清理命令影响其他项目。

### Android

使用 Android Studio 打开 `android/`，安装工程要求的 SDK 与 JDK 17。构建自己的站点时显式设置 HTTPS 地址：

```bash
cd android
read -r -p '客户端使用的 HTTPS 站点地址: ' SITE_URL
./gradlew -PSITE_URL="$SITE_URL" test assembleDebug
```

Debug APK 只用于开发验收。正式发布需独立签名、版本递增、APK SHA-256 / 大小 / URL 与更新清单核对，并运行 `android/verify-release.ps1`；验证完成前保持 `releaseAvailable:false`。网页文件选择器、下载和外部 App 跳转仍需真机检查。

## 开发约定

先定义可复现的问题与验收标准，再做最小修改。保持原生架构和现有风格，不为一次性代码引入抽象层，不混入无关格式化。

跨层修改需要联查 API、数据库、前端和部署配置。新增写接口至少验证未登录 `401`、越权 `403`、合法成功，以及 Cookie / Bearer 两条路径。性能优化记录环境、指标、前后样本，而不是只给主观结论。
