<?php
/**
 * 统一图片压缩处理器
 *
 * @package LocalCrush
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 统一图片压缩处理器
 *
 * 根据设置为每个文件选择同格式压缩或目标格式输出，两种路径都写入同一
 * 压缩记录表，并统一提供批量、媒体库单项和上传自动处理入口。
 */
class Local_Crush_Processor {

    /**
     * 锁目录名称
     */
    const LOCK_DIR_NAME = '.local-crush-locks';

    /**
     * 待自动处理标记
     */
    const PENDING_META_KEY = '_local_crush_pending';

    /**
     * 单个请求在结束前最多处理的上传待办数量
     */
    const UPLOAD_QUEUE_LIMIT = 3;

    /**
     * 后台收尾任务单次最多处理的上传待办数量
     */
    const PENDING_SWEEP_LIMIT = 3;

    /**
     * 后台收尾任务最小间隔（秒）
     */
    const PENDING_SWEEP_INTERVAL = 60;

    /**
     * 后台收尾限流使用的 transient 名
     */
    const PENDING_SWEEP_TRANSIENT = 'local_crush_pending_sweep';

    /**
     * 后台收尾定时任务的钩子名
     */
    const PENDING_SWEEP_HOOK = 'local_crush_pending_sweep_event';

    /**
     * 恢复中间状态标记
     *
     * 原图已写回、其余文件已删除，但缩略图还没重新生成、正文链接还没还原时写入，
     * 让中断后的恢复可以从收尾阶段继续，而不是重复改动文件与引用。
     */
    const RESTORE_STATE_META_KEY = '_local_crush_restore_ready';

    /**
     * 原图备份到期清理任务的钩子名
     */
    const BACKUP_PRUNE_HOOK = 'local_crush_backup_prune_event';

    /**
     * 孤儿数据与压缩临时文件清理任务的钩子名
     */
    const CLEANUP_HOOK = 'local_crush_cleanup_event';

    /**
     * 临时文件/回滚副本保留时长：超过这个时间的才算垃圾
     */
    const TEMP_CLEANUP_AGE = DAY_IN_SECONDS;

    /**
     * 扫描临时文件的时间预算（秒），超时留到下次清理
     */
    const TEMP_SCAN_BUDGET = 10;

    /**
     * 备份到期清理单批处理的附件数量
     */
    const BACKUP_PRUNE_PAGE_SIZE = 20;

    /**
     * 单次定时任务最多清理的附件数量，剩余积压延后到补排的任务
     */
    const BACKUP_PRUNE_LIMIT = 200;

    /**
     * 是否暂停自动压缩
     *
     * @var bool
     */
    private $auto_compress_suppressed = false;

    /**
     * 本次请求登记的待处理附件
     *
     * @var array
     */
    private $queued_uploads = array();

    /**
     * 最近一次加锁失败的原因
     *
     * busy       = 锁被其他进程占用，稍后重试
     * unavailable = 锁文件无法创建，重试也不会成功
     *
     * @var string
     */
    private $lock_error = '';

