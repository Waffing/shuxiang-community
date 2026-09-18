<p align="center">
  <strong>简体中文</strong> · <a href="README.en.md">English</a>
</p>

<p align="center">
  <img src="docs/images/cover.svg" alt="数享社区：让好软件被发现，让分享有回响" width="100%">
</p>

<div align="center">

# 数享社区 · Shuxiang Community

**软件有版本，分享有回应，社区有秩序。**

用原生 PHP 和 JavaScript 构建的软件资源社区，覆盖桌面、移动浏览器、PWA 与 Android WebView。

[![Source checks](https://github.com/Waffing/shuxiang-community/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/Waffing/shuxiang-community/actions/workflows/ci.yml)

<kbd>PHP 8.2</kbd> &nbsp; <kbd>MariaDB 10.11</kbd> &nbsp; <kbd>Redis 7</kbd> &nbsp; <kbd>Vanilla JS</kbd> &nbsp; <kbd>PWA</kbd> &nbsp; <kbd>Kotlin</kbd>

[界面预览](#界面预览) · [功能](#功能) · [快速部署](#快速部署) · [架构](#架构与开发) · [更新记录](CHANGELOG.md) · [最新版本](https://github.com/Waffing/shuxiang-community/releases/latest)

</div>

| 想先体验 | 想自己部署 | 想参与开发 |
| :--- | :--- | :--- |
| [打开数享社区 →](https://2.bhsq.top/) | [部署与首次使用 →](docs/GETTING_STARTED.md) | [贡献指南 →](CONTRIBUTING.md) |
| 浏览界面、搜索与社区交互 | 独立 Docker、已有 Nginx、本地预览 | 开发手册、问题反馈与 Pull Request |

## 界面预览

以下截图来自**隔离演示环境**；示例软件、账户和下载内容只用于展示交互。

![桌面资源详情：版本、平台、说明与下载交互](docs/images/desktop-detail.png)

<details>
<summary><strong>展开移动端预览 · 首页与资源详情</strong></summary>

<table>
  <tr>
    <th width="50%">移动首页</th>
    <th width="50%">资源详情</th>
  </tr>
  <tr>
    <td align="center"><img src="docs/images/mobile-home.png" alt="375px 移动端首页演示截图" width="320"></td>
    <td align="center"><img src="docs/images/mobile-detail.png" alt="375px 移动端资源详情演示截图" width="320"></td>
  </tr>
</table>

</details>

<details>
<summary><strong>展开管理工作台 · 投稿审核与反馈队列</strong></summary>

![使用隔离测试账户展示的管理工作台](docs/images/desktop-moderation.png)

管理入口为 `/#/admin`；所有管理操作都由服务端校验角色。

</details>

## 功能

| 模块 | 当前能力 |
| :--- | :--- |
| **发现资源** | 中文关键词、平台 / 分类 / 时间 / 热度 / 访问条件组合筛选，真实分页与周下载榜 |
| **管理版本** | Windows、macOS、Android、iOS、Linux 标签，版本号、更新日志、多下载源、哈希与解压密码 |
| **参与社区** | 注册登录、收藏、回复、签到、积分、我的发布与个人中心 |
| **审核内容** | 投稿待审、作者编辑后重审、审核原因与记录、首次批准奖励、失效举报与版权申请 |
| **控制访问** | 回复 / 积分 / VIP 解锁，下载额度、短时令牌、Cookie / Bearer 认证、改密后撤销旧会话 |
| **适配设备** | 移动筛选抽屉、键盘焦点管理、PWA 离线页面、Kotlin WebView 客户端源码 |
| **便于维护** | 服务端详情与 SEO、Redis 缓存、数据库迁移、Docker Compose、HTTPS 与定时备份 |

**视觉也是源码。** 平台标识、界面图标、空状态和默认头像由 SVG/CSS 绘制。没有上传图标时，名称首字母与分类配色生成矢量头像；上传图片经处理生成 WebP。

信任状态来自结构化验证记录；文件哈希用于核对内容一致性。PWA 不缓存 API、下载附件或 `no-store` 响应。

## 快速部署

适用于**全新、独立的 Linux Docker 环境**。先准备 Docker Engine、Compose 插件、Git、OpenSSL，解析好域名，并确保 80/443 空闲且公网可达。

```bash
git clone https://github.com/Waffing/shuxiang-community.git
cd shuxiang-community
cp .env.example .env

read -r -p '站点域名: ' DOMAIN
read -r -p '证书通知邮箱: ' CERTBOT_EMAIL
DOMAIN="$DOMAIN" CERTBOT_EMAIL="$CERTBOT_EMAIL" bash deploy.sh

curl -fsS "https://${DOMAIN}/health.php"
```

脚本生成随机密钥、初始化或迁移数据库、构建服务并申请 HTTPS 证书；已有数据库先备份。健康结果应包含 `status: "ok"`、`database: true`、`redis: true`。

新站不创建默认管理员，也不导入演示资源。注册自己的账户后，按[初始化管理员](docs/GETTING_STARTED.md#初始化管理员)完成设置。

已有宝塔 / 宿主机 Nginx，或只想在本机预览？先选择[对应部署流程](docs/GETTING_STARTED.md)，避免独立部署命令占用已有服务端口。

<details>
<summary><strong>常用页面与接口入口</strong></summary>

| 页面 | 路径 | 页面 | 路径 |
| :--- | :--- | :--- | :--- |
| 首页 | `/#/home` | 资源库 | `/#/search` |
| 排行榜 | `/#/rankings` | 发布中心 | `/#/publish` |
| 个人中心 | `/#/account` | 管理工作台 | `/#/admin` |
| 可抓取详情 | `/software/{slug}` | 健康检查 | `/health.php` |

API 动作与认证说明见[开发手册](docs/DEVELOPMENT.md#常用-api)。

</details>

## 架构与开发

![浏览器与客户端经 Nginx 访问 PHP，连接 MariaDB、Redis 和受保护存储](docs/images/architecture.svg)

| 要修改什么 | 从这里开始 |
| :--- | :--- |
| 页面、交互、图标与 PWA | [`app/public/`](app/public/) |
| 资源、账户、下载与审核 | [`app/src/`](app/src/) |
| 建表结构与增量迁移 | [`app/database/`](app/database/) |
| Nginx、PHP 与容器部署 | [`infra/`](infra/) · [`deploy.sh`](deploy.sh) |
| Android WebView | [`android/`](android/) |

线上请求由 PHP-FPM 处理；Node.js 仅用于资产构建与测试。修改前端源码后运行 `npm ci --ignore-scripts` 与 `npm run build`，同步压缩文件、Brotli、页面版本和 Worker 缓存版本。完整命令见[开发手册](docs/DEVELOPMENT.md)。

[GitHub Actions](https://github.com/Waffing/shuxiang-community/actions/workflows/ci.yml)执行资产构建、PHP 镜像与扩展校验、权限和缓存回归，以及部署 / 迁移检查。仓库另有真实数据库、HTTP、上传和浏览器测试；运行条件见[验证说明](docs/DEVELOPMENT.md#验证)。

## 发布状态与路线图

源码按 [MIT License](LICENSE) 发布，版本与变更见 [Releases](https://github.com/Waffing/shuxiang-community/releases) 和 [CHANGELOG](CHANGELOG.md)。Android 当前交付源码，**尚无正式签名 APK**；FCP/LCP 与帧率目标仍待目标设备、网络下的专项测量。

- [ ] 邮件验证与账户恢复。
- [ ] 监控告警、异地备份与恢复演练。
- [ ] 真机性能基线、Android 签名发布与上传 / 下载验收。
- [ ] 根据实际反馈改进搜索、审核和无障碍体验。

## 参与贡献

从一个可复现的 Bug 或明确的使用场景开始。请阅读[贡献指南](CONTRIBUTING.md)，使用 [Issue 表单](https://github.com/Waffing/shuxiang-community/issues/new/choose)提交反馈；涉及漏洞和凭据时按[安全政策](SECURITY.md)私下报告。

MIT 许可覆盖仓库代码与原创视觉资产。第三方依赖见[许可说明](THIRD_PARTY_NOTICES.md)；第三方软件、商标与用户上传内容仍需独立授权。
