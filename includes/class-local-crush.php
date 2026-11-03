<?php
/**
 * Local Crush 主类
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Local Crush 主类
 *
 * 负责初始化和协调所有子模块
 */
class Local_Crush {

    /**
     * 单例实例
     *
     * @var Local_Crush|null
     */
    private static $instance = null;

    /**
     * 数据库模块实例
     *
     * @var Local_Crush_Database
     */
    public $database;

    /**
     * 备份模块实例
     *
     * @var Local_Crush_Backup
     */
    public $backup;

    /**
     * 压缩调度器实例
     *
     * @var Local_Crush_Compressor
     */
    public $compressor;

    /**
     * 图片引用替换实例
     *
     * @var Local_Crush_References
     */
    public $references;

    /**
     * 目标格式底层处理器实例
     *
     * @var Local_Crush_Output
     */
    public $output_processor;

    /**
     * 统一图片压缩处理器实例
     *
     * @var Local_Crush_Processor
     */
    public $processor;

    /**
     * 后台批量任务实例
     *
     * @var Local_Crush_Task
     */
    public $task;

    /**
     * 媒体库集成模块实例
     *
     * @var Local_Crush_Media_Library
     */
    public $media_library;

    /**
     * 缩略图管理模块实例
     *
     * @var Local_Crush_Thumbnail_Manager
     */
    public $thumbnail_manager;

    /**
     * 文件命名消歧模块实例
     *
     * @var Local_Crush_Naming
     */
    public $naming;

    /**
     * 设置页面模块实例
     *
     * @var Local_Crush_Settings
     */
    public $settings;

    /**
     * 获取单例实例
     *
     * @return Local_Crush
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 构造函数
     *
     * 私有化以实现单例模式
     */
    private function __construct() {
        $this->init_modules();
        $this->init_hooks();
    }

    /**
     * 禁止克隆
     */
    private function __clone() {}

    /**
     * 禁止反序列化
     *
     * @throws Exception 禁止反序列化
     */
    public function __wakeup() {
        throw new Exception( __( '不允许反序列化单例实例', 'local-crush' ) );
    }

    /**
     * 初始化各子模块
     */
    private function init_modules() {
        // 初始化数据库模块
        $this->database = new Local_Crush_Database();

        // 初始化备份模块
        $this->backup = new Local_Crush_Backup();

        // 初始化压缩调度器
        $this->compressor = new Local_Crush_Compressor();

        // 初始化图片引用替换模块
        $this->references = new Local_Crush_References();

        // 初始化目标格式底层处理器
        $this->output_processor = new Local_Crush_Output();

        // 前台图片按浏览器能力选择新格式或旧格式回退文件。
        new Local_Crush_Compatible_Fallback();

        // 初始化文件命名消歧模块（上传消歧与转换改名共用一套规则）
        $this->naming = new Local_Crush_Naming();

        // 初始化统一图片压缩处理器
        $this->processor = new Local_Crush_Processor();

        // 初始化后台批量任务引擎
        $this->task = new Local_Crush_Task();

        // 初始化媒体库集成模块
        $this->media_library = new Local_Crush_Media_Library();

        // 初始化缩略图管理模块
        $this->thumbnail_manager = new Local_Crush_Thumbnail_Manager();

        // 初始化设置页面模块（仅在后台）
        if ( is_admin() ) {
            $this->settings = new Local_Crush_Settings();
        }
    }

    /**
     * 注册 WordPress 钩子
     */
    private function init_hooks() {
        // 加载后台脚本和样式
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // 检查数据库版本并升级
        add_action( 'admin_init', array( $this, 'check_db_version' ) );

        // 确保上传待办收尾任务已注册
        add_action( 'admin_init', array( $this, 'ensure_scheduled_tasks' ), 30 );

        // 添加插件设置链接
        add_filter( 'plugin_action_links_' . LOCAL_CRUSH_BASENAME, array( $this, 'add_settings_link' ) );
    }

