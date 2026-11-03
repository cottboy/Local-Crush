<?php
/**
 * Plugin Name: Local Crush
 * Plugin URI: https://github.com/cottboy/local-crush
 * Description: Local Crush – CLI Image Compressor。免费的 WordPress 图片压缩插件。
 * Version: 1.0.0
 * Author: cottboy
 * Author URI: https://github.com/cottboy
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: local-crush
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 插件版本号
 */
define( 'LOCAL_CRUSH_VERSION', '1.0.0' );

/**
 * 插件文件路径
 */
define( 'LOCAL_CRUSH_FILE', __FILE__ );

/**
 * 插件目录路径
 */
define( 'LOCAL_CRUSH_PATH', plugin_dir_path( __FILE__ ) );

/**
 * 插件目录 URL
 */
define( 'LOCAL_CRUSH_URL', plugin_dir_url( __FILE__ ) );

/**
 * 插件基础名称
 */
define( 'LOCAL_CRUSH_BASENAME', plugin_basename( __FILE__ ) );

/**
 * 压缩工具二进制文件目录路径
 * 存储在 wp-content 目录下，避免插件更新时被覆盖
 */
define( 'LOCAL_CRUSH_BIN_PATH', WP_CONTENT_DIR . '/local-crush-bin/' );

/**
 * 数据库版本号
 */
define( 'LOCAL_CRUSH_DB_VERSION', '1.0.0' );

/**
 * 加载插件文本域
 */
function local_crush_load_textdomain() {
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- 插件不通过 WordPress.org 分发，翻译文件随插件自带，需要显式加载 languages 目录。
    load_plugin_textdomain(
        'local-crush',
        false,
        dirname( LOCAL_CRUSH_BASENAME ) . '/languages'
    );
}
add_action( 'plugins_loaded', 'local_crush_load_textdomain' );

/**
 * 检查设置中是否允许上传 SVG
 *
 * @return bool 是否允许
 */
function local_crush_svg_upload_allowed(): bool {
    $general = get_option( 'local_crush_general', array() );
    return ! empty( $general['allow_svg_upload'] );
}

/**
 * 允许上传 SVG 图片（需在设置中开启）
 *
 * @param array $mimes 允许的 MIME 类型
 * @return array 修改后的 MIME 类型
 */
function local_crush_allow_svg_upload( $mimes ) {
    if ( local_crush_svg_upload_allowed() && ! isset( $mimes['svg'] ) ) {
        $mimes['svg'] = 'image/svg+xml';
    }
    return $mimes;
}
add_filter( 'upload_mimes', 'local_crush_allow_svg_upload' );

/**
 * 检查 SVG 里的 DOCTYPE 声明是否安全
 *
 * 只放行“引用外部 DTD”的普通 DOCTYPE（`<!DOCTYPE svg PUBLIC "..." "...">`）。
 * 带内部子集（方括号包起来的那段）的声明可以定义实体和属性默认值，是 XXE 的入口，一律拒绝。
 * 解析时没有开启 LIBXML_DTDLOAD / LIBXML_NOENT，外部 DTD 不会被加载、实体不会被展开。
 *
 * @param string $content SVG 文件内容
 * @return bool 没有 DOCTYPE 或声明安全时返回 true
 */
function local_crush_svg_doctype_is_safe( string $content ): bool {
    $length = strlen( $content );
    $offset = 0;

    while ( false !== ( $start = stripos( $content, '<!DOCTYPE', $offset ) ) ) {
        $quote = '';
        $end   = null;

        for ( $i = $start + 9; $i < $length; $i++ ) {
            $char = $content[ $i ];

            // 引号里的内容（DTD 地址、标识符）不参与结构判断
            if ( '' !== $quote ) {
                if ( $char === $quote ) {
                    $quote = '';
                }

                continue;
            }

            if ( '"' === $char || "'" === $char ) {
                $quote = $char;
                continue;
            }

            // 内部子集从这里开始
            if ( '[' === $char ) {
                return false;
            }

            if ( '>' === $char ) {
                $end = $i;
                break;
            }
        }

        // 声明没有闭合，说明内容已经不合法
        if ( null === $end ) {
            return false;
        }

        $offset = $end + 1;
    }

    return true;
}

