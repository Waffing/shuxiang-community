# 参与贡献

感谢你帮助数享社区变得更好。我们偏好**范围清楚、容易验证、容易回滚**的改动。

## 报告问题

使用[问题反馈表单](https://github.com/Waffing/shuxiang-community/issues/new?template=bug_report.yml)提交 Bug，请提供：

1. 期望行为与实际行为。
2. 从干净状态开始的最短复现步骤。
3. 使用的提交、浏览器 / 系统、部署方式。
4. 已去除身份与凭证信息的截图或错误输出。

功能建议使用[功能建议表单](https://github.com/Waffing/shuxiang-community/issues/new?template=feature_request.yml)，说明问题、提议与已考虑的替代办法。其他反馈可以从 [Issue 入口](https://github.com/Waffing/shuxiang-community/issues/new/choose)创建空白 Issue。

涉及凭证、权限绕过或私人数据的问题，请使用[私密漏洞报告](https://github.com/Waffing/shuxiang-community/security/advisories/new)，并阅读 [SECURITY.md](SECURITY.md)。

## 开始修改

- 先阅读 [README](README.md) 与[开发手册](docs/DEVELOPMENT.md)。
- 较大的功能先通过 Issue 说明场景、取舍和验收条件，避免重复工作。
- 一个 PR 解决一个问题，不混入无关重构、依赖升级或全库格式化。
- Bug 优先补一个能复现问题的测试；没有现成框架时，提供最小可执行检查。
- 对产品行为有重要疑问时先讨论，不把推测当成既定需求。

## 提交前检查

按改动范围完成适用项：

- [ ] 每个改动都能追溯到本次问题。
- [ ] 受影响的 PHP / JS 语法、业务测试通过。
- [ ] 前端源码、压缩文件、Brotli、页面版本和 Worker 缓存版本保持一致。
- [ ] 数据库变更同时覆盖全新 schema 与可重复增量迁移。
- [ ] 新写接口覆盖 `401`、`403`、成功和两种认证路径。
- [ ] UI 变更检查移动端、桌面端与键盘交互。
- [ ] 未提交 `.env`、密钥、Token、数据库备份、真实用户数据或构建签名材料。
- [ ] PR 中区分已经执行的测试与尚未执行的测试。

## Pull Request 内容

创建 PR 时会自动填入[简短模板](.github/PULL_REQUEST_TEMPLATE.md)：说明问题、最小改动和实际验证结果。有相关 Issue 时附上链接；UI 变更附可公开的前后截图，数据库、缓存或部署变更说明同步与回滚步骤。

新增原创视觉可使用 SVG / CSS；界面截图必须来自你可公开的演示数据。第三方素材或依赖需保留原许可，不把来源不明的软件安装包加入仓库。

提交贡献意味着你确认有权按本项目的 [MIT License](LICENSE) 分发这部分贡献。