    /**
     * 构造函数
     */
    public function __construct() {
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'auto_compress_on_upload' ), 10, 3 );
        add_action( 'add_attachment', array( $this, 'queue_upload_for_compression' ) );
        add_action( 'delete_attachment', array( $this, 'handle_attachment_deleted' ) );
        add_action( 'shutdown', array( $this, 'process_queued_uploads' ), 1 );
        add_action( 'admin_init', array( $this, 'process_pending_uploads' ), 20 );
        add_action( self::PENDING_SWEEP_HOOK, array( $this, 'run_pending_sweep' ) );
        add_action( self::BACKUP_PRUNE_HOOK, array( $this, 'prune_expired_backups' ) );
        add_action( self::CLEANUP_HOOK, array( $this, 'run_cleanup' ) );
    }

    /**
     * 获取支持的图片 MIME
     *
     * @return array
     */
    public function get_supported_mimes(): array {
        return array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/jxl', 'image/gif', 'image/svg+xml' );
    }

    /**
     * 验证附件是否存在且为支持的图片
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function is_valid_attachment( int $attachment_id ): bool {
        if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
            return false;
        }

        return in_array( get_post_mime_type( $attachment_id ), $this->get_supported_mimes(), true );
    }

    /**
     * 暂停或恢复自动压缩
     *
     * @param bool $suppressed 是否暂停
     */
    public function set_auto_compress_suppressed( bool $suppressed ): void {
        $this->auto_compress_suppressed = $suppressed;
    }

    /**
     * 自动压缩当前是否被暂停
     *
     * @return bool
     */
    public function is_auto_compress_suppressed(): bool {
        return $this->auto_compress_suppressed;
    }

    /**
     * 获取附件统一压缩状态
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function get_attachment_state( int $attachment_id ): array {
        $files = $this->get_processing_files( $attachment_id );
        $state = array(
            'status'               => 'empty',
            'total_files'          => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'pending_count'        => 0,
            'skipped_count'        => 0,
            'missing_count'        => 0,
            'total_original_size'  => 0,
            'total_compressed_size' => 0,
            'total_ratio'          => 0,
        );

        $counted_paths = array(); // 共用文件的字节只计一次，否则合计虚高

        foreach ( $files as $file ) {
            // 元数据声明但磁盘上已不存在的尺寸不是待处理项：重试多少次都不会变，
            // 计入 pending 会让整个附件永远显示"待压缩"并被反复重试。
            // 符号链接与 uploads 外的路径同样按缺失处理，绝不去碰。
            if ( ! $this->is_safe_processing_path( $file['file_path'] ) ) {
                $state['missing_count']++;
                continue;
            }

            $state['total_files']++;

            $record_size_type = isset( $file['record_size_type'] ) ? $file['record_size_type'] : $file['size_type'];
            $record            = local_crush()->database->get_record( $attachment_id, $record_size_type );
            $complete = $this->is_complete_record( $file['file_path'], $record );

            if ( $complete ) {
                $state['success_count']++;

                // 与媒体详情页同一口径：附件包含的所有文件都参与比例，旧兼容格式回退文件也一起算，
                // 节省合计 ÷ 压缩前合计。原图备份不是被压缩的对象，不进这项统计。
                // 宽高相同的两个尺寸共用同一份文件时字节只按一份计。
                $path_key = $this->normalized_path( (string) $file['file_path'] );

                if ( isset( $counted_paths[ $path_key ] ) ) {
                    continue;
                }

                $counted_paths[ $path_key ] = true;

                $original_size = max( 0, (int) $record['original_size'] );

                if ( 0 === $original_size ) {
                    $original_size = (int) filesize( $file['file_path'] );
                }

                $state['total_original_size']   += $original_size;
                $state['total_compressed_size'] += min( $original_size, max( 0, (int) $record['compressed_size'] ) );
            } elseif ( $record && 'failed' === $record['status'] ) {
                $state['failed_count']++;
            } elseif ( $record && 'skipped' === $record['status'] ) {
                // 主动跳过（如 APNG 动图、缺工具、超过大小限制）已记录在案，
                // 不该每轮批量都当成待处理重新尝试。
                $state['skipped_count']++;
            } else {
                $state['pending_count']++;
            }
        }

        if ( $state['total_files'] > 0 && $state['success_count'] === $state['total_files'] ) {
            $state['status'] = 'complete';
        } elseif ( $state['success_count'] > 0 ) {
            $state['status'] = 'partial';
        } elseif ( $state['failed_count'] > 0 ) {
            $state['status'] = 'failed';
        } elseif ( $state['pending_count'] > 0 ) {
            $state['status'] = 'pending';
        } elseif ( $state['skipped_count'] > 0 ) {
            // 文件都存在，只是被主动跳过（如 APNG 保护、缺工具），不是“没有文件”。
            $state['status'] = 'skipped';
        }

        if ( $state['total_original_size'] > 0 ) {
            $state['total_ratio'] = round(
                ( 1 - $state['total_compressed_size'] / $state['total_original_size'] ) * 100,
                2
            );
        }

        return $state;
    }

    /**
     * 获取附件本次压缩需要关注的所有文件。
     *
     * 兼容格式不写进 WordPress 附件元数据，因此单独从转换映射补进来。
     * 记录名称加 fallback_ 前缀，避免覆盖新格式同尺寸的压缩记录。
     *
     * @param int        $attachment_id 附件 ID
     * @param array|null $metadata       附件元数据
     * @return array
     */
    private function get_processing_files( int $attachment_id, ?array $metadata = null ): array {
        $files = local_crush()->compressor->get_attachment_files( $attachment_id, $metadata );

        foreach ( $files as $index => $file ) {
            $files[ $index ]['is_fallback']      = false;
            $files[ $index ]['record_size_type'] = $file['size_type'];
        }

        foreach ( local_crush()->output_processor->get_fallback_entries( $attachment_id ) as $entry ) {
            $size_type = isset( $entry['size_type'] ) ? (string) $entry['size_type'] : 'full';

            $files[] = array(
                'size_type'        => $size_type,
                'record_size_type' => local_crush()->output_processor->get_fallback_record_size_type( $size_type ),
                'file_path'        => $entry['from'],
                'is_fallback'      => true,
                'fallback_to'      => $entry['to'],
            );
        }

        return $files;
    }

    /**
     * 获取媒体库详情所需的文件明细和总占用空间。
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function get_attachment_details( int $attachment_id ): array {
        if ( ! $this->is_valid_attachment( $attachment_id ) ) {
            return array();
        }

        $metadata       = wp_get_attachment_metadata( $attachment_id );
        $metadata       = is_array( $metadata ) ? $metadata : array();
        $files          = $this->get_processing_files( $attachment_id, $metadata );
        $items          = array();
        $total_size     = 0;
        $seen_paths     = array();

        foreach ( $files as $file ) {
            $path              = isset( $file['file_path'] ) ? (string) $file['file_path'] : '';
            $exists            = '' !== $path && $this->is_safe_processing_path( $path );
            $current_size      = $exists ? (int) filesize( $path ) : 0;
            $record_size_type  = isset( $file['record_size_type'] ) ? (string) $file['record_size_type'] : (string) $file['size_type'];
            $record            = local_crush()->database->get_record( $attachment_id, $record_size_type );
            $complete          = $exists && $this->is_complete_record( $path, $record );
            $status            = $this->detail_file_status( $exists, $record, $complete );
            $record_original   = is_array( $record ) ? max( 0, (int) $record['original_size'] ) : 0;
            $record_compressed = is_array( $record ) ? max( 0, (int) $record['compressed_size'] ) : 0;
            $item_original     = $record_original > 0 ? $record_original : $current_size;
            $item_current      = $current_size;
            $item_saved        = $complete ? max( 0, $item_original - $record_compressed ) : 0;
            $size_type         = isset( $file['size_type'] ) ? (string) $file['size_type'] : 'full';
            $is_fallback       = ! empty( $file['is_fallback'] );

            if ( $exists ) {
                $normalized_path = wp_normalize_path( $path );
                if ( ! isset( $seen_paths[ $normalized_path ] ) ) {
                    $seen_paths[ $normalized_path ] = true;
                    $total_size += $current_size;
                }
            }

            $items[] = array(
                'group'         => $is_fallback ? __( '旧兼容格式', 'local-crush' ) : __( '当前文件', 'local-crush' ),
                'name'          => $this->detail_size_label( $size_type, $metadata ),
                'size_type'     => $size_type,
                'format'        => $this->detail_file_format( $path ),
                'status'        => $status,
                'original_size' => $item_original,
                'current_size'  => $item_current,
                'saved_size'    => $item_saved,
                'file_name'     => '' !== $path ? wp_basename( $path ) : '',
                'is_fallback'   => $is_fallback,
            );
        }

        foreach ( local_crush()->backup->get_backup_info( $attachment_id ) as $backup ) {
            $backup_path  = isset( $backup['backup_path'] ) ? (string) $backup['backup_path'] : '';
            $backup_size  = isset( $backup['backup_size'] ) ? max( 0, (int) $backup['backup_size'] ) : 0;
            $backup_exists = $backup_size > 0 && is_file( $backup_path );

            if ( $backup_exists ) {
                $normalized_path = wp_normalize_path( $backup_path );
                if ( ! isset( $seen_paths[ $normalized_path ] ) ) {
                    $seen_paths[ $normalized_path ] = true;
                    $total_size += $backup_size;
                }
            }

            $items[] = array(
                'group'         => __( '原图备份', 'local-crush' ),
                'name'          => __( '备份文件', 'local-crush' ),
                'size_type'     => 'backup',
                'format'        => $this->detail_file_format( $backup_path ),
                'status'        => $backup_exists ? __( '备份中', 'local-crush' ) : __( '文件不存在', 'local-crush' ),
                'original_size' => $backup_size,
                'current_size'  => $backup_size,
                'saved_size'    => 0,
                'file_name'     => '' !== $backup_path ? wp_basename( $backup_path ) : '',
                'is_fallback'   => false,
            );
        }

        return array(
            'attachment_id' => $attachment_id,
            'total_size'    => $total_size,
            'items'         => $items,
        );
    }

    /**
     * 详情里的文件状态。
     *
     * @param bool       $exists   文件是否存在
     * @param array|null $record   压缩记录
     * @param bool       $complete 记录是否匹配当前文件
     * @return string
     */
    private function detail_file_status( bool $exists, $record, bool $complete ): string {
        if ( ! $exists ) {
            return __( '文件不存在', 'local-crush' );
        }

        if ( $complete ) {
            return __( '已压缩', 'local-crush' );
        }

        if ( is_array( $record ) && 'failed' === (string) $record['status'] ) {
            return __( '压缩失败', 'local-crush' );
        }

        if ( is_array( $record ) && 'skipped' === (string) $record['status'] ) {
            return __( '已跳过', 'local-crush' );
        }

        return __( '未压缩', 'local-crush' );
    }

    /**
     * 详情里的尺寸名称，缩略图逐尺寸显示宽高。
     *
     * @param string $size_type 尺寸类型
     * @param array  $metadata  附件元数据
     * @return string
     */
    private function detail_size_label( string $size_type, array $metadata ): string {
        if ( 'full' === $size_type ) {
            return __( '主图', 'local-crush' );
        }

        if ( 'original_image' === $size_type ) {
            return __( '未缩放原图', 'local-crush' );
        }

        $size = isset( $metadata['sizes'][ $size_type ] ) && is_array( $metadata['sizes'][ $size_type ] )
            ? $metadata['sizes'][ $size_type ]
            : array();
        $width  = isset( $size['width'] ) ? absint( $size['width'] ) : 0;
        $height = isset( $size['height'] ) ? absint( $size['height'] ) : 0;

        if ( $width > 0 && $height > 0 ) {
            return sprintf(
                /* translators: 1: 缩略图尺寸名, 2: 宽度, 3: 高度 */
                __( '缩略图 %1$s（%2$d × %3$d）', 'local-crush' ),
                $size_type,
                $width,
                $height
            );
        }

        return sprintf(
            /* translators: %s: 缩略图尺寸名 */
            __( '缩略图 %s', 'local-crush' ),
            $size_type
        );
    }

    /**
     * 详情里的文件格式。
     *
     * @param string $path 文件路径
     * @return string
     */
    private function detail_file_format( string $path ): string {
        $extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

        return '' === $extension ? __( '未知', 'local-crush' ) : strtoupper( $extension );
    }

    /**
     * 判断附件是否还有未压缩文件
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function has_pending_files( int $attachment_id ): bool {
        if ( ! $this->is_valid_attachment( $attachment_id ) ) {
            return false;
        }

        $state = $this->get_attachment_state( $attachment_id );

        // 跳过也纳入批量：用户装好工具/改完设置后，点一次批量就能重试，
        // 不必逐张手点。批量任务本身每个附件只处理一次，不会在单个任务里死循环。
        return $state['pending_count'] > 0 || $state['failed_count'] > 0 || $state['skipped_count'] > 0;
    }

    /**
     * 统一压缩一个附件
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function compress_attachment( int $attachment_id ): array {
        if ( ! $this->is_valid_attachment( $attachment_id ) ) {
            return $this->empty_result( __( '无效的图片附件', 'local-crush' ), 'failed' );
        }

        $lock = $this->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return $this->empty_result( $this->get_lock_error_message(), 'skipped', 'busy' === $this->lock_error );
        }

        $global_lock = $this->acquire_global_lock( false );
        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            return $this->empty_result( $this->get_lock_error_message( true ), 'skipped', 'busy' === $this->lock_error );
        }

        try {
            $metadata      = wp_get_attachment_metadata( $attachment_id );
            $metadata      = is_array( $metadata ) ? $metadata : array();
            $drop_unscaled = $this->should_drop_unscaled_original( $metadata );
            $files         = $this->get_processing_files( $attachment_id, empty( $metadata ) ? null : $metadata );

            if ( $drop_unscaled ) {
                // 这份文件压缩完就要删掉，压它纯属白费功夫；它的旧格式回退文件同样排除。
                // 但如果除了它一个文件都不剩（例如 -scaled 主图已经不在了），就别排除，
                // 否则这张图会彻底没法处理。
                $remaining = $this->without_unscaled_original( $files );
                $files     = empty( $remaining ) ? $files : $remaining;
            }

            if ( empty( $files ) ) {
                return $this->empty_result( __( '未找到可压缩的图片文件', 'local-crush' ), 'failed' );
            }

            $pending = $this->collect_pending_files( $attachment_id, $files );

            // 只对待压缩的图片生效：已全部压缩的附件在这里直接返回，
            // 不会对同一张图反复处理，也不会动已压缩文件的大小。
            if ( empty( $pending ) ) {
                $has_safe_file = false;

                foreach ( $files as $candidate ) {
                    if ( $this->is_safe_processing_path( (string) $candidate['file_path'] ) ) {
                        $has_safe_file = true;
                        break;
                    }
                }

                // 文件都已压缩，但以前留下的未缩放原图还占着磁盘：这次压缩同样按开关清掉。
                if ( $drop_unscaled && $this->delete_unscaled_original( $attachment_id ) ) {
                    return $this->empty_result( __( '图片已经压缩，已删除未缩放的原始大图', 'local-crush' ), 'skipped' );
                }

                return $this->empty_result(
                    $has_safe_file ? __( '图片已经压缩', 'local-crush' ) : __( '没有可处理的图片文件', 'local-crush' ),
                    'skipped'
                );
            }

            // 只要附件已有一个成功记录，后续补压缩就只能保持当前格式，
            // 避免重新生成缩略图时把旧格式再次转换成目标格式。
            $allow_target_conversion = true;
            foreach ( $files as $candidate ) {
                $candidate_record_type = isset( $candidate['record_size_type'] ) ? $candidate['record_size_type'] : $candidate['size_type'];
                $candidate_record      = local_crush()->database->get_record( $attachment_id, $candidate_record_type );

                if ( is_file( $candidate['file_path'] ) && $this->is_complete_record( $candidate['file_path'], $candidate_record ) ) {
                    $allow_target_conversion = false;
                    break;
                }
            }

            // 智能备份：压缩前只备份一份主图像（未缩放原图优先），恢复时据此重建缩略图。
            if ( ! $this->backup_original_if_needed( $attachment_id, $metadata ) ) {
                return $this->empty_result( __( '无法创建原图备份，已停止压缩', 'local-crush' ), 'failed' );
            }

            $output_settings = local_crush()->output_processor->get_output_settings();
            $target          = $output_settings['target'];
            $output_map   = array();
            $result          = array(
                'status'      => 'success',
                'message'     => '',
                'busy'        => false,
                'total'       => count( $pending ),
                'success'     => 0,
                'failed'      => 0,
                'skipped'     => 0,
                'saved_bytes' => 0,
                'details'     => array(),
            );

            $handled = array(); // 本轮已成功处理的文件：路径 => 结果与记录键，供共用尺寸复制

            foreach ( $pending as $file ) {
                do_action( 'local_crush_before_compress', $attachment_id, $file['file_path'], $file['size_type'] );

                $path_key     = $this->normalized_path( (string) $file['file_path'] );
                $will_convert = empty( $file['is_fallback'] )
                    && $allow_target_conversion
                    && local_crush()->output_processor->should_output_target( $file['file_path'] );

                if ( isset( $handled[ $path_key ] ) ) {
                    // 宽高相同的两个尺寸（如 large 与 medium_large）共用同一份文件：
                    // 干活按文件只干一次，第二个尺寸复制第一份结果，不对同一张
                    // 文件重复压缩或编码；名册里每个尺寸仍各有一份完整记录。
                    $file_result = $this->replicate_handled_result( $attachment_id, $file, $handled[ $path_key ] );

                    if ( null === $file_result ) {
                        // 复制前提不成立（映射或记录对不上），按独立文件正常处理。
                        $file_result = $will_convert
                            ? local_crush()->output_processor->compress_to_target_format(
                                $attachment_id,
                                $file['file_path'],
                                $file['size_type'],
                                $target
                            )
                            : local_crush()->compressor->compress_file(
                                $attachment_id,
                                $file['file_path'],
                                $file['size_type']
                            );
                    }
                } elseif ( $will_convert ) {
                    $file_result = local_crush()->output_processor->compress_to_target_format(
                        $attachment_id,
                        $file['file_path'],
                        $file['size_type'],
                        $target
                    );
                } else {
                    $file_result = local_crush()->compressor->compress_file(
                        $attachment_id,
                        $file['file_path'],
                        $file['size_type']
                    );
                }

                $file_result['size_type'] = $file['size_type'];
                $result['details'][]      = array_merge( $file, $file_result );

                if ( 'success' === $file_result['status'] ) {
                    if ( ! isset( $handled[ $path_key ] ) ) {
                        $handled[ $path_key ] = array(
                            'result'           => $file_result,
                            'record_size_type' => isset( $file['record_size_type'] ) ? (string) $file['record_size_type'] : (string) $file['size_type'],
                        );
                    }

                    $result['success']++;
                    $result['saved_bytes'] += max(
                        0,
                        (int) $file_result['original_size'] - (int) $file_result['compressed_size']
                    );

                    if ( ! empty( $file_result['to'] ) ) {
                        $output_map[ $file['size_type'] ] = array(
                            'from' => $file_result['from'],
                            'to'   => $file_result['to'],
                        );
                    }

                    do_action( 'local_crush_after_compress', $attachment_id, $file['file_path'], $file['size_type'], $file_result );
                } elseif ( 'failed' === $file_result['status'] ) {
                    $result['failed']++;
                } else {
                    $result['skipped']++;
                }
            }

            // 引用、文章链接与源文件清理必须一起提交成功，否则退回原文件引用等待重试。
            if ( ! empty( $output_map ) && ! local_crush()->output_processor->commit_attachment_format( $attachment_id, $output_map ) ) {
                foreach ( $result['details'] as $index => $detail ) {
                    if ( empty( $detail['to'] ) || ! isset( $output_map[ $detail['size_type'] ] ) ) {
                        continue;
                    }

                    $result['details'][ $index ]['status']  = 'failed';
                    $result['details'][ $index ]['message'] = __( '附件引用更新失败，已退回原文件', 'local-crush' );
                    $result['saved_bytes']                 -= max( 0, (int) $detail['original_size'] - (int) $detail['compressed_size'] );
                    $result['saved_bytes']                  = max( 0, $result['saved_bytes'] );
                    $result['success']--;
                    $result['failed']++;
                }

                $result['status']  = 'failed';
                $result['message'] = __( '压缩结果已生成，但附件引用或文章链接更新失败，图片仍指向原文件，可重新压缩继续提交', 'local-crush' );

                return $result;
            }

            $this->refresh_metadata_file_sizes( $attachment_id );

            // 主图（-scaled 缩放图）确实压好了才清理原始大图：主图一张都没压成时先留着，
            // 等用户把工具配好重来，别在什么都没做成的时候先动刀。
            if ( $drop_unscaled && $this->is_main_file_compressed( $attachment_id ) ) {
                $this->delete_unscaled_original( $attachment_id );
            }

            if ( empty( $result['message'] ) ) {
                if ( $result['success'] === $result['total'] ) {
                    $result['status']  = 'success';
                    $result['message'] = __( '图片压缩完成', 'local-crush' );
                } elseif ( $result['success'] > 0 ) {
                    $result['status']  = 'partial';
                    $result['message'] = __( '图片仅部分压缩完成，仍有文件需要重试', 'local-crush' );
                } elseif ( $result['failed'] > 0 ) {
                    $result['status']  = 'failed';
                    $result['message'] = __( '图片压缩失败', 'local-crush' );
                } else {
                    $result['status']  = 'skipped';
                    $result['message'] = __( '没有可用的压缩结果', 'local-crush' );
                }
            }

            return $result;
        } finally {
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 收集本次需要压缩的文件
     *
     * @param int   $attachment_id 附件 ID
     * @param array $files         候选文件列表
     * @return array 待压缩文件列表
     */
    private function collect_pending_files( int $attachment_id, array $files ): array {
        $pending = array();

        foreach ( $files as $file ) {
            // 不在 uploads 内或是符号链接的文件永远处理不了，不放进待办队列，
            // 否则每一轮批量都会重试同一张图却永远没有结果。
            if ( ! $this->is_safe_processing_path( (string) $file['file_path'] ) ) {
                continue;
            }

            $record_size_type = isset( $file['record_size_type'] ) ? $file['record_size_type'] : $file['size_type'];
            $record            = local_crush()->database->get_record( $attachment_id, $record_size_type );

            if ( ! $this->is_complete_record( $file['file_path'], $record ) ) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    /**
     * 把本轮已处理完的文件结果复制到共用它的另一个尺寸名下
     *
     * 宽高相同的两个尺寸（如 large 与 medium_large）共用同一份文件：干活按
     * 文件只干一次，第二份记录是第一份的复制品——这样名册里每个尺寸仍各有
     * 一份完整记录，状态、明细与删除清理都按既有口径工作。
     *
     * 转换结果通过转换器的共用逻辑认领（映射、记录与回退记录一并补齐）；
     * 同格式压缩结果只复制压缩记录。前提对不上时返回 null，交回调用方把
     * 该尺寸当独立文件正常处理。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $file          待处理条目（含 size_type 与 record_size_type）
     * @param array $handled       已成功处理的结果与记录键
     * @return array|null 复制出的处理结果
     */
    private function replicate_handled_result( int $attachment_id, array $file, array $handled ): ?array {
        $result     = $handled['result'];
        $from_type  = (string) $handled['record_size_type'];
        $to_type    = isset( $file['record_size_type'] ) ? (string) $file['record_size_type'] : (string) $file['size_type'];
        $size_type  = (string) $file['size_type'];

        if ( '' === $from_type || '' === $to_type || $from_type === $to_type ) {
            return null;
        }

        $owner_record = local_crush()->database->get_record( $attachment_id, $from_type );

        if ( ! is_array( $owner_record ) || 'success' !== (string) $owner_record['status'] ) {
            return null;
        }

        if ( ! empty( $result['to'] ) ) {
            return local_crush()->output_processor->adopt_shared_output(
                $attachment_id,
                (string) $file['file_path'],
                $size_type,
                (string) $result['to'],
                max( 0, (int) $owner_record['original_size'] ),
                (string) ( isset( $result['tool_name'] ) ? $result['tool_name'] : '' )
            );
        }

        // 同格式压缩结果：只把压缩记录复制到本尺寸名下。
        if ( ! local_crush()->compressor->save_compression_record(
            $attachment_id,
            (string) $owner_record['file_path'],
            $to_type,
            max( 0, (int) $owner_record['original_size'] ),
            max( 0, (int) $owner_record['compressed_size'] ),
            (string) ( isset( $owner_record['tool_name'] ) ? $owner_record['tool_name'] : '' ),
            'success',
            ''
        ) ) {
            return array(
                'file_path'       => (string) $file['file_path'],
                'from'            => (string) $file['file_path'],
                'to'              => '',
                'status'          => 'failed',
                'message'         => __( '压缩记录保存失败，请重新压缩', 'local-crush' ),
                'original_size'   => max( 0, (int) $owner_record['compressed_size'] ),
                'compressed_size' => 0,
            );
        }

        return array(
            'file_path'       => (string) $file['file_path'],
            'from'            => (string) $file['file_path'],
            'to'              => '',
            'size_type'       => $size_type,
            'status'          => 'success',
            'message'         => __( '该尺寸与另一尺寸共用同一份压缩结果，未重复处理', 'local-crush' ),
            // 没有新工作发生，本次运行不重复计入节省字节数。
            'original_size'   => max( 0, (int) $owner_record['compressed_size'] ),
            'compressed_size' => max( 0, (int) $owner_record['compressed_size'] ),
            'tool_name'       => (string) ( isset( $owner_record['tool_name'] ) ? $owner_record['tool_name'] : '' ),
        );
    }

    /**
     * 压缩前备份一份主图像
     *
     * 智能备份只存原图（有 original_image 时是未缩放源，否则是主文件），
     * 不再逐张备份缩略图；关闭备份或找不到原图时不阻断压缩。
     *
     * @param int   $attachment_id 附件 ID
     * @param mixed $metadata      附件元数据
     * @return bool 是否允许继续压缩
     */
    private function backup_original_if_needed( int $attachment_id, $metadata ): bool {
        $settings       = get_option( 'local_crush_general', array() );
        $backup_enabled = isset( $settings['backup_enabled'] ) ? (bool) $settings['backup_enabled'] : true;

        if ( ! $backup_enabled ) {
            return true;
        }

        $original_path = $this->determine_original_image_path( $attachment_id, $metadata );

        if ( '' === $original_path || ! is_file( $original_path ) ) {
            return true;
        }

        // 主文件已经是格式转换产物时不再备份：它的原图在第一轮压缩时就存过了，
        // 把 photo.jpg.webp 这类结果存进备份目录，只会多出一个被误当原图的压缩文件。
        if ( local_crush()->output_processor->is_conversion_output( $attachment_id, $original_path ) ) {
            return true;
        }

        return local_crush()->backup->create_backup( $attachment_id, $original_path );
    }

    /**
     * 确定附件的主图像路径
     *
     * 有 original_image 时主文件是缩放产物，未缩放源才是真正的主图像；否则主文件即原图。
     * SVG 这类附件 WordPress 不写 file 元数据，改用附件登记路径取主图像。
     *
     * @param int   $attachment_id 附件 ID
     * @param mixed $metadata      附件元数据
     * @return string 主图像绝对路径，找不到时返回空字符串
     */
    private function determine_original_image_path( int $attachment_id, $metadata ): string {
        $upload_dir = wp_upload_dir();
        $base_dir   = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
        $main_rel   = is_array( $metadata ) && ! empty( $metadata['file'] )
            ? ltrim( wp_normalize_path( (string) $metadata['file'] ), '/' )
            : '';

        if ( '' !== $main_rel && ! empty( $metadata['original_image'] ) ) {
            $dir_rel  = dirname( $main_rel );
            $orig_rel = ( '.' === $dir_rel ? '' : $dir_rel . '/' ) . wp_basename( (string) $metadata['original_image'] );
            $orig_abs = $base_dir . '/' . ltrim( wp_normalize_path( $orig_rel ), '/' );

            if ( is_file( $orig_abs ) && $this->is_inside_upload_dir( $orig_abs ) ) {
                return $orig_abs;
            }
        }

        // 主文件本身就是 -scaled 缩放图，却已经没有 original_image 记录：未缩放原图不是
        // 被清理掉了就是丢了，这时把缩放图当原图存进备份目录，只会让“恢复原图”恢复出
        // 一张本来就是压缩、缩放过的图，白占一份磁盘。
        if ( '' !== $main_rel && empty( $metadata['original_image'] )
            && preg_match( '/-scaled\.[^\/]+$/i', wp_basename( $main_rel ) ) ) {
            return '';
        }

        if ( '' !== $main_rel ) {
            $main_path = $base_dir . '/' . $main_rel;

            return is_file( $main_path ) && $this->is_inside_upload_dir( $main_path ) ? $main_path : '';
        }

        // get_attached_file() 已把 uploads 相对路径补成绝对路径。
        $attached = get_attached_file( $attachment_id );

        if ( ! is_string( $attached ) || '' === $attached ) {
            return '';
        }

        $attached_path = wp_normalize_path( $attached );

        return is_file( $attached_path ) && $this->is_inside_upload_dir( $attached_path ) ? $attached_path : '';
    }

    /**
     * 元数据里是否记着未缩放的原始大图
     *
     * original_image 非空就说明这张图上传时超过阈值、另存过 -scaled 缩放图，
     * 未缩放的那份仍留在上传目录里。
     *
     * @param array $metadata 附件元数据
     * @return bool
     */
    private function has_unscaled_original( array $metadata ): bool {
        return ! empty( $metadata['file'] ) && ! empty( $metadata['original_image'] );
    }

    /**
     * 本次压缩后是否要删掉未缩放的原始大图
     *
     * 三个条件缺一不可：设置里开着“压缩后删除未缩放的原始大图”、开着自动缩放、
     * 元数据里确实有 original_image 记录（说明原图超阈值生成了 -scaled 缩放图）。
     * 关闭自动缩放时根本不会生成 -scaled 图，这个开关也就没有意义。
     *
     * @param array $metadata 附件元数据
     * @return bool
     */
    private function should_drop_unscaled_original( array $metadata ): bool {
        if ( ! Local_Crush_Settings::delete_unscaled_original() || ! Local_Crush_Settings::image_scale_enabled() ) {
            return false;
        }

        return $this->has_unscaled_original( $metadata );
    }

    /**
     * 主图（-scaled 缩放图）是否已经压缩完毕
     *
     * 只看附件当前主文件的压缩记录：转换过格式时记录落在新格式文件上，
     * 元数据同步后主文件也指向它，两者必须能对上。
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    private function is_main_file_compressed( int $attachment_id ): bool {
        $main_path = get_attached_file( $attachment_id );

        if ( ! is_string( $main_path ) || '' === $main_path ) {
            return false;
        }

        return $this->is_complete_record(
            wp_normalize_path( $main_path ),
            local_crush()->database->get_record( $attachment_id, 'full' )
        );
    }

    /**
     * 从待处理文件里去掉未缩放原图
     *
     * 未缩放原图压缩完就要删掉，压缩它没有意义；它的旧格式回退文件同属这份原图，
     * 一并排除，免得为一份马上消失的文件生成转换结果和映射。
     *
     * @param array $files 文件列表
     * @return array
     */
    private function without_unscaled_original( array $files ): array {
        $kept = array();

        foreach ( $files as $file ) {
            if ( 'original_image' === (string) $file['size_type'] ) {
                continue;
            }

            $kept[] = $file;
        }

        return $kept;
    }

    /**
     * 删除未缩放的原始大图，只留 -scaled 缩放图当主图
     *
     * 只在同时满足这些条件时才动手：
     * 1. 设置里开启“压缩后删除未缩放的原始大图”，且开启自动缩放；
     * 2. 元数据里有 original_image，说明确实生成过 -scaled 缩放图；
     * 3. -scaled 主图真实存在、非空、不是符号链接且在 uploads 内 —— 原图删掉后就只剩它了；
     * 4. 未缩放原图与主图不是同一个文件，且是 uploads 内的普通文件。
     *
     * 删除成功后清掉元数据里的 original_image，并顺手清掉指向这份原图的转换映射、
     * 旧格式回退文件与压缩记录，不留任何指向已删文件的痕迹。原图早就不在磁盘上时
     * 只清理这些残留指向，不动主图。
     *
     * @param int  $attachment_id   附件 ID
     * @param bool $ignore_settings 为 true 时只看数据、不看两个设置开关（批量按钮用）
     * @return int 实际删掉的文件数（原图 + 它的旧格式回退），没删任何文件时为 0
     */
    private function delete_unscaled_original( int $attachment_id, bool $ignore_settings = false ): int {
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) ) {
            return 0;
        }

        $allowed = $ignore_settings
            ? $this->has_unscaled_original( $metadata )
            : $this->should_drop_unscaled_original( $metadata );

        if ( ! $allowed ) {
            return 0;
        }

        $base_dir = untrailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) );
        $main_rel = ltrim( wp_normalize_path( (string) $metadata['file'] ), '/' );
        $file_dir = dirname( $main_rel );
        $orig_rel = ( '.' === $file_dir ? '' : $file_dir . '/' ) . wp_basename( (string) $metadata['original_image'] );
        $main_abs = $base_dir . '/' . $main_rel;
        $orig_abs = $base_dir . '/' . ltrim( wp_normalize_path( $orig_rel ), '/' );

        // 元数据属于不可信数据，拼出来的路径先按字面排掉回溯写法与多余层级。
        if ( '' === $main_rel || '' === wp_basename( $orig_rel )
            || false !== strpos( $orig_rel, '..' ) || false !== strpos( $orig_rel, ':' ) ) {
            return 0;
        }

        // 主图必须真实可用：原图删掉后媒体库只剩它撑门面，它有问题就一个都不能动。
        if ( $this->normalized_path( $main_abs ) === $this->normalized_path( $orig_abs )
            || ! $this->is_safe_processing_path( $main_abs ) ) {
            return 0;
        }

        clearstatcache( true, $main_abs );

        if ( (int) filesize( $main_abs ) <= 0 ) {
            return 0;
        }

        // 未缩放原图必须是 uploads 内的普通文件，符号链接一律不碰。
        if ( is_link( $orig_abs ) ) {
            return 0;
        }

        $had_original = is_file( $orig_abs );

        if ( $had_original ) {
            if ( ! $this->is_inside_upload_dir( $orig_abs ) ) {
                return 0;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- 已确认是 uploads 内的普通文件，不会删到目录外或符号链接目标。
            if ( ! unlink( $orig_abs ) || file_exists( $orig_abs ) ) {
                // 删不掉就保持现状，下次压缩还能再试，不能留下“文件还在、记录没了”的状态。
                return 0;
            }
        }

        $deleted = $had_original ? 1 : 0;
        $deleted += $this->forget_unscaled_original_traces( $attachment_id, $orig_abs );

        unset( $metadata['original_image'] );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        return $deleted;
    }

    /**
     * 清掉指向未缩放原图的转换映射、旧格式回退文件与压缩记录
     *
     * 转换过的附件里，未缩放原图有新旧两份文件：新格式那份就是元数据里的 original_image，
     * 旧格式那份是它的兼容格式回退。前者已由调用方删除，后者同样没人引用，跟着删掉，
     * 再把映射、回退记录和对应压缩记录一并清空。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $original_path 已删除的未缩放原图绝对路径
     * @return int 跟着删掉的旧格式回退文件数
     */
    private function forget_unscaled_original_traces( int $attachment_id, string $original_path ): int {
        $deleted = 0;

        foreach ( local_crush()->output_processor->get_output_entries( $attachment_id ) as $entry ) {
            if ( 'original_image' !== (string) $entry['size_type'] ) {
                continue;
            }

            $fallback_file = wp_normalize_path( (string) $entry['from'] );

            if ( $this->normalized_path( $fallback_file ) !== $this->normalized_path( $original_path )
                && is_file( $fallback_file ) && ! is_link( $fallback_file ) && $this->is_inside_upload_dir( $fallback_file ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- 已确认是 uploads 内的普通文件，不会删到目录外或符号链接目标。
                if ( unlink( $fallback_file ) ) {
                    $deleted++;
                }
            }

            local_crush()->output_processor->remove_output_entry( $attachment_id, 'original_image' );
            local_crush()->output_processor->forget_fallback_records( $attachment_id, array( 'original_image' ) );
        }

        $record = local_crush()->database->get_record( $attachment_id, 'original_image' );

        if ( is_array( $record ) && ! empty( $record['id'] ) ) {
            local_crush()->database->delete_record( (int) $record['id'] );
        }

        return $deleted;
    }

    /**
     * 用 WordPress 图片编辑器生成 -scaled 缩放文件
     *
     * 与核心上传流程一致：resize 到阈值内、按 EXIF 方向旋转、另存为 xxx-scaled 文件。
     * 目标必须与源文件不同，否则会把未缩放源文件直接覆盖掉。
     *
     * @param string $source_path 未缩放的源文件绝对路径
     * @param int    $threshold   阈值（像素）
     * @return array|null 图片编辑器保存结果；失败或目标与源相同时返回 null
     */
    public function generate_scaled_file( string $source_path, int $threshold ): ?array {
        $editor = wp_get_image_editor( $source_path );

        if ( is_wp_error( $editor ) ) {
            return null;
        }

        $resized = $editor->resize( $threshold, $threshold );

        if ( is_wp_error( $resized ) ) {
            return null;
        }

        // 与核心一致：取源图的 EXIF Orientation，避免缩放出来的图方向不对。
        $rotated = $editor->maybe_exif_rotate();

        if ( is_wp_error( $rotated ) ) {
            return null;
        }

        $target = $editor->generate_filename( 'scaled' );

        if ( ! is_string( $target ) || '' === $target ) {
            return null;
        }

        if ( $this->normalized_path( $target ) === $this->normalized_path( $source_path ) ) {
            return null;
        }

        if ( ! $this->is_inside_upload_dir( $target ) ) {
            return null;
        }

        $saved = $editor->save( $target );

        if ( is_wp_error( $saved ) || ! is_array( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
            return null;
        }

        return $saved;
    }

    /**
     * 恢复附件原图
     *
     * 顺序固定为：备份写回原路径 → 还原引用与文章链接 → 记录中间状态 → 删除转换结果、
     * 备份索引与压缩记录。任一步失败都会保留可重试的线索，绝不会先删掉正在被引用的文件。
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function restore_attachment( int $attachment_id ): bool {
        if ( ! $this->attachment_exists( $attachment_id ) ) {
            return false;
        }

        $lock = $this->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return false;
        }

        $global_lock = $this->acquire_global_lock( false );
        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            return false;
        }

        $was_suppressed                 = $this->auto_compress_suppressed;
        $this->auto_compress_suppressed = true;

        try {
            if ( ! $this->has_restore_state( $attachment_id ) && ! $this->restore_original_and_refs( $attachment_id ) ) {
                return false;
            }

            return $this->finish_restore( $attachment_id );
        } finally {
            $this->auto_compress_suppressed = $was_suppressed;
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 智能备份恢复：把主图像写回原路径，删掉其余文件，并记下正文链接的还原目标
     *
     * 只备份了一份主图像，恢复时把它写回，再删除缩放图、缩略图与格式转换结果，
     * 让附件回到“只有原图”的干净状态；缩略图交由 finish 阶段按当前设置重新生成。
     * 正文链接不在这里改写：链接要指向的文件得等缩略图重新生成后才知道在不在，
     * 因此只把“每个地址原来叫什么”记进恢复状态，由 finish 阶段解析后统一改写。
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    private function restore_original_and_refs( int $attachment_id ): bool {
        $backups = local_crush()->backup->get_backups( $attachment_id );

        // 没有备份就没有可恢复的东西。
        if ( empty( $backups ) ) {
            return false;
        }

        $backup   = $backups[0];
        $original = (string) $backup['original_path'];

        if ( '' === $original || ! local_crush()->backup->restore_backup_file( $backup, true ) ) {
            return false;
        }

        clearstatcache( true, $original );

        if ( ! is_file( $original ) ) {
            return false;
        }

        $original_norm = $this->normalized_path( $original );
        $metadata      = wp_get_attachment_metadata( $attachment_id );
        $metadata      = is_array( $metadata ) ? $metadata : array();

        // 收集除原图外要删除的文件：主文件、original_image、各缩略图、格式转换结果
        $to_delete = array();
        $main_rel  = isset( $metadata['file'] ) ? (string) $metadata['file'] : '';

        if ( '' !== $main_rel ) {
            $to_delete[] = $this->abs_from_relative( $main_rel );
        }

        if ( ! empty( $metadata['original_image'] ) ) {
            $dir_rel     = dirname( $main_rel );
            $orig_rel    = ( '.' === $dir_rel ? '' : $dir_rel . '/' ) . wp_basename( (string) $metadata['original_image'] );
            $to_delete[] = $this->abs_from_relative( $orig_rel );
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $file_dir = dirname( $main_rel );

            foreach ( $metadata['sizes'] as $size_data ) {
                if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
                    continue;
                }

                $to_delete[] = $this->abs_from_relative( $file_dir . '/' . $size_data['file'] );
            }
        }

        foreach ( local_crush()->output_processor->get_output_entries( $attachment_id ) as $entry ) {
            $to_delete[] = $entry['from'];
            $to_delete[] = $entry['to'];
        }

        $to_delete = array_values( array_unique( array_filter( $to_delete ) ) );

        // 正文链接的还原目标要在删文件和改元数据之前定下来，此时元数据还是压缩后的样子
        $links = $this->collect_restore_links( $attachment_id, $metadata, $to_delete );

        // 删除除原图外的文件，绝不删到原图头上，也不碰 uploads 外的路径与符号链接
        foreach ( $to_delete as $path ) {
            if ( ! is_file( $path ) || is_link( $path ) ) {
                continue;
            }

            if ( $this->normalized_path( $path ) === $original_norm || ! $this->is_inside_upload_dir( $path ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( ! unlink( $path ) && file_exists( $path ) ) {
                return false;
            }
        }

        // 元数据回到干净的原图状态
        $original_size = wp_getimagesize( $original );
        $clean         = array(
            'file'     => $this->get_relative_upload_path( $original ),
            'filesize' => (int) filesize( $original ),
        );

        if ( is_array( $original_size ) && ! empty( $original_size[0] ) && ! empty( $original_size[1] ) ) {
            $clean['width']  = (int) $original_size[0];
            $clean['height'] = (int) $original_size[1];
        }

        if ( ! empty( $original_size['mime'] ) ) {
            $clean['mime-type'] = (string) $original_size['mime'];
        }

        if ( ! empty( $metadata['image_meta'] ) && is_array( $metadata['image_meta'] ) ) {
            $clean['image_meta'] = $metadata['image_meta'];
        }

        if ( ! $this->write_restored_metadata( $attachment_id, $clean ) ) {
            return false;
        }

        update_attached_file( $attachment_id, $clean['file'] );

        // 附件 MIME 回到原图格式
        if ( ! empty( $clean['mime-type'] ) && $clean['mime-type'] !== get_post_mime_type( $attachment_id ) ) {
            wp_update_post(
                array(
                    'ID'             => $attachment_id,
                    'post_mime_type' => $clean['mime-type'],
                ),
                true
            );
        }

        return $this->write_restore_state( $attachment_id, $links );
    }

    /**
     * 收集正文链接的还原目标
     *
     * 恢复不是把链接一律改回原图：压缩前链接指向哪个尺寸，恢复后还要指向同一个
     * 尺寸的名字。转换映射里的 from 就是压缩前的地址；没被转换改过名的文件地址
     * 本身没变，重新生成后同名文件会回来。目标这次没生成出来的留给 finish 阶段
     * 就近替换，因此连宽高、是否主图一起记下。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $metadata      恢复前的附件元数据
     * @param array $paths         本次删除的文件绝对路径
     * @return array 每项包含 from（链接当前指向的地址）、to（还原目标）、width、height、is_main
     */
    private function collect_restore_links( int $attachment_id, array $metadata, array $paths ): array {
        $links = array();
        $seen  = array();

        foreach ( local_crush()->output_processor->get_output_entries( $attachment_id ) as $entry ) {
            $from = isset( $entry['to'] ) ? wp_normalize_path( (string) $entry['to'] ) : '';
            $to   = isset( $entry['from'] ) ? wp_normalize_path( (string) $entry['from'] ) : '';
            $size_type = isset( $entry['size_type'] ) ? (string) $entry['size_type'] : 'full';

            if ( '' === $from || '' === $to || ! $this->is_inside_upload_dir( $from ) ) {
                continue;
            }

            list( $width, $height ) = $this->restore_size_dimensions( $metadata, $size_type );

            $key = $this->normalized_path( $from );

            if ( isset( $seen[ $key ] ) ) {
                continue;
            }

            $seen[ $key ] = true;
            $links[]      = array(
                'from'    => $from,
                'to'      => $to,
                'width'   => $width,
                'height'  => $height,
                'is_main' => 'full' === $size_type,
            );
        }

        // 没有被转换改过名的文件：地址不变，只要重新生成出来链接就仍然有效
        foreach ( $paths as $path ) {
            $path = wp_normalize_path( (string) $path );

            if ( '' === $path || ! $this->is_inside_upload_dir( $path ) ) {
                continue;
            }

            $key = $this->normalized_path( $path );

            if ( isset( $seen[ $key ] ) ) {
                continue;
            }

            $seen[ $key ] = true;
            list( $width, $height ) = $this->restore_file_dimensions( $metadata, wp_basename( $path ) );
            $links[]                = array(
                'from'    => $path,
                'to'      => $path,
                'width'   => $width,
                'height'  => $height,
                'is_main' => wp_basename( $path ) === ( isset( $metadata['file'] ) ? wp_basename( (string) $metadata['file'] ) : '' ),
            );
        }

        return $links;
    }

    /**
     * 取恢复前元数据里某个尺寸的宽高
     *
     * @param array  $metadata  恢复前的附件元数据
     * @param string $size_type 尺寸类型，full 表示主图
     * @return array width、height，取不到时为 0
     */
    private function restore_size_dimensions( array $metadata, string $size_type ): array {
        if ( 'full' === $size_type ) {
            return array(
                isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
                isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
            );
        }

        $size = isset( $metadata['sizes'][ $size_type ] ) ? $metadata['sizes'][ $size_type ] : null;

        if ( is_array( $size ) ) {
            return array(
                isset( $size['width'] ) ? (int) $size['width'] : 0,
                isset( $size['height'] ) ? (int) $size['height'] : 0,
            );
        }

        return array( 0, 0 );
    }

    /**
     * 按文件名取恢复前元数据里的宽高
     *
     * @param array  $metadata  恢复前的附件元数据
     * @param string $file_name 文件名
     * @return array width、height，取不到时为 0
     */
    private function restore_file_dimensions( array $metadata, string $file_name ): array {
        if ( '' !== $file_name && $file_name === ( isset( $metadata['file'] ) ? wp_basename( (string) $metadata['file'] ) : '' ) ) {
            return $this->restore_size_dimensions( $metadata, 'full' );
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_data ) {
                if ( is_array( $size_data ) && ! empty( $size_data['file'] )
                    && wp_basename( (string) $size_data['file'] ) === $file_name ) {
                    return array(
                        isset( $size_data['width'] ) ? (int) $size_data['width'] : 0,
                        isset( $size_data['height'] ) ? (int) $size_data['height'] : 0,
                    );
                }
            }
        }

        return array( 0, 0 );
    }

    /**
     * 按当前勾选设置重新生成缩略图
     *
     * 恢复的语义是回到压缩前的样子：勾选了自动缩放且原图超过阈值时，-scaled 缩放图
     * 会重新生成并接管主文件，正文链接也随之指向同一个名字；关闭缩放时主图就是原图
     * 本身。生成的都是未压缩文件，与“恢复原图”一致，用户可重新压缩。
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否允许继续清理（原图缺失不阻断）
     */
    private function regenerate_thumbnails( int $attachment_id ): bool {
        $file = get_attached_file( $attachment_id );

        if ( ! $file || ! file_exists( $file ) ) {
            return true;
        }

        // wp_generate_attachment_metadata 定义在后台文件里；wp-cron / WP-CLI 不会自动加载。
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $fresh = wp_generate_attachment_metadata( $attachment_id, $file );

        if ( ! is_array( $fresh ) || empty( $fresh['file'] ) ) {
            return true;
        }

        // 新元数据缺失的字段（如 SVG 没有尺寸和体积）用恢复主流程里写好的值补齐
        $current = wp_get_attachment_metadata( $attachment_id );

        if ( is_array( $current ) ) {
            foreach ( array( 'filesize', 'width', 'height', 'image_meta' ) as $key ) {
                if ( ! isset( $fresh[ $key ] ) && isset( $current[ $key ] ) ) {
                    $fresh[ $key ] = $current[ $key ];
                }
            }
        }

        wp_update_attachment_metadata( $attachment_id, $fresh );

        return true;
    }

    /**
     * 写入恢复后的元数据并读回校验主文件
     *
     * @param int   $attachment_id 附件 ID
     * @param array $metadata      待写入的元数据
     * @return bool
     */
    private function write_restored_metadata( int $attachment_id, array $metadata ): bool {
        wp_update_attachment_metadata( $attachment_id, $metadata );

        $saved = wp_get_attachment_metadata( $attachment_id );

        return is_array( $saved )
            && isset( $saved['file'] )
            && $this->normalized_path( (string) $saved['file'] ) === $this->normalized_path( (string) $metadata['file'] );
    }

    /**
     * 由 uploads 相对路径拼出绝对路径
     *
     * @param string $relative_path 相对路径
     * @return string 路径非法时返回空字符串
     */
    private function abs_from_relative( string $relative_path ): string {
        $relative_path = ltrim( wp_normalize_path( $relative_path ), '/' );

        if ( '' === $relative_path || false !== strpos( $relative_path, '..' ) ) {
            return '';
        }

        $base_dir = untrailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) );

        return $base_dir . '/' . $relative_path;
    }

    /**
     * 恢复收尾：按当前设置重新生成缩略图，还原正文链接，再清理备份、记录与映射
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否全部完成
     */
    private function finish_restore( int $attachment_id ): bool {
        if ( ! $this->regenerate_thumbnails( $attachment_id ) ) {
            return false;
        }

        // 正文链接要在忘记转换映射之前还原，失败时保留恢复状态，下次可重试续跑
        if ( ! $this->restore_content_links( $attachment_id ) ) {
            return false;
        }

        if ( ! local_crush()->backup->delete_backup( $attachment_id ) ) {
            return false;
        }

        if ( ! local_crush()->database->delete_records_by_attachment( $attachment_id ) ) {
            return false;
        }

        if ( ! local_crush()->output_processor->forget_output_entries( $attachment_id ) ) {
            return false;
        }

        return $this->delete_restore_state( $attachment_id );
    }

    /**
     * 按恢复状态里记下的目标还原正文链接
     *
     * 目标文件已经重新生成好的，地址原样还原；目标这次没生成出来的分两种情况：
     * 原来指向主图的改成当前主文件（关闭自动缩放后缩放图不再生成，主图就是原图本身），
     * 原来指向缩略图的按像素面积挑一个最接近的现有尺寸顶上，平手选更大的；
     * 一个现有尺寸都没有时保持原样——正文里那条链接指向的文件这次已被删除，
     * 能改就必须改掉，否则读者看到的是打不开的图。
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否全部改写成功
     */
    private function restore_content_links( int $attachment_id ): bool {
        $state = $this->get_restore_state( $attachment_id );
        $links = isset( $state['links'] ) && is_array( $state['links'] ) ? $state['links'] : array();

        if ( empty( $links ) ) {
            return true;
        }

        $candidates   = $this->existing_size_candidates( $attachment_id );
        $replacements = array();

        foreach ( $links as $link ) {
            $from = isset( $link['from'] ) ? wp_normalize_path( (string) $link['from'] ) : '';
            $to   = isset( $link['to'] ) ? wp_normalize_path( (string) $link['to'] ) : '';

            if ( '' === $from || '' === $to || ! $this->is_inside_upload_dir( $from ) ) {
                continue;
            }

            if ( ! is_file( $to ) ) {
                // 原来指向主图的链接改到当前主文件：按面积挑会把全文大图降级成小缩略图
                if ( ! empty( $link['is_main'] ) ) {
                    $to = get_attached_file( $attachment_id );
                    $to = $to ? wp_normalize_path( $to ) : '';
                } else {
                    $nearest = local_crush()->thumbnail_manager->nearest_replacement(
                        array(
                            'file'   => $to,
                            'width'  => isset( $link['width'] ) ? (int) $link['width'] : 0,
                            'height' => isset( $link['height'] ) ? (int) $link['height'] : 0,
                        ),
                        $candidates
                    );

                    $to = null === $nearest ? '' : wp_normalize_path( (string) $nearest['file'] );
                }
            }

            if ( '' === $to || ! $this->is_inside_upload_dir( $to )
                || $this->normalized_path( $from ) === $this->normalized_path( $to ) ) {
                continue;
            }

            $replacements[] = array(
                'from' => $from,
                'to'   => $to,
            );
        }

        if ( empty( $replacements ) ) {
            return true;
        }

        $replaced = 0;

        return local_crush()->output_processor->update_content_references( $replacements, $replaced );
    }

    /**
     * 收集当前磁盘上真实存在的尺寸，作为链接还原的候选
     *
     * @param int $attachment_id 附件 ID
     * @return array 每项包含 file、width、height
     */
    private function existing_size_candidates( int $attachment_id ): array {
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            return array();
        }

        $base_dir = untrailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) );
        $file_dir = dirname( (string) $metadata['file'] );
        $found    = array();

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_data ) {
                if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
                    continue;
                }

                $path = $base_dir . '/' . ltrim( wp_normalize_path( $file_dir . '/' . $size_data['file'] ), '/' );

                if ( is_file( $path ) ) {
                    $found[] = array(
                        'file'   => $path,
                        'width'  => isset( $size_data['width'] ) ? (int) $size_data['width'] : 0,
                        'height' => isset( $size_data['height'] ) ? (int) $size_data['height'] : 0,
                    );
                }
            }
        }

        $main = $base_dir . '/' . ltrim( wp_normalize_path( (string) $metadata['file'] ), '/' );

        if ( is_file( $main ) ) {
            $found[] = array(
                'file'   => $main,
                'width'  => isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
                'height' => isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
            );
        }

        return $found;
    }

    /**
     * 删除已不存在附件的旧格式回退文件
     *
     * 优先按转换映射删除；如果附件删除时把 postmeta 一起带走了，映射已经不存在，
     * 就从压缩记录里的 fallback_* 记录找旧格式文件路径。文件删不掉时返回 false，
     * 让调用方保留记录，下一次清理还能重试。
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否全部删除成功
     */
    private function delete_orphan_fallback_files( int $attachment_id ): bool {
        $success = local_crush()->output_processor->delete_fallback_files( $attachment_id );

        foreach ( local_crush()->database->get_records_by_attachment( $attachment_id ) as $record ) {
            $size_type = isset( $record['size_type'] ) ? (string) $record['size_type'] : '';

            if ( 0 !== strpos( $size_type, 'fallback_' ) ) {
                continue;
            }

            $path = $this->abs_from_relative( isset( $record['file_path'] ) ? (string) $record['file_path'] : '' );

            if ( '' === $path || ! is_file( $path ) || is_link( $path ) || ! $this->is_inside_upload_dir( $path ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( ! unlink( $path ) && file_exists( $path ) ) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * 清除单个附件的全部处理痕迹
     *
     * 备份文件、备份索引、转换映射和压缩记录必须一起清除：只清记录会让下一轮压缩
     * 拿压缩结果当作原图备份；只清备份则等于悄悄丢掉用户唯一的恢复依据。
     *
     * @param int $attachment_id 附件 ID
     * @return bool 是否全部清除成功
     */
    private function clear_attachment_history( int $attachment_id ): bool {
        if ( ! local_crush()->backup->delete_backup( $attachment_id ) ) {
            return false;
        }

        if ( ! local_crush()->database->delete_records_by_attachment( $attachment_id ) ) {
            return false;
        }

        if ( ! local_crush()->output_processor->forget_output_entries( $attachment_id ) ) {
            return false;
        }

        return $this->delete_restore_state( $attachment_id );
    }

    /**
     * 定时清理入口：孤儿数据 + 压缩临时文件/回滚副本
     *
     * 与原图备份保留期无关，单独每天跑一次；备份到期清理只负责备份。
     */
    public function run_cleanup(): void {
        $this->prune_orphan_history();
        $this->prune_temp_files();
    }

    /**
     * 定时清理超过保留时长的原图备份
     *
     * 只删备份文件和备份索引：图片与正文保持压缩、转换后的状态，压缩记录和格式转换
     * 映射一并保留，否则下一轮批量会把这批图重新压一遍。代价是从这一刻起无法再恢复原图。
     */
    public function prune_expired_backups(): void {
        $settings = get_option( 'local_crush_general', array() );
        $days     = Local_Crush_Settings::normalize_retention_days(
            isset( $settings['backup_retention_days'] ) ? $settings['backup_retention_days'] : null
        );

        if ( Local_Crush_Settings::RETENTION_PERMANENT === $days ) {
            return;
        }

        $after_id  = 0;
        $processed = 0;
        $has_more  = true;

        do {
            $page      = $this->prune_expired_backup_page( $days, $after_id );
            $after_id  = (int) $page['next_after'];
            $processed += count( $page['pruned_ids'] ) + count( $page['failed_ids'] );
            $has_more  = (bool) $page['has_more'];
        } while ( $has_more && $processed < self::BACKUP_PRUNE_LIMIT );

        // 还有积压时不必等到明天，稍后补排一次继续清理。
        if ( $has_more ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::BACKUP_PRUNE_HOOK );
        }
    }

    /**
     * 清理已删除附件留下的处理痕迹
     *
     * 附件被永久删除时，删除钩子只在当场尽力清理一次：拿不到锁就静默放弃，
     * 也没有任何重试入口。这里兜底扫描，删掉无主的备份文件、备份索引、
     * 压缩记录和转换映射，否则这些文件会一直占着磁盘。
     *
     * @return array removed_ids、failed_ids、removed_count、failed_count、has_more
     */
    public function prune_orphan_history(): array {
        $after_id  = 0;
        $removed   = array();
        $failed    = array();
        $processed = 0;
        $has_more  = true;

        do {
            $page      = $this->prune_orphan_history_page( $after_id );
            $after_id  = (int) $page['next_after'];
            $removed   = array_merge( $removed, $page['removed_ids'] );
            $failed    = array_merge( $failed, $page['failed_ids'] );
            $processed += count( $page['removed_ids'] ) + count( $page['failed_ids'] );
            $has_more  = (bool) $page['has_more'];
        } while ( $has_more && $processed < self::BACKUP_PRUNE_LIMIT );

        if ( $has_more ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CLEANUP_HOOK );
        }

        return array(
            'removed_ids'   => $removed,
            'failed_ids'    => $failed,
            'removed_count' => count( $removed ),
            'failed_count'  => count( $failed ),
            'has_more'      => $has_more,
        );
    }

    /**
     * 清理压缩过程中被硬杀留下的临时文件和回滚副本
     *
     * 只删插件自己命名的中间产物，且必须超过保留时长；正常结束的压缩会自己清理，
     * 这里只兜底 PHP/网关超时或进程被杀的情况。扫描有 10 秒预算，扫不完下次继续。
     */
    public function prune_temp_files(): void {
        $upload_dir = wp_upload_dir();

        if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
            return;
        }

        $base = realpath( $upload_dir['basedir'] );

        if ( false === $base ) {
            return;
        }

        $base    = untrailingslashit( wp_normalize_path( $base ) );
        $cutoff  = time() - self::TEMP_CLEANUP_AGE;
        $deadline = microtime( true ) + self::TEMP_SCAN_BUDGET;
        $regex   = '/\.(?:lc-rollback-[A-Za-z0-9]+|lc-restore-[A-Za-z0-9]+|tmp\.(?:webp|gif|jxl|svg)|lc-avif\.(?:png|avif)|lc-compress-[A-Za-z0-9]+\.[A-Za-z0-9]+|lc-source-[A-Za-z0-9]+\.png|lc-anim-[A-Za-z0-9]+\.[A-Za-z0-9]+|lc-webp-tmp)$/';
        $timed_out = false;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( microtime( true ) > $deadline ) {
                $timed_out = true;
                break;
            }

            $raw_path = $file->getPathname();

            if ( ! is_file( $raw_path ) || is_link( $raw_path ) ) {
                continue;
            }

            $path = wp_normalize_path( $raw_path );

            if ( false !== strpos( $path, '/' . Local_Crush_Backup::BACKUP_DIR_NAME . '/' )
                || false !== strpos( $path, '/' . self::LOCK_DIR_NAME . '/' ) ) {
                continue;
            }

            $mtime = @filemtime( $raw_path );

            if ( false === $mtime || $mtime > $cutoff || ! preg_match( $regex, $file->getFilename() ) ) {
                continue;
            }

            $real_path = realpath( $raw_path );

            if ( false === $real_path || 0 !== strpos( wp_normalize_path( $real_path ), $base . '/' ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $real_path );
        }

        // 系统临时目录里的方向文件、webpmux 中间文件、命令输出缓冲。
        foreach ( array( 'lc_orient_*', 'lc_webp_mux_*', 'lc_webp_raw_*', 'lc_stdout_*', 'lc_stderr_*' ) as $pattern ) {
            $paths = glob( sys_get_temp_dir() . '/' . $pattern );

            if ( ! is_array( $paths ) ) {
                continue;
            }

            foreach ( $paths as $path ) {
                if ( ! is_file( $path ) || is_link( $path ) || filemtime( $path ) > $cutoff ) {
                    continue;
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $path );
            }
        }

        if ( $timed_out ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CLEANUP_HOOK );
        }
    }

    /**
     * 清理一批已删除附件的处理痕迹
     *
     * 附件已经不存在，不会有人再来压缩它，因此不取附件锁——否则只会给
     * 无主的附件 ID 留下永远不会用到的锁文件。仍要取全局维护锁，
     * 避免与“删除所有备份”并发。
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @return array removed_ids、failed_ids、next_after、has_more
     */
    public function prune_orphan_history_page( int $after_id = 0 ): array {
        $ids      = local_crush()->database->get_orphan_history_attachment_ids_after( $after_id, self::BACKUP_PRUNE_PAGE_SIZE + 1 );
        $has_more = count( $ids ) > self::BACKUP_PRUNE_PAGE_SIZE;

        if ( $has_more ) {
            array_pop( $ids );
        }

        $removed = array();
        $failed  = array();

        foreach ( $ids as $attachment_id ) {
            // 附件可能在扫描与清理之间被重新上传占用同一 ID，只处理确实已不存在的。
            if ( $this->attachment_exists( $attachment_id ) ) {
                continue;
            }

            $global_lock = $this->acquire_global_lock( false );

            if ( false === $global_lock ) {
                $failed[] = $attachment_id;
                continue;
            }

            try {
                if ( ! $this->delete_orphan_fallback_files( $attachment_id ) ) {
                    // 文件删不掉时保留记录，下一次清理还能按记录重试。
                    $failed[] = $attachment_id;
                } elseif ( $this->clear_attachment_history( $attachment_id ) ) {
                    $removed[] = $attachment_id;
                } else {
                    $failed[] = $attachment_id;
                }
            } finally {
                $this->release_attachment_lock( $global_lock );
            }
        }

        return array(
            'removed_ids' => $removed,
            'failed_ids'  => $failed,
            'next_after'  => empty( $ids ) ? $after_id : (int) max( $ids ),
            'has_more'    => $has_more,
        );
    }

    /**
     * 清理一批到期原图备份
     *
     * 附件的备份索引逐条删除，任一条失败都保留其索引，等下次任务重试。
     *
     * @param int $days     保留天数
     * @param int $after_id 上一批最后处理的附件 ID
     * @return array pruned_ids、failed_ids、next_after、has_more
     */
    public function prune_expired_backup_page( int $days, int $after_id = 0 ): array {
        $ids      = local_crush()->database->get_expired_backup_attachment_ids_after( $days, $after_id, self::BACKUP_PRUNE_PAGE_SIZE + 1 );
        $has_more = count( $ids ) > self::BACKUP_PRUNE_PAGE_SIZE;

        if ( $has_more ) {
            array_pop( $ids );
        }

        $pruned = array();
        $failed = array();

        foreach ( $ids as $attachment_id ) {
            $lock = $this->acquire_attachment_lock( $attachment_id );
            if ( false === $lock ) {
                $failed[] = $attachment_id;
                continue;
            }

            $global_lock = $this->acquire_global_lock( false );
            if ( false === $global_lock ) {
                $this->release_attachment_lock( $lock );
                $failed[] = $attachment_id;
                continue;
            }

            try {
                if ( local_crush()->backup->delete_backup( $attachment_id ) ) {
                    $pruned[] = $attachment_id;
                } else {
                    $failed[] = $attachment_id;
                }
            } finally {
                $this->release_attachment_lock( $global_lock );
                $this->release_attachment_lock( $lock );
            }
        }

        return array(
            'pruned_ids' => $pruned,
            'failed_ids' => $failed,
            'next_after' => empty( $ids ) ? $after_id : (int) max( $ids ),
            'has_more'   => $has_more,
        );
    }

    /**
     * 是否存在引用已还原、但残留文件与记录尚未清理完的附件
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function has_pending_restore( int $attachment_id ): bool {
        return $this->has_restore_state( $attachment_id );
    }

    /**
     * 读取恢复中间状态
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    private function get_restore_state( int $attachment_id ): array {
        $state = get_post_meta( $attachment_id, self::RESTORE_STATE_META_KEY, true );

        return is_array( $state ) ? $state : array();
    }

    /**
     * 是否存在待清理的恢复中间状态
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    private function has_restore_state( int $attachment_id ): bool {
        $state = $this->get_restore_state( $attachment_id );

        return isset( $state['links'] ) && is_array( $state['links'] );
    }

    /**
     * 写入恢复中间状态
     *
     * 记录正文链接的还原目标：缩略图要等 finish 阶段重新生成后才知道文件在不在，
     * 恢复中断后继续收尾时靠这份记录把正文链接改完，不会漏掉已经固化在文章里的旧地址。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $links         正文链接还原目标
     * @return bool
     */
    private function write_restore_state( int $attachment_id, array $links ): bool {
        $state = array( 'links' => $links );

        update_post_meta( $attachment_id, self::RESTORE_STATE_META_KEY, wp_slash( $state ) );

        $saved = $this->get_restore_state( $attachment_id );

        return isset( $saved['links'] ) && $saved['links'] === $links;
    }

    /**
     * 删除恢复中间状态
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    private function delete_restore_state( int $attachment_id ): bool {
        if ( ! $this->has_restore_state( $attachment_id ) ) {
            return true;
        }

        delete_post_meta( $attachment_id, self::RESTORE_STATE_META_KEY );

        return ! $this->has_restore_state( $attachment_id );
    }

    /**
     * 删除附件的未缩放原始大图（批量按钮入口）
     *
     * 与压缩流程里的自动清理共用同一套判断和清理逻辑，区别只有两点：不看
     * “压缩后删除未缩放的原始大图”和“自动缩放”两个开关（按钮是明确指令），
     * 以及批处理需要自己拿锁。
     *
     * @param int $attachment_id 附件 ID
     * @return array deleted_files、failed
     */
    public function delete_original_image( int $attachment_id ): array {
        $result = array(
            'deleted_files' => 0,
            'failed'        => false,
        );

        if ( ! $this->is_valid_attachment( $attachment_id ) ) {
            $result['failed'] = true;
            return $result;
        }

        $lock = $this->acquire_attachment_lock( $attachment_id );

        if ( false === $lock ) {
            $result['failed'] = true;
            return $result;
        }

        $global_lock = $this->acquire_global_lock( false );

        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            $result['failed'] = true;
            return $result;
        }

        try {
            $result['deleted_files'] = $this->delete_unscaled_original( $attachment_id, true );

            // 删完元数据还记着未缩放原图，说明主图有问题或文件删不动，如实报失败。
            $after = wp_get_attachment_metadata( $attachment_id );

            if ( 0 === $result['deleted_files'] && is_array( $after ) && $this->has_unscaled_original( $after ) ) {
                $result['failed'] = true;
            }

            return $result;
        } finally {
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 删除附件的兼容格式回退文件
     *
     * 只删除仍有对应新格式文件的旧格式文件，并把正文里指向旧格式的链接改到新格式，
     * 删除后不支持新格式的浏览器也统一加载新格式图片。
     *
     * @param int $attachment_id 附件 ID
     * @return array deleted_files、replaced、failed
     */
    public function delete_fallback( int $attachment_id ): array {
        $result = array(
            'deleted_files' => 0,
            'replaced'      => 0,
            'failed'        => false,
        );

        if ( ! $attachment_id ) {
            $result['failed'] = true;
            return $result;
        }

        $lock = $this->acquire_attachment_lock( $attachment_id );

        if ( false === $lock ) {
            $result['failed'] = true;
            return $result;
        }

        $global_lock = $this->acquire_global_lock( false );

        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            $result['failed'] = true;
            return $result;
        }

        try {
            $entries = array();
            $pairs   = array();

            foreach ( local_crush()->output_processor->get_fallback_entries( $attachment_id ) as $entry ) {
                // 回退文件必须位于 uploads 内且不是符号链接，否则一律不动。
                if ( ! $this->is_inside_upload_dir( $entry['from'] ) || is_link( $entry['from'] ) ) {
                    $result['failed'] = true;
                    continue;
                }

                $entries[] = $entry;
                $pairs[]   = array(
                    'from' => $entry['from'],
                    'to'   => $entry['to'],
                );
            }

            if ( empty( $pairs ) ) {
                return $result;
            }

            // 先把正文里固化的旧格式地址改到仍存在的新格式，成功后才删除旧文件。
            $replaced              = 0;
            $updated               = local_crush()->output_processor->update_content_references( $pairs, $replaced );
            $result['replaced']    = $replaced;
            if ( ! $updated ) {
                $result['failed'] = true;
                return $result;
            }

            $deleted = array();

            foreach ( $entries as $entry ) {
                // 两个尺寸共用同一份回退文件时，前面的条目已经把它删掉了：跳过即可，不算失败。
                if ( ! is_file( $entry['from'] ) ) {
                    continue;
                }

                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( ! unlink( $entry['from'] ) || file_exists( $entry['from'] ) ) {
                    $result['failed'] = true;
                    continue;
                }

                $deleted[] = $entry['size_type'];
                $result['deleted_files']++;
            }

            // 回退文件已删除，指向它的压缩记录不再描述任何真实文件。
            if ( ! empty( $deleted ) ) {
                local_crush()->output_processor->forget_fallback_records( $attachment_id, $deleted );
            }

            return $result;
        } finally {
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 删除附件的原图备份
     *
     * @param int $attachment_id 附件 ID
     * @return array ok：是否全部删除成功；files：删除前记录在案的备份文件数
     */
    public function delete_attachment_backups( int $attachment_id ): array {
        $result = array(
            'ok'    => false,
            'files' => 0,
        );

        $lock = $this->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return $result;
        }

        $global_lock = $this->acquire_global_lock( false );
        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            return $result;
        }

        try {
            $result['files'] = count( local_crush()->database->get_backups_by_attachment( $attachment_id ) );
            $result['ok']    = local_crush()->backup->delete_backup( $attachment_id );
        } finally {
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }

        return $result;
    }

    /**
     * 登记所有新建附件，覆盖不生成标准 metadata 的上传流程
     *
     * @param int $attachment_id 附件 ID
     */
    public function queue_upload_for_compression( $attachment_id ): void {
        $attachment_id = absint( $attachment_id );
        $settings      = get_option( 'local_crush_general', array() );

        if ( $this->auto_compress_suppressed
            || empty( $settings['auto_compress'] )
            || ! $this->is_valid_attachment( $attachment_id ) ) {
            return;
        }

        update_post_meta( $attachment_id, self::PENDING_META_KEY, time() );
        $this->queued_uploads[ $attachment_id ] = true;
    }

    /**
     * 请求结束前处理没有走标准 metadata 生成钩子的上传
     *
     * 单个请求只处理限量附件，避免批量导入时把请求拖到超时；
     * 未处理完的附件保留待处理标记，由后台收尾任务继续处理。
     */
    public function process_queued_uploads(): void {
        if ( empty( $this->queued_uploads ) ) {
            return;
        }

        $settings = get_option( 'local_crush_general', array() );
        if ( $this->auto_compress_suppressed || empty( $settings['auto_compress'] ) ) {
            foreach ( array_keys( $this->queued_uploads ) as $attachment_id ) {
                delete_post_meta( $attachment_id, self::PENDING_META_KEY );
            }
            $this->queued_uploads = array();
            return;
        }

        $processed = 0;

        foreach ( array_keys( $this->queued_uploads ) as $attachment_id ) {
            if ( $processed >= self::UPLOAD_QUEUE_LIMIT ) {
                break;
            }

            $this->run_pending_upload( $attachment_id );
            $processed++;
        }

        $this->queued_uploads = array();
    }

    /**
     * 后台收尾：处理请求中断留下的上传待办
     *
     * 只在普通后台页面加载时执行，AJAX 和 admin-post 不参与，
     * 避免和批量流程抢占资源。
     */
    public function process_pending_uploads(): void {
        $pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

        if ( wp_doing_ajax() || 'admin-post.php' === $pagenow ) {
            return;
        }

        $this->run_pending_sweep();
    }

    /**
     * 处理上传待办队列
     *
     * 单次限量并按最小间隔限流，避免长时间外部命令拖慢后台。
     */
    public function run_pending_sweep(): void {
        $settings = get_option( 'local_crush_general', array() );
        if ( $this->auto_compress_suppressed || empty( $settings['auto_compress'] ) ) {
            // 自动压缩关闭时待办标记没有意义，按限流节奏清理即可。
            if ( $this->acquire_pending_sweep_token() ) {
                $this->clear_pending_uploads();
            }
            return;
        }

        $attachment_ids = $this->get_pending_upload_ids( self::PENDING_SWEEP_LIMIT );
        if ( empty( $attachment_ids ) || ! $this->acquire_pending_sweep_token() ) {
            return;
        }

        foreach ( $attachment_ids as $attachment_id ) {
            $this->run_pending_upload( $attachment_id );
        }
    }

    /**
     * 清理全部上传待办标记
     */
    private function clear_pending_uploads(): void {
        global $wpdb;

        // 一次性删除，避免逐条删除在标记堆积时拖慢后台页面。
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", self::PENDING_META_KEY ) );
    }

    /**
     * 获取收尾任务限流令牌
     *
     * @return bool 是否允许本次执行
     */
    private function acquire_pending_sweep_token(): bool {
        if ( get_transient( self::PENDING_SWEEP_TRANSIENT ) ) {
            return false;
        }

        set_transient( self::PENDING_SWEEP_TRANSIENT, time(), self::PENDING_SWEEP_INTERVAL );
        return true;
    }

    /**
     * 处理单个上传待办附件
     *
     * @param int $attachment_id 附件 ID
     */
    private function run_pending_upload( int $attachment_id ): void {
        if ( ! get_post_meta( $attachment_id, self::PENDING_META_KEY, true ) ) {
            return;
        }

        $result = $this->compress_attachment( $attachment_id );

        // 锁被占用说明其他进程正在处理，刷新时间戳排到队尾等待下次收尾。
        if ( ! empty( $result['busy'] ) ) {
            update_post_meta( $attachment_id, self::PENDING_META_KEY, time() );
            return;
        }

        delete_post_meta( $attachment_id, self::PENDING_META_KEY );
    }

    /**
     * 查询残留的上传待办附件
     *
     * 按登记时间升序返回，时间最久的优先处理；
     * 已删除或已进入回收站的附件不再处理。
     *
     * @param int $limit 数量上限
     * @return int[]
     */
    private function get_pending_upload_ids( int $limit ): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.post_id
                 FROM {$wpdb->postmeta} p
                 INNER JOIN {$wpdb->posts} o ON o.ID = p.post_id
                 WHERE p.meta_key = %s
                   AND o.post_type = 'attachment'
                   AND o.post_status <> 'trash'
                 GROUP BY p.post_id
                 ORDER BY MIN( p.meta_value + 0 ) ASC
                 LIMIT %d",
                self::PENDING_META_KEY,
                max( 1, min( 50, $limit ) )
            )
        );

        return array_map( 'absint', $ids );
    }

    /**
     * 上传创建附件时自动执行统一压缩
     *
     * @param array       $metadata 附件元数据
     * @param int         $attachment_id 附件 ID
     * @param string|null $context  WordPress 元数据上下文
     * @return array
     */
    public function auto_compress_on_upload( $metadata, $attachment_id, $context = null ) {
        $settings = get_option( 'local_crush_general', array() );

        if ( $this->auto_compress_suppressed
            || empty( $settings['auto_compress'] )
            || ! $this->is_valid_attachment( absint( $attachment_id ) ) ) {
            return $metadata;
        }

        $is_pending = ! empty( get_post_meta( $attachment_id, self::PENDING_META_KEY, true ) );

        // 新版 WordPress 明确提供 create/update；旧版通过待处理标记区分。
        if ( is_string( $context ) && 'create' !== $context ) {
            return $metadata;
        }
        if ( null === $context && ! $is_pending ) {
            return $metadata;
        }

        // 首次上传时核心尚未保存本次 metadata，先持久化再交给统一处理器。
        if ( is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        $result = $this->compress_attachment( absint( $attachment_id ) );
        if ( empty( $result['busy'] ) ) {
            delete_post_meta( $attachment_id, self::PENDING_META_KEY );
        }
        $latest = wp_get_attachment_metadata( $attachment_id );

        return is_array( $latest ) ? $latest : $metadata;
    }

    /**
     * 附件永久删除时清理插件残留数据
     *
     * @param int $post_id 附件 ID
     */
    public function handle_attachment_deleted( $post_id ): void {
        $attachment_id = absint( $post_id );
        if ( ! $attachment_id ) {
            return;
        }

        $lock = $this->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return;
        }

        // 与压缩/恢复保持一致的共享锁：只需防同附件并发，不必独占整个插件。
        $global_lock = $this->acquire_global_lock( false );
        if ( false === $global_lock ) {
            $this->release_attachment_lock( $lock );
            return;
        }

        try {
            // 旧格式回退文件不写进附件元数据，WordPress 删附件时不会带走它们，先按映射删掉。
            local_crush()->output_processor->delete_fallback_files( $attachment_id );

            $this->clear_attachment_history( $attachment_id );
        } finally {
            $this->release_attachment_lock( $global_lock );
            $this->release_attachment_lock( $lock );
        }
    }

    /**
     * 附件是否仍然存在
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    private function attachment_exists( int $attachment_id ): bool {
        $post = $attachment_id ? get_post( $attachment_id ) : null;

        return $post && 'attachment' === $post->post_type;
    }

    /**
     * 获取附件级排他锁
     *
     * @param int $attachment_id 附件 ID
     * @return resource|false
     */
    public function acquire_attachment_lock( int $attachment_id ) {
        // phpcs:disable WordPress.WP.AlternativeFunctions -- 文件锁必须用 fopen/flock/fclose，WP_Filesystem 不支持加锁。
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/' . self::LOCK_DIR_NAME;

        if ( ! is_dir( $lock_dir ) && ! wp_mkdir_p( $lock_dir ) ) {
            $this->lock_error = 'unavailable';
            return false;
        }

        $handle = fopen( $lock_dir . '/' . absint( $attachment_id ) . '.lock', 'c' );
        if ( false === $handle ) {
            $this->lock_error = 'unavailable';
            return false;
        }

        if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
            fclose( $handle );
            $this->lock_error = 'busy';
            return false;
        }

        $this->lock_error = '';
        return $handle;
    }

    /**
     * 获取全局维护锁
     *
     * 压缩/恢复使用共享锁，维护操作使用排他锁。
     *
     * @param bool $exclusive 是否排他
     * @return resource|false
     */
    public function acquire_global_lock( bool $exclusive = false ) {
        $upload_dir = wp_upload_dir();
        $lock_dir   = $upload_dir['basedir'] . '/' . self::LOCK_DIR_NAME;

        if ( ! is_dir( $lock_dir ) && ! wp_mkdir_p( $lock_dir ) ) {
            $this->lock_error = 'unavailable';
            return false;
        }

        $handle = fopen( $lock_dir . '/maintenance.lock', 'c' );
        if ( false === $handle ) {
            $this->lock_error = 'unavailable';
            return false;
        }

        $flags = $exclusive ? ( LOCK_EX | LOCK_NB ) : ( LOCK_SH | LOCK_NB );
        if ( ! flock( $handle, $flags ) ) {
            fclose( $handle );
            $this->lock_error = 'busy';
            return false;
        }

        $this->lock_error = '';
        return $handle;
    }

    /**
     * 释放文件锁
     *
     * @param resource $lock 锁句柄
     */
    public function release_attachment_lock( $lock ): void {
        if ( ! is_resource( $lock ) ) {
            return;
        }

        flock( $lock, LOCK_UN );
        fclose( $lock );
    }
    // phpcs:enable

    /**
     * 判断压缩记录是否与当前文件完全匹配
     *
     * @param string      $file_path 当前文件绝对路径
     * @param array|null  $record    压缩记录
     * @return bool
     */
    private function is_complete_record( string $file_path, $record ): bool {
        if ( ! is_array( $record ) || 'success' !== $record['status'] || empty( $record['file_path'] ) ) {
            return false;
        }

        clearstatcache( true, $file_path );
        if ( ! is_file( $file_path ) || (int) filesize( $file_path ) !== (int) $record['compressed_size'] ) {
            return false;
        }

        $current_relative = $this->get_relative_upload_path( $file_path );
        return $this->normalized_path( $record['file_path'] ) === $this->normalized_path( $current_relative );
    }

    /**
     * 同步附件元数据中记录的文件大小
     *
     * 主文件和每个尺寸都要同步：压缩或格式转换后文件已经变小，
     * 只更新主文件会让所有尺寸的 filesize 停留在压缩前的旧值，
     * 读取元数据的调用方拿到的就是错误大小。
     *
     * @param int $attachment_id 附件 ID
     */
    private function refresh_metadata_file_sizes( int $attachment_id ): void {
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            return;
        }

        $changed = $this->sync_metadata_filesize( $metadata, (string) $metadata['file'] );

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $file_dir = dirname( (string) $metadata['file'] );

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
                    continue;
                }

                $synced = $this->sync_metadata_filesize(
                    $metadata['sizes'][ $size_name ],
                    $file_dir . '/' . $size_data['file']
                );

                $changed = $changed || $synced;
            }
        }

        if ( ! $changed ) {
            return;
        }

        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    /**
     * 把单个文件条目的 filesize 对齐磁盘实际大小
     *
     * @param array  $target       待同步的元数据条目（引用传递）
     * @param string $relative_path 相对 uploads 根的路径
     * @return bool 是否发生改动
     */
    private function sync_metadata_filesize( array &$target, string $relative_path ): bool {
        if ( '' === $relative_path ) {
            return false;
        }

        $absolute_path = untrailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) ) . '/' . ltrim( $relative_path, '/' );

        if ( ! $this->is_inside_upload_dir( $absolute_path ) || ! is_file( $absolute_path ) ) {
            return false;
        }

        clearstatcache( true, $absolute_path );
        $filesize = (int) filesize( $absolute_path );

        if ( isset( $target['filesize'] ) && (int) $target['filesize'] === $filesize ) {
            return false;
        }

        $target['filesize'] = $filesize;

        return true;
    }

    /**
     * 判断文件是否可安全处理：真实存在、不是符号链接、且位于上传目录内
     *
     * @param string $path 文件路径
     * @return bool
     */
    private function is_safe_processing_path( string $path ): bool {
        if ( '' === $path || is_link( $path ) || ! is_file( $path ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $real_path  = realpath( $path );

        if ( false === $base_dir || false === $real_path ) {
            return false;
        }

        $base_dir  = untrailingslashit( $this->normalized_path( $base_dir ) );
        $real_path = $this->normalized_path( $real_path );

        return 0 === strpos( $real_path, $base_dir . '/' );
    }

    /**
     * 判断路径是否位于上传目录内
     *
     * @param string $path 文件路径
     * @return bool
     */
    private function is_inside_upload_dir( string $path ): bool {
        $upload_dir = wp_upload_dir();
        // 两侧都做 realpath，兼容 uploads 目录是符号链接或独立卷的部署方式。
        $base_dir = realpath( $upload_dir['basedir'] );
        $base_dir = $this->normalized_path( $base_dir ? $base_dir : $upload_dir['basedir'] );
        $target   = realpath( $path );
        // realpath 失败时按字面解析 . 和 ..，避免穿越路径被误判为目录内。
        $target   = $this->normalized_path( $target ? $target : $this->collapse_path_segments( $path ) );

        return 0 === strpos( $target, rtrim( $base_dir, '/' ) . '/' );
    }

    /**
     * 按字面解析路径中的 . 和 ..
     *
     * @param string $path 文件路径
     * @return string
     */
    private function collapse_path_segments( string $path ): string {
        $normalized = str_replace( '\\', '/', $path );
        $prefix     = '';

        if ( preg_match( '#^([a-z]:/|/)#i', $normalized, $matches ) ) {
            $prefix     = $matches[1];
            $normalized = substr( $normalized, strlen( $prefix ) );
        }

        $segments = array();
        foreach ( explode( '/', $normalized ) as $segment ) {
            if ( '' === $segment || '.' === $segment ) {
                continue;
            }

            if ( '..' === $segment ) {
                array_pop( $segments );
                continue;
            }

            $segments[] = $segment;
        }

        return $prefix . implode( '/', $segments );
    }

    /**
     * 获取上传目录内相对路径
     *
     * 返回值只统一分隔符、不动大小写，避免写入元数据的文件名大小写被改掉。
     *
     * @param string $path 绝对路径或相对路径
     * @return string
     */
    private function get_relative_upload_path( string $path ): string {
        $upload_dir = wp_upload_dir();
        $normalized = wp_normalize_path( $path );
        $base_dir   = $upload_dir['basedir'];

        if ( 0 === strpos( $this->normalized_path( $normalized ), $this->normalized_path( $base_dir ) . '/' ) ) {
            return ltrim( substr( $normalized, strlen( $base_dir ) ), '/' );
        }

        return ltrim( $normalized, '/' );
    }

    /**
     * 规范化路径用于比较
     *
     * @param string $path 路径
     * @return string
     */
    private function normalized_path( string $path ): string {
        $normalized = wp_normalize_path( $path );
        return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? strtolower( $normalized ) : $normalized;
    }

    /**
     * 获取加锁失败提示
     *
     * @param bool $global 是否为全局维护锁
     * @return string
     */
    private function get_lock_error_message( bool $global = false ): string {
        if ( 'busy' === $this->lock_error ) {
            return $global
                ? __( '插件正在执行维护操作，请稍后重试', 'local-crush' )
                : __( '该图片正在处理中，请稍后重试', 'local-crush' );
        }

        return __( '无法创建锁文件，请检查上传目录的写入权限', 'local-crush' );
    }

    /**
     * 创建空结果
     *
     * @param string $message 提示
     * @param string $status  状态
     * @param bool   $busy    是否因锁冲突未执行
     * @return array
     */
    private function empty_result( string $message, string $status, bool $busy = false ): array {
        return array(
            'status'      => $status,
            'message'     => $message,
            'busy'        => $busy,
            'total'       => 0,
            'success'     => 0,
            'failed'      => $status === 'failed' ? 1 : 0,
            'skipped'     => $status === 'skipped' ? 1 : 0,
            'saved_bytes' => 0,
            'details'     => array(),
        );
    }
}
