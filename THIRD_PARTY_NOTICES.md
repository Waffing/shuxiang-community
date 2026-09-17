# Third-party notices

数享社区的原创代码使用仓库根目录的 MIT License。第三方组件保留各自的版权和许可证，项目许可证不替换或覆盖它们。

本清单根据仓库中的 `package-lock.json`、Android 构建配置与 Gradle Wrapper 归档核对；没有把依赖下载目录、容器镜像或 Android SDK 复制到仓库中。

## 随源码分发的 Gradle Wrapper

| 文件 | 归属及许可证 |
|---|---|
| `android/gradlew`、`android/gradlew.bat` | Gradle 原作者；保留文件内原始版权声明，Apache License 2.0 |
| `android/gradle/wrapper/gradle-wrapper.jar` | Gradle Wrapper；归档自带 `META-INF/LICENSE`，Apache License 2.0 |

完整许可证副本保存在 [`LICENSES/Apache-2.0.txt`](LICENSES/Apache-2.0.txt)，直接提取自随仓库分发的 Wrapper JAR。该 JAR 中没有单独的 NOTICE 文件。Gradle 分发版本由 `android/gradle/wrapper/gradle-wrapper.properties` 固定为 8.9，构建时由 Wrapper 获取。

## 前端构建依赖

下表反映本仓库锁定的构建依赖，不代表这些工具的源码被合并进应用前端。安装时获取的依赖包保留其自身许可证文件；如单独再分发依赖包，应一并保留这些文件。

| 组件 | 锁定版本 | 许可证 |
|---|---|---|
| `terser` | 5.44.0 | BSD-2-Clause |
| `lightningcss` 及锁定的各平台原生包 | 1.30.1 | MPL-2.0 |
| `detect-libc` | 2.1.2 | Apache-2.0 |
| `@jridgewell/gen-mapping` | 0.3.13 | MIT |
| `@jridgewell/resolve-uri` | 3.1.2 | MIT |
| `@jridgewell/source-map` | 0.3.11 | MIT |
| `@jridgewell/sourcemap-codec` | 1.5.5 | MIT |
| `@jridgewell/trace-mapping` | 0.3.31 | MIT |
| `acorn` | 8.18.0 | MIT |
| `buffer-from` | 1.1.2 | MIT |
| `commander` | 2.20.3 | MIT |
| `source-map` | 0.6.1 | BSD-3-Clause |
| `source-map-support` | 0.5.21 | MIT |

其中 Lightning CSS 的平台包覆盖 Darwin、FreeBSD、Linux 和 Windows；完整包名、校验摘要及依赖关系以 [`package-lock.json`](package-lock.json) 为准。

## 构建和运行环境

Android 工程通过构建仓库获取 Android Gradle Plugin 8.7.3、Kotlin Gradle Plugin 2.0.21 及相关工具。PHP、Nginx、MariaDB、Redis、Certbot 和容器基础镜像由 Docker 构建或运行流程获取。它们各自的许可证及发行条款继续适用，不因本项目采用 MIT 而改变。

## 图像和字体

项目界面使用源码中的 SVG/CSS 图形；分享图由本地绘图脚本生成。仓库没有分发操作系统字体文件。重新生成图片时，所选字体继续适用其自身许可；项目许可不覆盖字体文件本身。
