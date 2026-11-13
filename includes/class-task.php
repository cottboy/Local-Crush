<?php
/**
 * Local Crush 后台批量任务
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 后台批量任务引擎
 *
 * 批量操作不再由浏览器一页页地拉：任务状态存进选项，服务器自己一批接一批地跑。
 * 每批只干固定时长就收手，再把接力棒交给下一个请求，所以单个请求永远跑不到
 * 网闸（Nginx、宝塔、CDN）的超时线；浏览器只是看客，关掉页面任务照样继续。
 */
class Local_Crush_Task {

    /**
     * 任务状态选项名
     */
    const OPTION_NAME = 'local_crush_task';

    /**
     * 后台接力请求的动作名
     */
    const ASYNC_ACTION = 'local_crush_task_tick';

    /**
     * 定时兜底钩子
     */
    const WATCHDOG_HOOK = 'local_crush_task_watchdog_event';

    /**
     * 任务正在执行
     */
    const STATUS_RUNNING = 'running';

    /**
     * 任务正常跑完
     */
    const STATUS_DONE = 'done';

    /**
     * 任务被手动停止
     */
    const STATUS_STOPPED = 'stopped';

    /**
     * 单次后台执行的时间预算（秒）
     */
    const BUDGET = 20;

    /**
     * 看门狗内联执行的时间预算（秒）
     */
    const WATCHDOG_BUDGET = 8;

    /**
     * 批量任务单条外部命令上限（秒）
     */
    const COMMAND_TIMEOUT = 60;

    /**
     * 批量任务 PHP 执行上限在预算之外多留的余量（秒）
     */
    const PHP_LIMIT_GRACE = 160;

    /**
     * 执行者心跳宽限（秒），超过这么久没有心跳就认为执行者已经死了
     */
    const RUNNER_GRACE = 15;

    /**
     * 定时兜底事件的间隔（秒）
     */
    const WATCHDOG_DELAY = 120;

    /**
     * 失败清单最多记住多少个附件，用于“重试失败”
     */
    const FAILED_ID_LIMIT = 200;

    /**
     * 任务类型表
     *
     * source 决定要处理的附件从哪来：images 扫全部图片，
     * restore 扫需要恢复的，backups 扫还有备份索引的，fallbacks 扫有兼容格式回退的，
     * queue 是“重试失败”专用的固定名单。
     *
     * @var array
     */
    private $types;

    /**
     * 构造函数
     */
    public function __construct() {
        $this->types = array(
            'compress'            => array(
                'label'  => __( '批量压缩未压缩的图片', 'local-crush' ),
                'source' => 'images',
            ),
            'restore'             => array(
                'label'  => __( '恢复所有原图备份', 'local-crush' ),
                'source' => 'restore',
            ),
            'delete_backups'      => array(
                'label'  => __( '删除所有原图备份', 'local-crush' ),
                'source' => 'backups',
            ),
            'delete_fallbacks'    => array(
                'label'  => __( '删除所有兼容格式回退', 'local-crush' ),
                'source' => 'fallbacks',
            ),
            'delete_originals'    => array(
                'label'  => __( '删除所有未缩放的原始大图', 'local-crush' ),
                'source' => 'originals',
            ),
            'delete_thumbnails'   => array(
                'label'  => __( '删除未勾选尺寸的缩略图', 'local-crush' ),
                'source' => 'images',
            ),
            'generate_thumbnails' => array(
                'label'  => __( '补生成缺失尺寸的缩略图', 'local-crush' ),
                'source' => 'images',
            ),
            'unlock_thumbnails'   => array(
                'label'  => __( '解除所有图片的“不再生成缩略图”锁定', 'local-crush' ),
                'source' => 'no_thumbnails',
            ),
        );

        // 后台接力：不带登录信息，靠任务令牌校验，只认正在执行的那个任务
        add_action( 'wp_ajax_' . self::ASYNC_ACTION, array( $this, 'handle_async_tick' ) );
        add_action( 'wp_ajax_nopriv_' . self::ASYNC_ACTION, array( $this, 'handle_async_tick' ) );

        // 后台页面用的任务接口
        add_action( 'wp_ajax_local_crush_task_start', array( $this, 'ajax_start' ) );
        add_action( 'wp_ajax_local_crush_task_status', array( $this, 'ajax_status' ) );
        add_action( 'wp_ajax_local_crush_task_stop', array( $this, 'ajax_stop' ) );
        add_action( 'wp_ajax_local_crush_task_retry', array( $this, 'ajax_retry' ) );
        add_action( 'wp_ajax_local_crush_task_dismiss', array( $this, 'ajax_dismiss' ) );

        // 接力棒掉了时的定时兜底
        add_action( self::WATCHDOG_HOOK, array( $this, 'run_watchdog' ), 10, 2 );
    }

