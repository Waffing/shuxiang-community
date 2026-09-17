# 数享社区 · Android 客户端

轻量的单 Activity Kotlin WebView 工程，连接你自己的数享社区站点。最低支持 Android 7.0（API 24），编译及目标 SDK 为 35；使用 JDK 17、Gradle Wrapper 8.9、Android Gradle Plugin 8.7.3 和 Kotlin 2.0.21。

> 当前交付的是客户端源码，不含已签名正式 APK。站点更新清单保持 `releaseAvailable: false`；正式签名和真机验收需要由部署者完成。

## 构建与站点配置

使用 Android Studio 打开本目录并安装 Android SDK 35，或在已配置 `ANDROID_HOME` 的环境中执行：

```bash
./gradlew test assembleDebug -PSITE_URL=https://forum.example.com
```

Windows PowerShell 使用：

```powershell
.\gradlew.bat test assembleDebug -PSITE_URL=https://forum.example.com
```

默认 `SITE_URL` 是示例域名 `https://forum.example.com`，使用前请替换为你已部署的 HTTPS 站点；该值会写入 `BuildConfig.SITE_URL`。Debug APK 输出到 `app/build/outputs/apk/debug/app-debug.apk`，仅供本地测试。

如果宿主机开发站点监听端口 8080，Android 模拟器可显式连接：

```bash
./gradlew assembleDebug -PSITE_URL=http://10.0.2.2:8080
```

网络安全配置仅为模拟器宿主机地址 `10.0.2.2` 放行明文 HTTP，其余站点使用 HTTPS。模拟器地址不是客户端默认连接地址，也不适用于真机访问宿主机。

## 正式发行

1. 将 `SITE_URL` 设为实际 HTTPS 域名，并在 `app/build.gradle.kts` 递增 `versionCode`、维护 `versionName`。
2. 使用自己的正式签名密钥，通过 Android Studio 的 **Generate Signed App Bundle or APK** 生成 Release APK；签名密钥、密码和本地配置不提交到 Git。
3. 在 Windows 上从 `android/` 目录运行仓库提供的发行检查：

   ```powershell
   .\verify-release.ps1 -ApkPath .\app\build\outputs\apk\release\app-release.apk -TargetOrigin https://forum.example.com
   ```

4. 按输出核对签名、版本、站点地址、文件大小及 SHA-256，并完成真机登录、图标文件选择、下载、缓存清理、外链跳转和更新流程测试。
5. 正式 APK 使用 `/downloads/resource-forum-v1.1.0-release.apk` 这类文件名，并同步更新服务端清单中的版本、真实哈希、字节数和变更说明，再开启 `releaseAvailable`。Nginx 只公开匹配正式发行命名规则的 APK。

`./gradlew assembleRelease` 只负责 Release 构建；当前工程没有预置生产签名配置，执行成功不等于已经得到可公开分发的签名产物。仓库中不包含签名密钥、Debug APK 或正式下载包。

## 更新清单

“检查更新”读取 `${SITE_URL}/app-update.json`。仓库当前清单如下：

```json
{
  "releaseAvailable": false,
  "versionCode": 2,
  "versionName": "1.1.0",
  "downloadUrl": null,
  "sha256": null,
  "fileSize": null,
  "changelog": [
    "完成正式发布安全检查后提供下载"
  ],
  "minSupportedVersionCode": 1
}
```

- `releaseAvailable: false` 表示暂无正式下载，不向用户展示安装入口。
- `downloadUrl` 可为相对清单 URL 或绝对 URL；解析后的地址必须使用 HTTPS，且与配置的站点同源。
- 客户端比较 `versionCode`，展示版本、更新说明以及有效格式的 SHA-256 和正数文件大小。
- 更新按钮交给系统外部处理器打开下载地址；客户端不自行安装 APK，也没有实现下载完成后逐字节计算并比对哈希。清单展示的 SHA-256 不是设备端完整性验真的结果。
- `minSupportedVersionCode` 用于更新提示的可取消状态，不代替服务端版本支持策略。

## WebView 能力与边界

- 同源页面留在 WebView，外域、网盘和允许的自定义协议交给系统浏览器或对应 App。
- 网页文件下载先展示确认框，再交给 Android `DownloadManager`；登录 Cookie 和 Referer 随实际下载请求传递。
- 网页文件选择器已接入，资源图标上传仍需在目标真机和 WebView 版本上验收。
- 联网使用标准 HTTP 缓存；离线优先读取已有缓存，站点 Service Worker 按自身策略缓存 PWA 资源。未缓存的在线 API 不会因此自动获得离线能力。
- “清除缓存”调用 WebView 缓存清理及 Web Storage 清理；不会主动删除 Cookie。Service Worker 的 Cache Storage 生命周期由站点脚本管理。
- 禁用文件/内容 URI 直接访问和混合内容；调试功能仅随 Debug 构建开启，证书错误使用系统默认处理。

## 相关文件

- [`MainActivity.kt`](app/src/main/java/com/resourceforum/app/MainActivity.kt)：导航、缓存、下载、上传选择器与更新提示。
- [`app/build.gradle.kts`](app/build.gradle.kts)：站点地址、SDK 和应用版本。
- [`network_security_config.xml`](app/src/main/res/xml/network_security_config.xml)：HTTPS 与模拟器 HTTP 配置。
- [`verify-release.ps1`](verify-release.ps1)：正式 APK 发行检查。
- [`../THIRD_PARTY_NOTICES.md`](../THIRD_PARTY_NOTICES.md)：第三方组件及许可证说明。