/**
 * 清理 SVG 内容中的危险代码
 *
 * 移除 script 与 foreignObject 元素、on* 事件属性、javascript/vbscript
 * 协议与 data:text/html 伪协议；自定义实体与 DOCTYPE 内部子集直接拒绝（防 XXE）
 *
 * @param string $content SVG 文件内容
 * @return string|false 清理后的内容，不合法时返回 false
 */
function local_crush_sanitize_svg_content( string $content ) {
    if ( false !== stripos( $content, '<!ENTITY' ) || ! local_crush_svg_doctype_is_safe( $content ) ) {
        return false;
    }

    if ( false === stripos( $content, '<svg' ) ) {
        return false;
    }

    if ( ! class_exists( 'DOMDocument' ) ) {
        return false;
    }

    $doc = new DOMDocument();

    $prev_libxml_errors = libxml_use_internal_errors( true );
    // LIBXML_NONET：禁止加载外部实体
    $loaded = $doc->loadXML( $content, LIBXML_NONET );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev_libxml_errors );

    if ( ! $loaded ) {
        return false;
    }

    $xpath = new DOMXPath( $doc );

    // 移除可执行外部文档或插件内容的元素：脚本、外部 HTML 容器、内嵌框架与嵌入对象
    foreach ( array( 'script', 'foreignObject', 'iframe', 'embed', 'object', 'handler' ) as $tag ) {
        $nodes = $xpath->query( '//*[local-name()="' . $tag . '"]' );

        if ( $nodes ) {
            foreach ( iterator_to_array( $nodes ) as $node ) {
                if ( null !== $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }
    }

    // <style> 块里的 CSS 同样不允许出现脚本伪协议与可嵌套脚本的数据协议
    $style_nodes = $xpath->query( '//*[local-name()="style"]' );

    if ( $style_nodes ) {
        foreach ( iterator_to_array( $style_nodes ) as $node ) {
            $css = strtolower( (string) $node->textContent );
            // 浏览器会忽略协议中的 ASCII 控制字符与空白，清理后再判断。
            $css = preg_replace( '/[\x00-\x20\x7F]+/', '', $css );

            if ( false !== strpos( $css, 'javascript:' ) || false !== strpos( $css, 'vbscript:' )
                || false !== strpos( $css, 'data:text/html' ) || false !== strpos( $css, 'data:image/svg+xml' ) ) {
                if ( null !== $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }
    }

    // 移除危险属性：on* 事件、脚本伪协议、HTML/XML 数据协议；
    // style 属性值出现在任意位置同样危险（如 url(javascript:...)），一并检查
    $elements = $xpath->query( '//*' );

    if ( $elements ) {
        foreach ( iterator_to_array( $elements ) as $element ) {
            if ( ! $element instanceof DOMElement ) {
                continue;
            }

            foreach ( iterator_to_array( $element->attributes ) as $attribute ) {
                $name  = strtolower( $attribute->nodeName );
                $value = strtolower( trim( (string) $attribute->nodeValue ) );
                // 浏览器会忽略 URL 协议中的 ASCII 控制字符，清理后再判断协议。
                $value = preg_replace( '/[\x00-\x20\x7F]+/', '', $value );

                // 危险协议必须整段扫描：SMIL 的 values 用分号分隔多个值，
                // 只检查开头会放过 values="https://safe;javascript:..."。
                $is_script_protocol = (bool) preg_match( '/(javascript|vbscript):|data:(text\/html|image\/svg\+xml|text\/xml|application\/(xhtml\+xml|xml))/', $value );

                if ( 0 === strpos( $name, 'on' ) || $is_script_protocol ) {
                    $element->removeAttributeNode( $attribute );
                }
            }

            // SMIL 动画可以把目标属性改成事件处理器，例如
            // <set attributeName="onload" to="alert(1)">；整只动画元素删掉。
            $tag_name = strtolower( (string) $element->localName );

            if ( in_array( $tag_name, array( 'animate', 'set', 'animatemotion', 'animatetransform', 'animatecolor' ), true ) ) {
                $target_attribute = strtolower( trim( (string) $element->getAttribute( 'attributeName' ) ) );
                $target_attribute = (string) preg_replace( '/^[a-z0-9]+:/', '', $target_attribute );

                if ( 0 === strpos( $target_attribute, 'on' ) ) {
                    if ( null !== $element->parentNode ) {
                        $element->parentNode->removeChild( $element );
                    }
                }
            }
        }
    }

    // 走到这里说明文档没有依赖 DTD 定义的自定义实体（否则上面的 loadXML 会失败），
    // 所以外部 DTD 声明已经没有保留价值。删掉它，避免下游工具或浏览器再去拉那个地址。
    if ( null !== $doc->doctype && null !== $doc->doctype->parentNode ) {
        $doc->removeChild( $doc->doctype );
    }

    return $doc->saveXML();
}

/**
 * 上传前清理 SVG 文件内容
 *
 * @param array $file 上传文件信息
 * @return array 修改后的上传文件信息
 */
function local_crush_sanitize_svg_upload( $file ) {
    if ( ! local_crush_svg_upload_allowed() ) {
        return $file;
    }

    if ( empty( $file['tmp_name'] ) || ! file_exists( $file['tmp_name'] ) ) {
        return $file;
    }

    // 不能只信客户端报的 MIME：把 .svg 报成 image/png 就能绕过清理。
    // 后缀是 svg，或类型声明是 svg，都按 SVG 处理；真正内容不合法会被下面的解析拒绝。
    $name      = isset( $file['name'] ) ? (string) $file['name'] : '';
    $extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
    $is_svg    = ( 'svg' === $extension )
        || ( ! empty( $file['type'] ) && 'image/svg+xml' === $file['type'] );

    if ( ! $is_svg ) {
        return $file;
    }

    if ( ! class_exists( 'DOMDocument' ) ) {
        $file['error'] = __( '服务器缺少 DOM 扩展，无法安全处理 SVG', 'local-crush' );
        return $file;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
    $content = file_get_contents( $file['tmp_name'] );

    if ( false === $content ) {
        $file['error'] = __( '无法读取上传的 SVG 文件', 'local-crush' );
        return $file;
    }

    $clean = local_crush_sanitize_svg_content( $content );

    if ( false === $clean ) {
        $file['error'] = __( 'SVG 内容不合法或包含危险代码', 'local-crush' );
        return $file;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    $written = file_put_contents( $file['tmp_name'], $clean );

    if ( false === $written || $written !== strlen( $clean ) ) {
        $file['error'] = __( '无法安全写入清理后的 SVG 文件', 'local-crush' );
    }

    return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'local_crush_sanitize_svg_upload' );

/**
 * 加载依赖文件
 */
function local_crush_load_dependencies() {
    // 加载方向元数据辅助类（工具类会用到）
    require_once LOCAL_CRUSH_PATH . 'includes/class-orientation.php';

    // 加载压缩工具基类和实现
    require_once LOCAL_CRUSH_PATH . 'compression/class-tool-base.php';
    require_once LOCAL_CRUSH_PATH . 'compression/class-jpeg.php';
    require_once LOCAL_CRUSH_PATH . 'compression/class-pngquant.php';
    require_once LOCAL_CRUSH_PATH . 'compression/class-oxipng.php';
    require_once LOCAL_CRUSH_PATH . 'compression/class-cwebp.php';
    require_once LOCAL_CRUSH_PATH . 'compression/class-avif.php';
    require_once LOCAL_CRUSH_PATH . 'compression/class-cjxl.php';
    require_once LOCAL_CRUSH_PATH . 'compression/class-gifsicle.php';
    require_once LOCAL_CRUSH_PATH . 'compression/class-oxvg.php';

    // 加载核心类
    require_once LOCAL_CRUSH_PATH . 'includes/class-database.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-backup.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-compatible-fallback.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-naming.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-animation.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-compressor.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-references.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-converter.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-processor.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-task.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-media-library.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-thumbnail-manager.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-settings.php';
    require_once LOCAL_CRUSH_PATH . 'includes/class-local-crush.php';
}

/**
 * 插件激活时执行
 */
function local_crush_activate() {
    // 加载依赖
    local_crush_load_dependencies();

    // 创建压缩工具二进制文件目录
    if ( ! file_exists( LOCAL_CRUSH_BIN_PATH ) ) {
        wp_mkdir_p( LOCAL_CRUSH_BIN_PATH );

        // 添加 .htaccess 保护文件（新旧 Apache 双兼容写法，见 Local_Crush_Backup）
        $htaccess_content = Local_Crush_Backup::protection_htaccess_content();
        file_put_contents( LOCAL_CRUSH_BIN_PATH . '.htaccess', $htaccess_content );

        // 添加 index.php 保护文件
        $index_content = "<?php\n// 禁止直接访问\n";
        file_put_contents( LOCAL_CRUSH_BIN_PATH . 'index.php', $index_content );
    }

    // 创建数据库表
    $database = new Local_Crush_Database();
    $database->create_tables();

    // 设置默认选项
    $default_general = array(
        'auto_compress'            => false,
        'backup_enabled'           => true,
        'backup_retention_days'    => -1,
        'strip_metadata'           => true,
        'fallback_enabled'         => false,
        'image_scale_enabled'      => true,
        'image_size_threshold'     => 2560,
        'delete_unscaled_original' => true,
        'output_format'            => 'avif',
        'output_apng'              => false,
    );

    // 压缩参数的默认值只从参数总表取，不再在激活代码里抄第二份。
    $default_tools = Local_Crush_Settings::defaults();

    // 只在选项不存在时添加默认值
    if ( false === get_option( 'local_crush_general' ) ) {
        add_option( 'local_crush_general', $default_general );
    }

    if ( false === get_option( 'local_crush_tools' ) ) {
        add_option( 'local_crush_tools', $default_tools );
    }

    // 注册上传待办收尾任务，保证没有后台访问时也会排空队列
    if ( ! wp_next_scheduled( Local_Crush_Processor::PENDING_SWEEP_HOOK ) ) {
        wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Local_Crush_Processor::PENDING_SWEEP_HOOK );
    }

    // 注册原图备份到期清理任务，保留时长为永久时任务本身会直接跳过
    if ( ! wp_next_scheduled( Local_Crush_Processor::BACKUP_PRUNE_HOOK ) ) {
        wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', Local_Crush_Processor::BACKUP_PRUNE_HOOK );
    }

    // 注册孤儿数据与压缩临时文件清理任务
    if ( ! wp_next_scheduled( Local_Crush_Processor::CLEANUP_HOOK ) ) {
        wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', Local_Crush_Processor::CLEANUP_HOOK );
    }

    // 刷新重写规则
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'local_crush_activate' );

/**
 * 插件停用时执行
 */
function local_crush_deactivate() {
    // 确保常量可用
    local_crush_load_dependencies();

    wp_clear_scheduled_hook( Local_Crush_Processor::PENDING_SWEEP_HOOK );
    wp_clear_scheduled_hook( Local_Crush_Processor::BACKUP_PRUNE_HOOK );
    wp_clear_scheduled_hook( Local_Crush_Processor::CLEANUP_HOOK );
    wp_unschedule_hook( Local_Crush_Task::WATCHDOG_HOOK );

    // 刷新重写规则
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'local_crush_deactivate' );

/**
 * 初始化插件
 */
function local_crush_init() {
    // 加载依赖
    local_crush_load_dependencies();

    // 初始化主类
    Local_Crush::get_instance();
}
add_action( 'plugins_loaded', 'local_crush_init', 20 );

/**
 * 获取插件实例
 *
 * @return Local_Crush
 */
function local_crush() {
    return Local_Crush::get_instance();
}