    /**
     * 补注册定时任务，兼容插件升级前已启用的站点
     */
    public function ensure_scheduled_tasks(): void {
        if ( ! wp_next_scheduled( Local_Crush_Processor::PENDING_SWEEP_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Local_Crush_Processor::PENDING_SWEEP_HOOK );
        }

        if ( ! wp_next_scheduled( Local_Crush_Processor::BACKUP_PRUNE_HOOK ) ) {
            wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', Local_Crush_Processor::BACKUP_PRUNE_HOOK );
        }

        if ( ! wp_next_scheduled( Local_Crush_Processor::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', Local_Crush_Processor::CLEANUP_HOOK );
        }
    }

    /**
     * 加载后台脚本和样式
     *
     * @param string $hook 当前页面钩子
     */
    public function enqueue_admin_scripts( $hook ) {
        // 仅在媒体库和设置页面加载
        $allowed_hooks = array(
            'upload.php',
            'post.php',
            'post-new.php',
            'settings_page_local-crush',
        );

        if ( ! in_array( $hook, $allowed_hooks, true ) ) {
            return;
        }

        // 加载后台 JavaScript
        wp_enqueue_script(
            'local-crush-admin',
            LOCAL_CRUSH_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            LOCAL_CRUSH_VERSION,
            true
        );

        // 传递数据到 JavaScript
        wp_localize_script(
            'local-crush-admin',
            'localCrushData',
            array(
                'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                'nonce'     => wp_create_nonce( 'local_crush_nonce' ),
                'taskState' => $this->task->get_state(),
                'i18n'      => array(
                    'error'                   => __( '操作失败', 'local-crush' ),
                    'detailsTitle'            => __( '图片详情', 'local-crush' ),
                    'detailsClose'            => __( '关闭', 'local-crush' ),
                    'detailsGroup'            => __( '类别', 'local-crush' ),
                    'detailsItem'             => __( '文件项目', 'local-crush' ),
                    'detailsFormat'           => __( '格式', 'local-crush' ),
                    'detailsStatus'           => __( '状态', 'local-crush' ),
                    'detailsOriginalFileSize' => __( '压缩前大小', 'local-crush' ),
                    'detailsCurrentSize'      => __( '当前大小', 'local-crush' ),
                    'detailsSavedSize'        => __( '节省大小', 'local-crush' ),
                    'detailsEmpty'            => __( '没有可显示的文件', 'local-crush' ),
                    'confirmBulkCompress'     => __( '确定要批量压缩媒体库中所有未压缩的图片吗？任务在后台执行，可能需要一些时间。', 'local-crush' ),
                    'confirmRestoreAll'       => __( '确定要恢复所有原图备份吗？此操作不可撤销。', 'local-crush' ),
                    'confirmDeleteThumbnails'   => __( '确定要删除未勾选尺寸的缩略图吗？全站指向这些尺寸的链接会改到面积最接近的现有尺寸；关闭了自动缩放或缩放图与阈值不符时，也会删除缩放图让主图退回原图。此操作不可撤销。', 'local-crush' ),
                    'confirmGenerateThumbnails' => __( '确定要为已勾选但缺失的尺寸补生成缩略图吗？开启了自动缩放且主图超过阈值时，还会生成或修正 -scaled 缩放图。这可能需要一些时间。', 'local-crush' ),
                    'confirmDeleteBackup'     => __( '确定要删除此图片的备份吗？删除后将无法恢复原图。', 'local-crush' ),
                    'confirmDeleteAllBackups' => __( '确定要删除所有原图备份吗？删除后将无法恢复原图。', 'local-crush' ),
                    'confirmDeleteFallback'   => __( '确定要删除此图片的兼容格式回退吗？删除后不支持新格式的浏览器将直接加载新格式图片，此操作不可撤销。', 'local-crush' ),
                    'confirmDeleteAllFallbacks' => __( '确定要删除所有兼容格式回退吗？删除后不支持新格式的浏览器将直接加载新格式图片，此操作不可撤销。', 'local-crush' ),
                    'confirmDeleteAllOriginals' => __( '确定要删除所有未缩放的原始大图吗？只处理生成过 -scaled 缩放图的图片，格式转换后的那份和它的旧格式回退文件会一起删除，媒体库主图仍是 -scaled 图。此操作不涉及备份，没有备份的图片删除后无法恢复，且不可撤销。', 'local-crush' ),
                    'deleteBackup'            => __( '删除备份', 'local-crush' ),
                    'deleteFallback'          => __( '删除兼容格式回退', 'local-crush' ),
                    'processing'              => __( '处理中...', 'local-crush' ),
                    'taskRunning'             => __( '执行中', 'local-crush' ),
                    'taskDone'                => __( '已完成', 'local-crush' ),
                    'taskStopped'             => __( '已停止', 'local-crush' ),
                    'taskStalled'             => __( '任务已停滞，点“继续执行”接着跑', 'local-crush' ),
                    /* translators: 1: 已处理数量, 2: 总数 */
                    'taskProgress'            => __( '已处理 %1$d / %2$d', 'local-crush' ),
                    /* translators: 1: 已检查数量, 2: 图片总数 */
                    'taskScanned'             => __( '已检查 %1$d / %2$d 张图片', 'local-crush' ),
                    /* translators: 1: 成功数, 2: 跳过数, 3: 失败数 */
                    'taskCounts'              => __( '成功 %1$d，跳过 %2$d，失败 %3$d', 'local-crush' ),
                    /* translators: %s: 正在处理的图片名 */
                    'taskCurrent'             => __( '正在处理：%s', 'local-crush' ),
                    /* translators: %s: 已用时长 */
                    'taskElapsed'             => __( '已用时 %s', 'local-crush' ),
                    /* translators: %d: 秒数 */
                    'taskSeconds'             => __( '%d 秒', 'local-crush' ),
                    /* translators: 1: 分钟数, 2: 秒数 */
                    'taskMinutes'             => __( '%1$d 分 %2$d 秒', 'local-crush' ),
                    /* translators: %d: 失败数量 */
                    'taskRetry'               => __( '重试失败的 %d 个项目', 'local-crush' ),
                    'taskStopConfirm'         => __( '确定停止当前任务吗？已经处理完的部分会保留下来。', 'local-crush' ),
                ),
            )
        );
    }

    /**
     * 检查数据库版本并升级
     */
    public function check_db_version() {
        $installed_version = get_option( 'local_crush_db_version' );

        if ( $installed_version !== LOCAL_CRUSH_DB_VERSION ) {
            $this->database->create_tables();
            update_option( 'local_crush_db_version', LOCAL_CRUSH_DB_VERSION );
        }
    }

    /**
     * 添加插件设置链接
     *
     * @param array $links 现有链接
     * @return array 修改后的链接
     */
    public function add_settings_link( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'options-general.php?page=local-crush' ),
            __( '设置', 'local-crush' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * 获取插件设置
     *
     * @param string $group   设置组：general, local
     * @param string $key     设置键名
     * @param mixed  $default 默认值
     * @return mixed 设置值
     */
    public function get_option( $group, $key = null, $default = null ) {
        $option_name = 'local_crush_' . $group;
        $options     = get_option( $option_name, array() );

        if ( null === $key ) {
            return $options;
        }

        return isset( $options[ $key ] ) ? $options[ $key ] : $default;
    }

    /**
     * 更新插件设置
     *
     * @param string $group 设置组：general, local
     * @param string $key   设置键名
     * @param mixed  $value 设置值
     * @return bool 是否更新成功
     */
    public function update_option( $group, $key, $value ) {
        $option_name = 'local_crush_' . $group;
        $options     = get_option( $option_name, array() );

        $options[ $key ] = $value;

        return update_option( $option_name, $options );
    }
}
