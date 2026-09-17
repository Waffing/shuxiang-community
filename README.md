<p align="center">
  <img src="docs/images/cover.svg" alt="数享社区：让好软件被发现，让分享有回响" width="100%">
</p>

<div align="center">

# 数享社区 · Shuxiang Community

**把软件资源、使用经验和社区互动，放进同一个轻盈的界面。**

原生 PHP 驱动的开源资源社区，面向桌面、移动浏览器、PWA 与 Android WebView。

<kbd>PHP 8.2</kbd> &nbsp; <kbd>MariaDB 10.11</kbd> &nbsp; <kbd>Redis 7</kbd> &nbsp; <kbd>Vanilla JS</kbd> &nbsp; <kbd>PWA</kbd> &nbsp; <kbd>Kotlin</kbd>

[在线体验](https://2.bhsq.top/) · [部署指南](docs/GETTING_STARTED.md) · [开发手册](docs/DEVELOPMENT.md) · [参与贡献](CONTRIBUTING.md) · [MIT License](LICENSE)

</div>

---

## 不只是一页下载链接

资源有版本，投稿有审核，下载有边界，反馈有去处。数享社区把这些日常流程连接起来，同时保持源码直接、部署可理解。

| 发现 | 分享 | 维护 |
| :--- | :--- | :--- |
| 中文搜索、平台与分类筛选、真实分页、周下载榜 | 多平台版本、更新日志、多下载源、收藏、回复与签到 | 投稿审核、作者编辑重审、失效举报、版权处理、审核记录 |

**视觉也属于源码。** 系统图标、平台标识、空状态和默认软件头像由 SVG/CSS 绘制；未上传图标时，使用名称首字母与分类配色生成矢量头像，而不是依赖第三方随机图片。

## 界面预览

> 以下 PNG 为**演示环境截图，非线上资源/用户数据**。示例条目仅用于展示交互，不代表真实软件推荐、授权声明或下载内容。

### 桌面端 · 信息完整，层次清楚

![桌面资源详情：版本、平台、资源说明和下载交互](docs/images/desktop-detail.png)

<table>
  <tr>
    <th width="50%">移动首页 · 一屏开始探索</th>
    <th width="50%">资源详情 · 版本与反馈一目了然</th>
  </tr>
  <tr>
    <td align="center"><img src="docs/images/mobile-home.png" alt="375px 移动端首页演示截图" width="320"></td>
    <td align="center"><img src="docs/images/mobile-detail.png" alt="375px 移动端资源详情演示截图" width="320"></td>
  </tr>
</table>

<details>
<summary><strong>展开管理工作台预览</strong></summary>

![管理工作台：资源审核与处理队列，使用隔离测试账户](docs/images/desktop-moderation.png)

后台入口为 `/#/admin`，由服务端校验管理员角色；知道地址并不意味着拥有管理权限。

</details>

## 已经能做什么

### 01 / 资源是内容，不是附件堆积

- Windows、macOS、Android、iOS、Linux 平台标签，版本号与折叠更新日志。
- 站内直链与 HTTPS 外部下载源；展示实际填写的 MD5/SHA-256 与解压密码。
- 回复、积分或 VIP 解锁条件；下载前统一检查权限和每日额度。
- 结构化验证记录驱动的信任状态，不把上传者自述当作“安全认证”。

### 02 / 社区能运营，也能追溯

- 注册、登录、个人中心、收藏、签到和积分奖励。
- 普通用户投稿进入待审；作者编辑后重新送审；首次批准奖励不重复发放。
- 我的发布、管理员审核、审核原因、失效举报与版权下架申请。
- 修改密码后撤销旧会话，浏览器 Cookie 与 API Bearer 两种认证路径。

### 03 / 小屏顺手，大屏舒展

- 卡片布局、移动筛选抽屉、键盘焦点循环、Escape 关闭和焦点恢复。
- 中文关键词与平台、分类、更新时间、访问条件、热度组合查询。
- 可抓取的软件详情页、canonical、Open Graph、JSON-LD、robots 与 sitemap。
- PWA 安装、离线页面与更新提示；下载、API 和 `no-store` 响应不进入离线缓存。

### 04 / 部署和源码一起交付

- Docker Compose 包含 Nginx、PHP-FPM、MariaDB、Redis、Certbot 和定时备份。
- 前端提供可读源码、压缩产物与 Brotli 产物，并校验页面 / Service Worker 版本一致性。
- 完整建表结构、增量迁移和迁移账本；升级前备份，备份可验证。
- Kotlin WebView 工程含缓存管理、外部链接、下载拦截与图片选择器。

## 一眼看懂架构

![数享社区架构：浏览器与客户端经 Nginx 进入 PHP API，连接 MariaDB、Redis 和受保护存储](docs/images/architecture.svg)

| 层 | 实现 | 主要入口 |
| :--- | :--- | :--- |
| 页面与交互 | 原生 JavaScript / CSS、SVG、PWA | [`app/public/`](app/public/) |
| 业务与权限 | PHP 8.2、PDO、Cookie / Bearer | [`app/src/`](app/src/) |
| 数据与缓存 | MariaDB、Redis、迁移账本 | [`app/database/`](app/database/) |
| 传输与运维 | Nginx、Compose、Certbot、备份 | [`infra/`](infra/) · [`deploy.sh`](deploy.sh) |
| Android | Kotlin、WebView | [`android/`](android/) |

这里没有为页面再引入一套大型前端框架。接口、页面、数据库和部署文件都在同一仓库，适合直接阅读、按模块修改。

## 从源码到站点

以下命令用于**全新、独立的 Linux Docker 环境**：准备 Docker Engine、Compose 插件、Git、OpenSSL；域名已解析，80/443 空闲且公网可访问。

```bash
git clone https://github.com/Waffing/shuxiang-community.git
cd shuxiang-community
cp .env.example .env

read -r -p '请输入已解析的站点域名: ' DOMAIN
read -r -p '请输入证书通知邮箱: ' CERTBOT_EMAIL
DOMAIN="$DOMAIN" CERTBOT_EMAIL="$CERTBOT_EMAIL" bash deploy.sh

curl -fsS "https://${DOMAIN}/health.php"
```

脚本会为模板中的密钥字段生成随机值，初始化或迁移数据库，构建服务并申请 HTTPS 证书。已有数据库会先备份；新安装不自动创建管理员、不自动导入演示资源。

**已有宝塔 / 宿主机 Nginx？** 不直接运行上面的独立部署命令。先阅读[部署方式与前置条件](docs/GETTING_STARTED.md)，使用独立站点模板与对应发布流程，避免端口冲突。

### 常用页面

| 页面 | 路径 | 页面 | 路径 |
| :--- | :--- | :--- | :--- |
| 首页 | `/#/home` | 资源库 | `/#/search` |
| 排行榜 | `/#/rankings` | 发布中心 | `/#/publish` |
| 个人中心 | `/#/account` | 管理工作台 | `/#/admin` |
| 可抓取详情 | `/software/{slug}` | 健康检查 | `/health.php` |

## 状态与边界

**已提供源码与回归测试：** 搜索分页、审核生命周期、下载配额、Cookie/Bearer、改密撤销、迁移结构、PWA 缓存与浏览器交互。测试类型与运行条件见[开发手册](docs/DEVELOPMENT.md#验证)。

**明确尚待完成：** Android 正式签名发布与真机专项验收；目标设备、目标网络下的 FCP/LCP/帧率测量。仓库没有预置“已达标”性能徽章，也没有可直接公开分发的签名 APK。

### 路线图 · 未完成项

- [ ] 邮件验证与账户恢复，补齐身份生命周期。
- [ ] 监控告警、异地备份与定期恢复演练。
- [ ] 移动真机性能基线与可重复的性能回归。
- [ ] Android 签名发布、上传 / 下载 / 外部 App 真机验收。
- [ ] 根据真实使用反馈完善搜索、审核与无障碍体验。

路线图是方向，不是当前功能清单。优先解决可复现的问题，再考虑增加新模块。

## 一起把它做好

欢迎带着复现步骤的 Bug、具体场景的功能建议和小而完整的 Pull Request。请先阅读[贡献约定](CONTRIBUTING.md)；涉及漏洞、凭证或私人数据的问题，请按[安全报告流程](SECURITY.md)处理。

项目以 [MIT License](LICENSE) 开源。许可覆盖本仓库代码与原创视觉资产，不自动授予第三方软件、商标或用户上传内容的使用权。

<div align="center">

**让好软件被发现，让分享有回响。**

[体验数享社区](https://2.bhsq.top/) · [提交 Issue](https://github.com/Waffing/shuxiang-community/issues) · [开始开发](docs/DEVELOPMENT.md)

</div>
