=== Local Crush ===
Contributors: cottboy
Tags: image compression, webp, avif, jpeg xl, local
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

纯本地 WordPress 图片压缩：调用服务器上的命令行工具，不上传图片、不需要 API Key。

== Description ==

Local Crush 是一个完全在服务器本地运行的图片压缩插件。图片不会发送到任何第三方服务，不需要注册账号、不需要 API Key，也没有云端调用次数限制。

**核心优势：纯本地。**

- 所有压缩、格式转换和缩略图处理都在你的服务器上完成。
- 图片、备份和转换结果都不会离开服务器。
- 没有外部 API 请求，不消耗云端额度，不受第三方服务停运影响。

主要功能：

* 支持 JPEG、PNG、GIF、WebP、AVIF、JPEG XL 和 SVG。
* 支持 PNG、JPG、GIF 转换为 WebP、AVIF 或 JPEG XL。
* 支持 GIF、APNG、动画 WebP、动画 AVIF、动画 JXL。
* 上传时自动压缩、媒体库单张压缩、后台批量压缩。
* 批量任务由服务器自己执行，关掉浏览器页面也会继续。
* 压缩前可备份原图，支持单张或批量恢复，并可按天数自动清理旧备份。
* 可选兼容格式回退：格式转换后保留同样压缩过的旧格式文件，前台用 `<picture>` 自动选择。
* 可逐尺寸控制缩略图生成，支持补生成缺失尺寸和删除未勾选尺寸。
* 超尺寸主图生成 `-scaled` 缩放图后，压缩时可删除留在磁盘上的未缩放原始大图，只保留缩放图。
* 默认删除 EXIF、GPS 等隐私信息。
* 压缩参数自定义设置。

== Installation ==

1. 将插件目录上传到 `/wp-content/plugins/`，或在 WordPress 后台直接安装 ZIP。
2. 在后台启用 Local Crush。
3. 安装需要的命令行工具。可以放进 `/wp-content/local-crush-bin/`，也可以把工具所在目录填到“设置 → Local Crush → 压缩工具”的“工具目录”；用包管理器安装到 `/usr/bin`、`/usr/local/bin` 的会被自动找到。注意：Linux 上在终端里改 `PATH` 对 PHP 无效，Windows 上改系统 Path 后需要重启 PHP / Apache。
4. 打开“设置 → Local Crush”，在“压缩工具”页检查工具状态并调整参数。

插件不携带压缩工具的二进制文件，需要按页面上的工具清单自行安装。

== Frequently Asked Questions ==

= 虚拟主机、共享主机能用吗？ =

不能。插件必须通过 PHP 的 `proc_open()` 启动本地命令行工具。大多数虚拟主机、共享主机默认禁用 `proc_open()`（通常写在 `disable_functions` 里），这种情况下所有压缩都无法执行。

请使用 VPS 或独立服务器，并确保 PHP 允许 `proc_open()`，同时安装对应的命令行工具。可以在“设置 → Local Crush → 压缩工具”顶部查看 `proc_open()` 是否可用。

= 需要 API Key 或联网吗？ =

不需要。插件没有任何云端调用，图片不会被上传到外部服务器。

= 支持哪些图片格式？ =

JPEG、PNG、GIF、WebP、AVIF、JPEG XL 和 SVG，包含 GIF、APNG、动画 WebP、动画 AVIF、动画 JXL 等动画格式。

= 压缩后能恢复原图吗？ =

可以。默认会在压缩前备份原图，可在媒体库单张恢复，也可批量恢复。备份保留天数可以在设置里调整。

= 为什么压缩后文件没有变小？ =

如果压缩或转换后的文件没有变小，插件会保留原图，并按 0% 记为“已压缩”，不会反复重试。

= 没有安装压缩工具会怎样？ =

缺少对应工具时，该格式会安全跳过并保留原图；在“压缩工具”页装好工具后，重新执行压缩即可重试。

== Changelog ==

= 1.0.0 =

* 纯本地压缩，不依赖第三方云服务。
* 支持常用图片格式、动图与格式转换。
* 支持上传自动压缩、媒体库压缩和后台批量任务。
* 支持原图备份与恢复、兼容格式回退和缩略图管理。

== Upgrade Notice ==

= 1.0.0 =

完全免费纯本地的 WordPress 图像压缩插件
