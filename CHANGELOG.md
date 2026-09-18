# 更新记录 / Changelog

本记录区分已经交付的内容与计划中的工作。下载源码前可按版本查看变化；部署仍需配置自己的域名、凭据与运行环境。

## v1.1.0 — 2026-09-18

首次带版本标签的开源源码发行，包含 2026-09-17 公开的应用功能与本轮仓库维护改进。

### 社区功能

- 多平台资源、版本和更新日志、中文搜索、组合筛选与真实分页。
- 投稿待审、作者编辑重审、管理队列、处理原因与审核记录。
- Cookie/Bearer 认证、改密撤销旧会话、签到积分、收藏和回复。
- 下载权限与额度校验、短时令牌、失效举报及版权申请。
- 服务端资源详情、SEO 元数据、站点地图、PWA 离线页面与缓存边界。
- Kotlin Android WebView 源码，以及 SVG/CSS 图标与默认头像。

### 仓库与开发体验

- 精简中文首页，新增英文 README、真实 CI 状态和文档导航。
- 提供问题/功能建议表单、Pull Request 模板和私密漏洞报告入口。
- CI 增加真实 MariaDB、Redis、HTTP 权限与上传回归；保留源码、前端产物和 PHP 镜像验证。
- 补齐 Docker 构建上下文的环境文件、签名材料和运行数据排除规则。
- 生成产物与 Gradle Wrapper 标记为 generated/vendor，便于浏览源码差异。
- 提供版本化源码归档与 SHA-256 校验文件；第三方许可单独保留。

### 当前边界

- Android 交付源码；正式签名 APK、真机验证仍待部署者完成。
- 性能目标需在目标设备和网络条件下测量；此版本不声称已达到特定 FCP/LCP/帧率。
- 邮件找回、支付、推荐系统和异地备份不是本版本已实现功能。

### English summary

First tagged source release. Includes the existing moderated resource community, authenticated downloads, search, PWA and Android WebView source. Repository improvements add bilingual discovery, issue and PR templates, real database/API integration in CI, build-context exclusions and checksummed source archives. No signed Android binary or measured performance guarantee is included.