    /**
     * 读取当前任务
     *
     * @return array|null
     */
    public function get_task() {
        $task = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $task ) || empty( $task['type'] ) || empty( $task['token'] ) ) {
            return null;
        }

        return $task;
    }

    /**
     * 对外暴露的任务状态，供后台页面显示
     *
     * @return array
     */
    public function get_state(): array {
        $task = $this->get_task();

        if ( ! $task || ! isset( $this->types[ $task['type'] ] ) ) {
            return array( 'has_task' => false );
        }

        $status   = (string) $task['status'];
        $total    = max( 0, (int) $task['total'] );
        $progress = 'images' === $task['source'] ? (int) $task['scanned'] : (int) $task['processed'];

        if ( self::STATUS_RUNNING === $status ) {
            $percent = $total > 0 ? (int) floor( min( 1, $progress / $total ) * 100 ) : 0;
        } else {
            // 结束的任务一律满格，避免除不尽时停在 99%
            $percent = 100;
        }

        $failed_ids = isset( $task['failed_ids'] ) ? array_values( (array) $task['failed_ids'] ) : array();
        $started_at = (int) $task['started_at'];

        return array(
            'has_task'    => true,
            'type'        => (string) $task['type'],
            'label'       => (string) $this->types[ $task['type'] ]['label'],
            'status'      => $status,
            'total'       => $total,
            'progress'    => min( $progress, $total ),
            'percent'     => $percent,
            'scanned'     => 'images' === $task['source'],
            'success'     => (int) $task['success'],
            'skipped'     => (int) $task['skipped'],
            'failed'      => (int) $task['failed'],
            'retry_count' => count( $failed_ids ),
            'processed'   => (int) $task['processed'],
            'current'     => (string) $task['current'],
            'elapsed'     => max( 0, time() - $started_at ),
            'stalled'     => self::STATUS_RUNNING === $status && $this->is_stalled( $task ),
            'summary'     => self::STATUS_RUNNING === $status ? '' : $this->build_summary( $task ),
            'message'     => isset( $task['message'] ) ? (string) $task['message'] : '',
        );
    }

    /**
     * 后台页面：开始一个批量任务
     */
    public function ajax_start() {
        check_ajax_referer( 'local_crush_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'local-crush' ) ) );
        }

        $type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
        $start = $this->start( $type );

        if ( is_wp_error( $start ) ) {
            wp_send_json_error( array( 'message' => $start->get_error_message() ) );
        }

        wp_send_json_success( $this->get_state() );
    }

    /**
     * 后台页面：查询任务状态
     *
     * 接力棒掉了（服务器禁止自己访问自己，或上一个执行者被杀了）时，这里顺手补一棒，
     * 所以只要页面开着，任务就不会真的停住。
     */
    public function ajax_status() {
        check_ajax_referer( 'local_crush_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'local-crush' ) ) );
        }

        $task = $this->get_task();

        if ( $task && self::STATUS_RUNNING === $task['status'] && $this->is_stalled( $task ) ) {
            $this->maybe_run( self::WATCHDOG_BUDGET );
        }

        wp_send_json_success( $this->get_state() );
    }

    /**
     * 后台页面：停止任务
     */
    public function ajax_stop() {
        check_ajax_referer( 'local_crush_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'local-crush' ) ) );
        }

        $this->stop();
        wp_send_json_success( $this->get_state() );
    }

    /**
     * 后台页面：只重跑失败的附件
     */
    public function ajax_retry() {
        check_ajax_referer( 'local_crush_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'local-crush' ) ) );
        }

        $task = $this->get_task();

        if ( ! $task || self::STATUS_RUNNING === $task['status'] ) {
            wp_send_json_error( array( 'message' => __( '没有可重试的失败记录', 'local-crush' ) ) );
        }

        $failed_ids = isset( $task['failed_ids'] ) ? array_values( (array) $task['failed_ids'] ) : array();

        if ( empty( $failed_ids ) ) {
            wp_send_json_error( array( 'message' => __( '没有可重试的失败记录', 'local-crush' ) ) );
        }

        $start = $this->start( (string) $task['type'], $failed_ids );

        if ( is_wp_error( $start ) ) {
            wp_send_json_error( array( 'message' => $start->get_error_message() ) );
        }

        wp_send_json_success( $this->get_state() );
    }

    /**
     * 后台页面：关掉结果面板
     */
    public function ajax_dismiss() {
        check_ajax_referer( 'local_crush_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'local-crush' ) ) );
        }

        $task = $this->get_task();

        // 正在执行的任务不给关：关掉就看不到进度，也找不到停止按钮了
        if ( $task && self::STATUS_RUNNING === $task['status'] ) {
            wp_send_json_error( array( 'message' => __( '任务还在执行，请先停止再关闭面板', 'local-crush' ) ) );
        }

        $this->dismiss();

        wp_send_json_success( array( 'has_task' => false ) );
    }

    /**
     * 关掉结果面板：直接把任务工单删掉，没了工单就不再显示
     */
    public function dismiss(): void {
        delete_option( self::OPTION_NAME );
    }

    /**
     * 登记并启动一个批量任务
     *
     * @param string $type  任务类型
     * @param array  $queue 只处理这批附件（重试失败时使用）
     * @return true|WP_Error
     */
    public function start( string $type, array $queue = array() ) {
        if ( ! isset( $this->types[ $type ] ) ) {
            return new WP_Error( 'invalid_type', __( '无效的操作类型', 'local-crush' ) );
        }

        $current = $this->get_task();

        if ( $current && self::STATUS_RUNNING === $current['status'] && ! $this->is_stalled( $current ) ) {
            return new WP_Error( 'busy', __( '已有批量任务正在执行，请等它跑完，或先点“停止任务”', 'local-crush' ) );
        }

        $queue  = array_values( array_unique( array_filter( array_map( 'absint', $queue ) ) ) );
        $source = $this->types[ $type ]['source'];

        if ( ! empty( $queue ) ) {
            $source = 'queue';
            $total  = count( $queue );
        } else {
            $total = $this->count_source( $source );
        }

        $task = array(
            'id'           => uniqid( 'lc_', true ),
            'token'        => wp_generate_password( 43, false, false ),
            'type'         => $type,
            'source'       => $source,
            'status'       => self::STATUS_RUNNING,
            'total'        => $total,
            'cursor'       => 0,
            'scanned'      => 0,
            'processed'    => 0,
            'success'      => 0,
            'skipped'      => 0,
            'failed'       => 0,
            'failed_ids'   => array(),
            'queue'        => $queue,
            'detail'       => array(),
            'current'      => '',
            'runner'       => '',
            'runner_until' => 0.0,
            'watchdog'     => 0,
            'started_at'   => time(),
            'updated_at'   => time(),
            'finished_at'  => 0,
            'message'      => '',
        );

        // 上一个任务留下的兜底定时事件一并撤掉，免得越攒越多
        wp_unschedule_hook( self::WATCHDOG_HOOK );

        $this->save( $task );
        $this->schedule_watchdog( $task );

        if ( ! $this->spawn_async_tick() ) {
            // 服务器禁止自己访问自己时接力棒发不出去，先在本请求里干一小段，
            // 保证任务立刻动起来；之后由页面轮询和定时兜底接着推。
            $this->maybe_run( self::WATCHDOG_BUDGET );
        }

        return true;
    }

    /**
     * 停止当前任务
     */
    public function stop(): void {
        $stopped = $this->mutate(
            function ( array &$task ) {
                if ( self::STATUS_RUNNING !== $task['status'] ) {
                    return false;
                }

                $task['status']      = self::STATUS_STOPPED;
                $task['finished_at'] = time();
                $task['updated_at']  = time();

                return true;
            }
        );

        if ( $stopped ) {
            // 任务都停了，兜底事件没必要再留着
            wp_unschedule_hook( self::WATCHDOG_HOOK );
        }
    }

    /**
     * 定时兜底：接力棒掉了就补一棒，只要任务还在跑就继续排队
     *
     * @param string $task_id 任务 ID
     * @param int    $tick    第几棒，仅用于让定时事件参数不重复
     */
    public function run_watchdog( $task_id = '', $tick = 0 ): void {
        $task = $this->get_task();

        if ( ! $task || $task['id'] !== $task_id || self::STATUS_RUNNING !== $task['status'] ) {
            return;
        }

        if ( $this->is_stalled( $task ) ) {
            // 定时兜底跑在自己的 wp-cron.php 请求里，可以按完整预算干一棒
            $this->maybe_run( self::BUDGET );
        }

        $this->schedule_watchdog( $this->get_task() );
    }

    /**
     * 后台接力入口：真正干活的一棒
     *
     * 不带登录信息，只认任务里的一次性令牌，抢不到执行权就直接退出，
     * 所以重复的请求不会把同一张图压两遍。
     */
    public function handle_async_tick(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 这个后台接力入口不使用 nonce，只认任务里的一次性令牌，并用 hash_equals() 做常量时间比对。
        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $task  = $this->get_task();
        $ran   = false;

        if (
            $task
            && self::STATUS_RUNNING === $task['status']
            && '' !== $token
            && hash_equals( (string) $task['token'], $token )
        ) {
            $ran = $this->maybe_run( self::BUDGET );
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo $ran ? '1' : '0';
        exit;
    }

    /**
     * 抢执行权并跑一棒
     *
     * @param float $budget 本次最多干多久
     * @return bool 是否真的跑了
     */
    public function maybe_run( float $budget ): bool {
        $task = $this->get_task();

        if ( ! $task || self::STATUS_RUNNING !== $task['status'] ) {
            return false;
        }

        $runner = $this->claim_runner( $budget );

        if ( false === $runner ) {
            return false;
        }

        // 后台请求不能因为 PHP 执行时间上限干到一半被杀
        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- 后台压缩一棒需要按任务预算延长执行时间，WordPress 没有替代接口；超限失败由调用方兜底。
            @set_time_limit( (int) $budget + self::PHP_LIMIT_GRACE );
        }

        ignore_user_abort( true );

        // 子命令也必须收短：给 PHP 留出回滚、写记录和清理临时文件的时间。
        Local_Crush_Tool_Base::set_command_timeout_cap( self::COMMAND_TIMEOUT );

        // 单条命令执行期间也要续期，否则超过 35 秒的长命令会被误判为停滞。
        $heartbeat = function () use ( $runner ) {
            $this->heartbeat( $runner );
        };
        add_action( 'local_crush_command_heartbeat', $heartbeat );

        try {
            $this->run( $runner, $budget );
        } finally {
            remove_action( 'local_crush_command_heartbeat', $heartbeat );
            Local_Crush_Tool_Base::reset_command_timeout_cap();
        }

        return true;
    }

    /**
     * 跑一棒：一批一批地处理，到点收手
     *
     * @param string $runner 本次执行者令牌
     * @param float  $budget 本次最多干多久
     */
    private function run( string $runner, float $budget ): void {
        $deadline  = microtime( true ) + $budget;
        $completed = false;

        while ( microtime( true ) < $deadline ) {
            $task = $this->get_task();

            if ( ! $task || self::STATUS_RUNNING !== $task['status'] || ! $this->runner_owns( $task, $runner ) ) {
                break;
            }

            $attachment_id = $this->claim_next( $runner, $deadline );

            if ( false === $attachment_id ) {
                $this->finish( $runner );
                $completed = true;
                break;
            }

            if ( null === $attachment_id ) {
                break;
            }

            $outcome = $this->process_item( $task, (int) $attachment_id );
            $this->record( $task, (int) $attachment_id, $outcome, $deadline );
        }

        $this->release_runner( $runner );

        $task = $this->get_task();

        if ( ! $completed && $task && self::STATUS_RUNNING === $task['status'] ) {
            // 接力棒交给下一棒；发不出去也不要紧，页面轮询和定时兜底都会补上
            $this->spawn_async_tick();
        }
    }

    /**
     * 取下一个要处理的附件
     *
     * @param string $runner   本次执行者令牌
     * @param float  $deadline 本棒截止时间
     * @return int|false|null 附件 ID；false 表示全部干完；null 表示执行权已不在自己手里
     */
    private function claim_next( string $runner, float $deadline ) {
        return $this->mutate(
            function ( array &$task ) use ( $runner, $deadline ) {
                if ( ! $this->runner_owns( $task, $runner ) ) {
                    return null;
                }

                if ( 'queue' === $task['source'] ) {
                    $queue = isset( $task['queue'] ) ? array_values( (array) $task['queue'] ) : array();

                    if ( empty( $queue ) ) {
                        return false;
                    }

                    $attachment_id = (int) array_shift( $queue );
                    $task['queue'] = array_values( $queue );
                } else {
                    $ids = $this->fetch_ids( (string) $task['source'], (int) $task['cursor'], 1 );

                    if ( empty( $ids ) ) {
                        return false;
                    }

                    $attachment_id  = (int) $ids[0];
                    $task['cursor'] = $attachment_id;

                    if ( 'images' === $task['source'] ) {
                        $task['scanned']++;
                    }
                }

                $this->touch( $task, $deadline );

                return $attachment_id;
            }
        );
    }

    /**
     * 处理单个附件
     *
     * @param array $task          任务数据
     * @param int   $attachment_id 附件 ID
     * @return array attempted、success、skipped、failed、current、detail
     */
    private function process_item( array $task, int $attachment_id ): array {
        $outcome = array(
            'attempted' => true,
            'success'   => 0,
            'skipped'   => 0,
            'failed'    => 0,
            'current'   => $this->attachment_label( $attachment_id ),
            'detail'    => array(),
        );

        switch ( $task['type'] ) {
            case 'compress':
                return $this->process_compress( $attachment_id, $outcome );

            case 'restore':
                return $this->process_restore( $attachment_id, $outcome );

            case 'delete_backups':
                $deleted = local_crush()->processor->delete_attachment_backups( $attachment_id );

                $outcome['success']     = $deleted['ok'] ? 1 : 0;
                $outcome['failed']      = $deleted['ok'] ? 0 : 1;
                $outcome['detail']      = array( 'files' => (int) $deleted['files'] );

                return $outcome;

            case 'delete_fallbacks':
                return $this->process_delete_fallback( $attachment_id, $outcome );

            case 'delete_originals':
                return $this->process_delete_original( $attachment_id, $outcome );

            case 'delete_thumbnails':
                return $this->process_delete_thumbnails( $attachment_id, $outcome );

            case 'generate_thumbnails':
                return $this->process_generate_thumbnails( $attachment_id, $outcome );

            case 'unlock_thumbnails':
                return $this->process_unlock_thumbnails( $attachment_id, $outcome );
        }

        $outcome['failed'] = 1;

        return $outcome;
    }

    /**
     * 压缩一个附件
     *
     * @param int   $attachment_id 附件 ID
     * @param array $outcome       结果骨架
     * @return array
     */
    private function process_compress( int $attachment_id, array $outcome ): array {
        $processor = local_crush()->processor;

        // 已经压过的图不算“处理过”，只是顺路看一眼，否则进度条会虚高
        if ( ! $processor->has_pending_files( $attachment_id ) ) {
            $outcome['attempted'] = false;

            return $outcome;
        }

        // 跳过文件也重新尝试：用户装好工具或改完设置后，点一次批量即可。
        $result = $processor->compress_attachment( $attachment_id );
        $status = isset( $result['status'] ) ? (string) $result['status'] : 'failed';

        if ( 'success' === $status ) {
            $outcome['success'] = 1;
        } elseif ( 'skipped' === $status ) {
            $outcome['skipped'] = 1;
        } else {
            $outcome['failed'] = 1;
        }

        return $outcome;
    }

    /**
     * 恢复一个附件的原图
     *
     * @param int   $attachment_id 附件 ID
     * @param array $outcome       结果骨架
     * @return array
     */
    private function process_restore( int $attachment_id, array $outcome ): array {
        $processor = local_crush()->processor;
        $backup    = local_crush()->backup;

        // 备份文件没了又没有任何待收尾的恢复：顺手清掉残留索引，别让它每次都失败
        if ( ! $backup->has_backup( $attachment_id ) && ! $processor->has_pending_restore( $attachment_id ) ) {
            $backup->delete_backup( $attachment_id );
            $outcome['skipped'] = 1;

            return $outcome;
        }

        if ( $processor->restore_attachment( $attachment_id ) ) {
            $outcome['success'] = 1;
        } else {
            $outcome['failed'] = 1;
        }

        return $outcome;
    }

    /**
     * 删除一个附件的兼容格式回退
     *
     * @param int   $attachment_id 附件 ID
     * @param array $outcome       结果骨架
     * @return array
     */
    private function process_delete_fallback( int $attachment_id, array $outcome ): array {
        $result = local_crush()->processor->delete_fallback( $attachment_id );

        $deleted = isset( $result['deleted_files'] ) ? (int) $result['deleted_files'] : 0;
        $failed  = ! empty( $result['failed'] );

        $outcome['detail'] = array(
            'files'    => $deleted,
            'replaced' => isset( $result['replaced'] ) ? (int) $result['replaced'] : 0,
        );

        if ( $failed ) {
            $outcome['failed'] = 1;
        } elseif ( $deleted > 0 ) {
            $outcome['success'] = 1;
        } else {
            $outcome['skipped'] = 1;
        }

        return $outcome;
    }

    /**
     * 删除一个附件的未缩放原始大图
     *
     * @param int   $attachment_id 附件 ID
     * @param array $outcome       结果骨架
     * @return array
     */
    private function process_delete_original( int $attachment_id, array $outcome ): array {
        $result  = local_crush()->processor->delete_original_image( $attachment_id );
        $deleted = isset( $result['deleted_files'] ) ? (int) $result['deleted_files'] : 0;

        $outcome['detail'] = array( 'files' => $deleted );

        if ( ! empty( $result['failed'] ) ) {
            $outcome['failed'] = 1;
        } elseif ( $deleted > 0 ) {
            $outcome['success'] = 1;
        } else {
            $outcome['skipped'] = 1;
        }

        return $outcome;
    }

    /**
     * 删除一个附件里所有未启用的缩略图尺寸
     *
     * @param int   $attachment_id 附件 ID
     * @param array $outcome       结果骨架
     * @return array
     */
    private function process_delete_thumbnails( int $attachment_id, array $outcome ): array {
        $result = local_crush()->thumbnail_manager->delete_disabled_for_attachment( $attachment_id );

        if ( null === $result ) {
            $outcome['failed'] = 1;

            return $outcome;
        }

        $deleted = isset( $result['deleted'] ) ? (int) $result['deleted'] : 0;

        $outcome['detail'] = array(
            'files'    => $deleted,
            'replaced' => isset( $result['replaced'] ) ? (int) $result['replaced'] : 0,
        );

        if ( ! empty( $result['content_failed'] ) ) {
            $outcome['failed']                    = 1;
            $outcome['detail']['content_failed']  = 1;

            return $outcome;
        }

        if ( $deleted > 0 ) {
            $outcome['success'] = 1;
        } else {
            $outcome['skipped'] = 1;
        }

        return $outcome;
    }

    /**
     * 给一个附件补生成缺失尺寸的缩略图
     *
     * @param int   $attachment_id 附件 ID
     * @param array $outcome       结果骨架
     * @return array
     */
    private function process_generate_thumbnails( int $attachment_id, array $outcome ): array {
        $result = local_crush()->thumbnail_manager->generate_missing_thumbnails( $attachment_id );

        if ( null === $result ) {
            $outcome['failed'] = 1;

            return $outcome;
        }

        if ( is_int( $result ) ) {
            $outcome['success']  = 1;
            $outcome['detail']   = array( 'files' => $result );

            return $outcome;
        }

        $outcome['skipped'] = 1;

        return $outcome;
    }

    /**
     * 解除一个附件的“不再生成缩略图”锁定
     *
     * 只关掉开关，不动已经存在的文件；之后的补生成会按正常设置走。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $outcome       结果骨架
     * @return array
     */
    private function process_unlock_thumbnails( int $attachment_id, array $outcome ): array {
        if ( local_crush()->thumbnail_manager->set_thumbnails_disabled( $attachment_id, false ) ) {
            $outcome['success'] = 1;
        } else {
            $outcome['failed'] = 1;
        }

        return $outcome;
    }

    /**
     * 记录一个附件的处理结果
     *
     * @param array $task          任务数据（含本次执行者令牌）
     * @param int   $attachment_id 附件 ID
     * @param array $outcome       处理结果
     * @param float $deadline      本棒截止时间
     */
    private function record( array $task, int $attachment_id, array $outcome, float $deadline ): void {
        $runner = isset( $task['runner'] ) ? (string) $task['runner'] : '';

        if ( '' === $runner ) {
            return;
        }

        $this->mutate(
            function ( array &$current ) use ( $runner, $attachment_id, $outcome, $deadline ) {
                // 这里不要求任务还在跑：刚点完“停止”时，手上这一条结果同样要记上
                if ( ! $this->runner_owned( $current, $runner ) ) {
                    return;
                }

                if ( ! empty( $outcome['attempted'] ) ) {
                    $current['processed']++;
                    $current['success'] += (int) $outcome['success'];
                    $current['skipped'] += (int) $outcome['skipped'];
                    $current['failed']  += (int) $outcome['failed'];
                }

                if ( ! empty( $outcome['failed'] ) ) {
                    $failed_ids = isset( $current['failed_ids'] ) ? (array) $current['failed_ids'] : array();

                    if ( count( $failed_ids ) < self::FAILED_ID_LIMIT ) {
                        $failed_ids[] = $attachment_id;
                    }

                    $current['failed_ids'] = $failed_ids;
                }

                foreach ( (array) $outcome['detail'] as $key => $delta ) {
                    $current['detail'][ $key ] = ( isset( $current['detail'][ $key ] ) ? (int) $current['detail'][ $key ] : 0 ) + (int) $delta;
                }

                $current['current'] = (string) $outcome['current'];

                $this->touch( $current, $deadline );
            }
        );
    }

    /**
     * 抢执行权
     *
     * @param float $budget 本次预算
     * @return string|false 执行者令牌
     */
    private function claim_runner( float $budget ) {
        $runner = wp_generate_password( 20, false, false );
        $now    = microtime( true );

        $claimed = $this->mutate(
            function ( array &$task ) use ( $runner, $budget, $now ) {
                if ( self::STATUS_RUNNING !== $task['status'] ) {
                    return false;
                }

                if ( ! empty( $task['runner'] ) && (float) $task['runner_until'] > $now ) {
                    return false;
                }

                $task['runner']       = $runner;
                $task['runner_until'] = $now + $budget + self::RUNNER_GRACE;
                $task['updated_at']   = time();

                return true;
            }
        );

        return $claimed ? $runner : false;
    }

    /**
     * 刷新心跳，避免干到一半被别人抢走
     *
     * @param array $task     任务数据（按引用）
     * @param float $deadline 本棒截止时间
     */
    private function touch( array &$task, float $deadline ): void {
        $task['runner_until'] = $deadline + self::RUNNER_GRACE;
        $task['updated_at']   = time();
    }

    /**
     * 交还执行权
     *
     * @param string $runner 执行者令牌
     */
    private function release_runner( string $runner ): void {
        $this->mutate(
            function ( array &$task ) use ( $runner ) {
                if ( ! $this->runner_owns( $task, $runner ) ) {
                    return;
                }

                $task['runner']       = '';
                $task['runner_until'] = 0.0;
                $task['updated_at']   = time();
            }
        );
    }

    /**
     * 收尾：任务干完了
     *
     * @param string $runner 执行者令牌
     */
    private function finish( string $runner ): void {
        $finished = $this->mutate(
            function ( array &$task ) use ( $runner ) {
                if ( ! $this->runner_owns( $task, $runner ) ) {
                    return false;
                }

                $task['status']      = self::STATUS_DONE;
                $task['runner']      = '';
                $task['runner_until'] = 0.0;
                $task['current']     = '';
                $task['finished_at'] = time();
                $task['updated_at']  = time();

                return true;
            }
        );

        if ( $finished ) {
            // 任务都干完了，兜底事件没必要再留着；万一是别人刚开的新任务，这里不会动它
            wp_unschedule_hook( self::WATCHDOG_HOOK );
        }
    }

    /**
     * 命令执行期间刷新执行者心跳
     *
     * @param string $runner 执行者令牌
     */
    private function heartbeat( string $runner ): void {
        $this->mutate(
            function ( array &$task ) use ( $runner ) {
                if ( ! $this->runner_owns( $task, $runner ) ) {
                    return;
                }

                $task['runner_until'] = microtime( true ) + self::RUNNER_GRACE + 10;
                $task['updated_at']   = time();
            }
        );
    }

    /**
     * 执行者令牌是否属于自己，且任务还在跑
     *
     * @param array  $task   任务数据
     * @param string $runner 执行者令牌
     * @return bool
     */
    private function runner_owns( array $task, string $runner ): bool {
        return self::STATUS_RUNNING === $task['status']
            && isset( $task['runner'] )
            && (string) $task['runner'] === $runner;
    }

    /**
     * 执行者令牌是否还是自己（任务可能已被停止，最后一条结果仍要记上）
     *
     * @param array  $task   任务数据
     * @param string $runner 执行者令牌
     * @return bool
     */
    private function runner_owned( array $task, string $runner ): bool {
        return isset( $task['runner'] ) && (string) $task['runner'] === $runner;
    }

    /**
     * 任务是不是卡住了：没人持有执行权（或心跳已过期），且已经静置了一段时间
     *
     * @param array $task 任务数据
     * @return bool
     */
    private function is_stalled( array $task ): bool {
        if ( self::STATUS_RUNNING !== $task['status'] ) {
            return false;
        }

        if ( ! empty( $task['runner'] ) && (float) $task['runner_until'] > microtime( true ) ) {
            return false;
        }

        // 刚登记的任务还没轮到任何人接手，给一个宽限期，避免被误判成卡住
        return ( time() - (int) $task['updated_at'] ) > self::RUNNER_GRACE;
    }

    /**
     * 按来源取一批附件 ID
     *
     * @param string $source   来源
     * @param int    $after_id 游标
     * @param int    $limit    数量
     * @return int[]
     */
    private function fetch_ids( string $source, int $after_id, int $limit ): array {
        $database = local_crush()->database;

        switch ( $source ) {
            case 'restore':
                return $database->get_restore_attachment_ids_after( $after_id, $limit );

            case 'backups':
                return $database->get_backup_attachment_ids_after( $after_id, $limit );

            case 'fallbacks':
                return $database->get_fallback_attachment_ids_after( $after_id, $limit );

            case 'originals':
                return $database->get_original_image_attachment_ids_after( $after_id, $limit );

            case 'no_thumbnails':
                return $database->get_no_thumbnails_attachment_ids_after( $after_id, $limit );

            case 'images':
                return $database->get_image_attachment_ids_after( $after_id, $limit );
        }

        return array();
    }

    /**
     * 按来源统计总数，进度条的分母
     *
     * @param string $source 来源
     * @return int
     */
    private function count_source( string $source ): int {
        $database = local_crush()->database;

        switch ( $source ) {
            case 'restore':
                return $database->count_restore_attachments();

            case 'backups':
                return $database->count_backup_attachments();

            case 'fallbacks':
                return $database->count_fallback_attachments();

            case 'originals':
                return $database->count_original_image_attachments();

            case 'no_thumbnails':
                return $database->count_no_thumbnails_attachments();

            case 'images':
                return $database->count_image_attachments();
        }

        return 0;
    }

    /**
     * 附件显示名
     *
     * @param int $attachment_id 附件 ID
     * @return string
     */
    private function attachment_label( int $attachment_id ): string {
        $file = get_attached_file( $attachment_id );

        if ( ! $file ) {
            return '#' . $attachment_id;
        }

        return sprintf( '%s (#%d)', wp_basename( $file ), $attachment_id );
    }

    /**
     * 拼一句结果汇总
     *
     * @param array $task 任务数据
     * @return string
     */
    private function build_summary( array $task ): string {
        if ( (int) $task['total'] < 1 ) {
            return __( '没有需要处理的项目', 'local-crush' );
        }

        $label  = (string) $this->types[ $task['type'] ]['label'];
        $detail = isset( $task['detail'] ) ? (array) $task['detail'] : array();
        $files  = isset( $detail['files'] ) ? (int) $detail['files'] : 0;

        switch ( $task['type'] ) {
            case 'compress':
                $summary = sprintf(
                    /* translators: %1$s: 任务名称, %2$d: 成功数, %3$d: 跳过数, %4$d: 失败数 */
                    __( '%1$s - 成功: %2$d, 跳过: %3$d, 失败: %4$d', 'local-crush' ),
                    $label,
                    (int) $task['success'],
                    (int) $task['skipped'],
                    (int) $task['failed']
                );
                break;

            case 'restore':
                $summary = sprintf(
                    /* translators: %1$s: 任务名称, %2$d: 成功数, %3$d: 失败数 */
                    __( '%1$s - 成功: %2$d, 失败: %3$d', 'local-crush' ),
                    $label,
                    (int) $task['success'],
                    (int) $task['failed']
                );
                break;

            case 'delete_backups':
                $summary = sprintf(
                    /* translators: 1: 涉及的附件数, 2: 删除的备份文件数, 3: 失败的附件数 */
                    __( '已删除 %1$d 个附件上的 %2$d 个备份文件，失败 %3$d 个', 'local-crush' ),
                    (int) $task['success'],
                    $files,
                    (int) $task['failed']
                );
                break;

            case 'delete_fallbacks':
                $summary = sprintf(
                    /* translators: 1: 涉及的附件数, 2: 删除的回退文件数, 3: 替换的链接数 */
                    __( '已删除 %1$d 个附件上的 %2$d 个兼容格式回退文件，替换了 %3$d 个图片链接', 'local-crush' ),
                    (int) $task['success'],
                    $files,
                    isset( $detail['replaced'] ) ? (int) $detail['replaced'] : 0
                );

                if ( ! empty( $detail['content_failed'] ) ) {
                    $summary .= ' ' . __( '部分正文链接未更新成功，可再次执行本操作继续处理。', 'local-crush' );
                }
                break;

            case 'delete_thumbnails':
                $summary = sprintf(
                    /* translators: 1: 删除的文件数, 2: 影响的附件数, 3: 替换的链接数 */
                    __( '已删除 %1$d 个未勾选及已失效尺寸的缩略图（涉及 %2$d 个附件），替换了 %3$d 个图片链接', 'local-crush' ),
                    $files,
                    (int) $task['success'],
                    isset( $detail['replaced'] ) ? (int) $detail['replaced'] : 0
                );

                if ( ! empty( $detail['content_failed'] ) ) {
                    $summary .= ' ' . __( '部分正文链接未更新成功，可再次执行本操作继续处理。', 'local-crush' );
                }
                break;

            case 'delete_originals':
                $summary = sprintf(
                    /* translators: 1: 涉及的文件数, 2: 通过的附件数, 3: 失败的附件数 */
                    __( '已删除 %1$d 个未缩放的原始大图（涉及 %2$d 个附件），失败 %3$d 个', 'local-crush' ),
                    $files,
                    (int) $task['success'],
                    (int) $task['failed']
                );
                break;

            case 'generate_thumbnails':
                $summary = sprintf(
                    /* translators: 1: 补生成的附件数, 2: 新增文件数, 3: 跳过的附件数, 4: 失败的附件数 */
                    __( '已为 %1$d 个附件补生成 %2$d 个缩略图（%3$d 个无需处理或已跳过，%4$d 个失败）', 'local-crush' ),
                    (int) $task['success'],
                    $files,
                    (int) $task['skipped'],
                    (int) $task['failed']
                );
                break;

            case 'unlock_thumbnails':
                $summary = sprintf(
                    /* translators: 1: 解除锁定的附件数, 2: 失败的附件数 */
                    __( '已解除 %1$d 个附件的“不再生成缩略图”锁定，失败 %2$d 个', 'local-crush' ),
                    (int) $task['success'],
                    (int) $task['failed']
                );
                break;

            default:
                $summary = $label;
        }

        if ( (int) $task['failed'] > 0 ) {
            $failed_ids = isset( $task['failed_ids'] ) ? array_values( (array) $task['failed_ids'] ) : array();
            $shown      = array_slice( $failed_ids, 0, 10 );

            $summary .= "\n" . sprintf(
                /* translators: %s: 附件 ID 列表 */
                __( '失败附件 ID：%s', 'local-crush' ),
                implode( ', ', array_map( 'strval', $shown ) )
            );

            if ( count( $failed_ids ) > count( $shown ) ) {
                $summary .= sprintf(
                    /* translators: %d: 失败附件总数 */
                    __( '（共 %d 个，仅显示前 10 个）', 'local-crush' ),
                    count( $failed_ids )
                );
            }
        }

        if ( self::STATUS_STOPPED === $task['status'] ) {
            $summary .= "\n" . sprintf(
                /* translators: %d: 已处理的附件数 */
                __( '任务已停止：本次共处理 %d 个附件，可以随时再执行一次继续处理剩余部分。', 'local-crush' ),
                (int) $task['processed']
            );
        }

        return $summary;
    }

    /**
     * 立刻给自己发一个后台请求，继续跑下一棒
     *
     * @return bool 请求是否发出
     */
    private function spawn_async_tick(): bool {
        $task = $this->get_task();

        if ( ! $task || self::STATUS_RUNNING !== $task['status'] ) {
            return false;
        }

        $response = wp_remote_post(
            admin_url( 'admin-ajax.php' ),
            array(
                'timeout'     => 0.01,
                'blocking'    => false,
                'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
                'cookies'     => array(),
                'body'        => array(
                    'action' => self::ASYNC_ACTION,
                    'token'  => (string) $task['token'],
                ),
            )
        );

        return ! is_wp_error( $response );
    }

    /**
     * 排一次定时兜底事件
     *
     * WP 会把十分钟内参数相同的一次性事件当重复事件丢掉，所以每次带上递增的棒次。
     *
     * @param array|null $task 任务数据
     */
    private function schedule_watchdog( $task ): void {
        if ( ! $task || self::STATUS_RUNNING !== $task['status'] ) {
            return;
        }

        $tick = 0;

        $this->mutate(
            function ( array &$current ) use ( &$tick ) {
                $current['watchdog'] = (int) $current['watchdog'] + 1;
                $tick                = (int) $current['watchdog'];
            }
        );

        wp_schedule_single_event(
            time() + self::WATCHDOG_DELAY,
            self::WATCHDOG_HOOK,
            array( (string) $task['id'], $tick )
        );
    }

    /**
     * 写入任务状态
     *
     * @param array $task 任务数据
     */
    private function save( array $task ): void {
        if ( false === get_option( self::OPTION_NAME, false ) ) {
            add_option( self::OPTION_NAME, $task, '', false );

            return;
        }

        update_option( self::OPTION_NAME, $task, false );
    }

    /**
     * 在任务锁保护下读改写任务状态
     *
     * 多个请求可能同时想推进同一件事，锁只护住读写这一小段，
     * 真正的重活（压缩、删文件）都在锁外干，不会互相堵住。
     *
     * @param callable $callback 回调，第一个参数是任务数组（按引用传入）
     * @return mixed 回调的返回值；拿不到锁时返回 null
     */
    private function mutate( callable $callback ) {
        $lock = $this->acquire_lock();

        if ( false === $lock ) {
            return null;
        }

        try {
            // 别的进程可能刚改过，先把对象缓存丢掉，保证读到最新状态
            wp_cache_delete( self::OPTION_NAME, 'options' );

            $task = get_option( self::OPTION_NAME, array() );
            $task = is_array( $task ) ? $task : array();

            $value = $callback( $task );

            if ( ! empty( $task['type'] ) ) {
                $this->save( $task );
            }

            return $value;
        } finally {
            $this->release_lock( $lock );
        }
    }

    /**
     * 获取任务锁
     *
     * @return resource|false
     */
    private function acquire_lock() {
        // phpcs:disable WordPress.WP.AlternativeFunctions -- 任务锁必须用 fopen/flock/fclose，WP_Filesystem 不支持加锁。
        $lock_dir = $this->lock_dir();

        if ( ! is_dir( $lock_dir ) && ! wp_mkdir_p( $lock_dir ) ) {
            return false;
        }

        $handle = fopen( $lock_dir . '/task.lock', 'c' );

        if ( false === $handle ) {
            return false;
        }

        if ( ! flock( $handle, LOCK_EX ) ) {
            fclose( $handle );

            return false;
        }

        return $handle;
    }

    /**
     * 释放任务锁
     *
     * @param resource $lock 锁句柄
     */
    private function release_lock( $lock ): void {
        if ( ! is_resource( $lock ) ) {
            return;
        }

        flock( $lock, LOCK_UN );
        fclose( $lock );
    }
    // phpcs:enable

    /**
     * 锁目录，与压缩处理共用同一个目录
     *
     * @return string
     */
    private function lock_dir(): string {
        $upload_dir = wp_upload_dir();

        return $upload_dir['basedir'] . '/' . Local_Crush_Processor::LOCK_DIR_NAME;
    }
}
