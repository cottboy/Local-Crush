<?php
/**
 * 缩略图管理类
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 缩略图管理类
 *
 * 按设置决定生成哪些尺寸的缩略图、调整大图缩放阈值，并负责清理未勾选尺寸的旧文件、补生成缺失的尺寸
 */
class Local_Crush_Thumbnail_Manager {

    /**
     * 构造函数
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * 初始化钩子
     */
    private function init_hooks() {
        // 按设置跳过未勾选的缩略图尺寸
        add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_sizes_for_generation' ), 10, 3 );

        // 按设置调整大图缩放阈值
        add_filter( 'big_image_size_threshold', array( $this, 'filter_big_image_size_threshold' ) );
    }

    /**
     * 未勾选、即不再生成的缩略图尺寸
     *
     * 存黑名单而不是白名单：以后主题或插件新注册的尺寸默认会生成，
     * 与设置页“默认全部勾选”的说法一致。
     *
     * @return string[]
     */
    public static function disabled_sizes(): array {
        $settings = get_option( 'local_crush_general', array() );
        $disabled = isset( $settings['disabled_thumbnail_sizes'] ) ? (array) $settings['disabled_thumbnail_sizes'] : array();

        return array_values( array_filter( array_map( 'sanitize_key', $disabled ) ) );
    }

    /**
     * 判断某个缩略图尺寸是否已勾选生成
     *
     * @param string $size_name 尺寸名
     * @return bool
     */
    public static function is_size_enabled( string $size_name ): bool {
        return ! in_array( $size_name, self::disabled_sizes(), true );
    }

    /**
     * 当前已注册且已勾选的尺寸
     *
     * @return string[]
     */
    public static function enabled_sizes(): array {
        $registered = array_keys( wp_get_registered_image_subsizes() );

        return array_values( array_diff( $registered, self::disabled_sizes() ) );
    }

    /**
     * 生成缩略图时剔除未勾选的尺寸
     *
     * @param array $sizes         待生成的尺寸
     * @param array $metadata      图片元数据
     * @param int   $attachment_id 附件 ID
     * @return array
     */
    public function filter_sizes_for_generation( $sizes, $metadata = array(), $attachment_id = 0 ) {
        if ( ! is_array( $sizes ) || empty( $sizes ) ) {
            return $sizes;
        }

        foreach ( self::disabled_sizes() as $size_name ) {
            unset( $sizes[ $size_name ] );
        }

        return $sizes;
    }

    /**
     * 按设置覆盖 WordPress 的大图缩放阈值
     *
     * 接的是 WordPress 生成 -scaled 缩放图时用的那个阈值，官方默认 2560。
     * 勾选自动缩放时返回设定阈值，未勾选时返回 0——0 与 false 一样会被核心
     * 当作“不缩放”处理，上传的大图直接作为主文件投入使用。
     *
     * @param int|false $threshold WordPress 当前阈值
     * @return int 开启自动缩放时为设定阈值，关闭时为 0
     */
    public function filter_big_image_size_threshold( $threshold ) {
        return Local_Crush_Settings::image_scale_enabled()
            ? Local_Crush_Settings::image_size_threshold()
            : 0;
    }

    /**
     * 删除单个附件中未勾选尺寸的缩略图
     *
     * 先更新正文链接，成功后再改元数据和删文件；正文更新失败时不做任何清理。
     * 文件仍被保留尺寸共用时只摘元数据条目，文件与它的旧格式回退都保留。
     *
     * @param int $attachment_id 附件 ID
     * @return array|null 计划结果；锁被占用或元数据写入失败时返回 null
     */
    public function delete_disabled_for_attachment( int $attachment_id ): ?array {
        $lock = local_crush()->processor->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return null;
        }

        $global_lock = local_crush()->processor->acquire_global_lock( false );
        if ( false === $global_lock ) {
            local_crush()->processor->release_attachment_lock( $lock );
            return null;
        }

        try {
            // 先按当前缩放设置清理 -scaled 缩放图（与尺寸勾选无关）
            $scaled = $this->cleanup_scaled( $attachment_id );

            if ( null === $scaled ) {
                return null;
            }

            $deleted_base  = (int) $scaled['deleted'];
            $replaced_base = (int) $scaled['replaced'];

            $metadata = wp_get_attachment_metadata( $attachment_id );

            if ( empty( $metadata ) || empty( $metadata['file'] ) || empty( $metadata['sizes'] ) ) {
                return array(
                    'deleted'  => $deleted_base,
                    'replaced' => $replaced_base,
                );
            }

            $upload   = wp_upload_dir();
            $base_dir = untrailingslashit( $upload['basedir'] );
            $file_dir = dirname( $metadata['file'] );

            $victims = array();
            $kept    = array();

            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $path = $base_dir . '/' . $file_dir . '/' . $size_data['file'];

                if ( ! file_exists( $path ) ) {
                    continue;
                }

                $entry = array(
                    'file'   => $path,
                    'width'  => isset( $size_data['width'] ) ? (int) $size_data['width'] : 0,
                    'height' => isset( $size_data['height'] ) ? (int) $size_data['height'] : 0,
                );

                if ( self::is_size_enabled( $size_name ) ) {
                    $kept[ $size_name ] = $entry;
                } else {
                    $victims[ $size_name ] = $entry;
                }
            }

            if ( empty( $victims ) ) {
                return array(
                    'deleted'  => $deleted_base,
                    'replaced' => $replaced_base,
                );
            }

            // 原图参与比面积：它差得最远，只有真的最接近时才会被选中，不会被优先采用
            $full_path = $base_dir . '/' . $metadata['file'];

            if ( file_exists( $full_path ) ) {
                $kept['full'] = array(
                    'file'   => $full_path,
                    'width'  => isset( $metadata['width'] ) ? (int) $metadata['width'] : 0,
                    'height' => isset( $metadata['height'] ) ? (int) $metadata['height'] : 0,
                );
            }

            // 要删的尺寸一律删到底，替代尺寸只决定文章链接改到哪里。
            // 两者捆在一个判断里会让“一个尺寸都没勾选”时没有任何相近尺寸可选，
            // 结果一个文件都删不掉。
            // $kept 里带着原图，所以挑不到替代只可能是连原图都不在，此时链接不动、文件照删。
            // 宽高相同的两个尺寸（如 large 与 medium_large）可能共用同一份文件：文件仍被
            // 保留尺寸使用时只摘掉被删尺寸的元数据条目，不删文件，正文链接也不改写——
            // 文件还在原地，链接依然有效。
            $kept_paths = array();

            foreach ( $kept as $size_data ) {
                $kept_paths[ $this->normalized_path( $size_data['file'] ) ] = true;
            }

            $deletions = array();
            $targets   = array();

            foreach ( $victims as $size_name => $size_data ) {
                if ( isset( $kept_paths[ $this->normalized_path( $size_data['file'] ) ] ) ) {
                    continue;
                }

                $deletions[ $size_name ] = $size_data['file'];

                $replacement = $this->nearest_replacement( $size_data, $kept );

                if ( null !== $replacement ) {
                    $targets[ $size_name ] = $replacement['file'];
                }
            }

            // 兼容格式不写进附件元数据，必须按转换映射找到同尺寸的旧文件。
            // 这里不看“兼容格式回退”开关：开关关闭后，磁盘上仍可能留有旧文件。
            // 只看真正要删文件的尺寸：共用文件没删，它的旧格式回退同样不能动。
            $fallback_by_size = array();
            foreach ( local_crush()->output_processor->get_fallback_entries( $attachment_id ) as $entry ) {
                $size_type = isset( $entry['size_type'] ) ? (string) $entry['size_type'] : '';

                if ( '' !== $size_type && isset( $deletions[ $size_type ] ) ) {
                    $fallback_by_size[ $size_type ] = $entry;
                }
            }

            $replacements = array();
            foreach ( $targets as $size_name => $target_path ) {
                $replacements[] = array(
                    'from' => $victims[ $size_name ]['file'],
                    'to'   => $target_path,
                );

                if ( isset( $fallback_by_size[ $size_name ] ) ) {
                    $replacements[] = array(
                        'from' => $fallback_by_size[ $size_name ]['from'],
                        'to'   => $target_path,
                    );
                }
            }

            $replaced = 0;

            if ( ! local_crush()->references->update_references( $replacements, $replaced ) ) {
                return array(
                    'deleted'        => $deleted_base,
                    'replaced'       => $replaced_base + $replaced,
                    'content_failed' => true,
                );
            }

            // 元数据条目对全部被删尺寸一律摘除；共用文件的尺寸文件仍在，只是名册里去掉这一条。
            foreach ( array_keys( $victims ) as $size_name ) {
                unset( $metadata['sizes'][ $size_name ] );
            }

            if ( ! $this->write_metadata( $attachment_id, $metadata ) ) {
                return null;
            }

            $deleted                = 0;
            $deleted_paths          = array();
            $deleted_fallback_paths = array();

            foreach ( $deletions as $size_name => $path ) {
                if ( ! $this->is_safe_upload_path( $path ) ) {
                    continue;
                }

                if ( file_exists( $path ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $path );
                }

                if ( file_exists( $path ) ) {
                    continue;
                }

                // 两个被删尺寸共用同一个文件时文件只删一次，计数也只记一次；
                // 每个尺寸自己的压缩记录仍要逐个清理。
                $normalized = $this->normalized_path( $path );

                if ( ! isset( $deleted_paths[ $normalized ] ) ) {
                    $deleted_paths[ $normalized ] = true;
                    $deleted++;
                }

                $database = local_crush()->database;
                $record   = $database->get_record( $attachment_id, $size_name );

                if ( $record ) {
                    $database->delete_record( $record['id'] );
                }

                // 新格式删掉后，映射对应的旧格式缩略图也必须一起删掉。
                if ( isset( $fallback_by_size[ $size_name ] ) ) {
                    $fallback_path = $fallback_by_size[ $size_name ]['from'];

                    if ( $this->is_safe_upload_path( $fallback_path ) && is_file( $fallback_path ) && ! is_link( $fallback_path ) ) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                        unlink( $fallback_path );
                    }

                    if ( ! file_exists( $fallback_path ) ) {
                        $fallback_normalized = $this->normalized_path( $fallback_path );

                        if ( ! isset( $deleted_fallback_paths[ $fallback_normalized ] ) ) {
                            $deleted_fallback_paths[ $fallback_normalized ] = true;
                            $deleted++;
                        }
                    }
                }
            }

            // 共用文件被保留尺寸继续使用：文件与其映射对不动，但被删尺寸自己的
            // 映射条目和压缩记录要清掉，否则明细里会留下没有当前文件对应的孤儿条目。
            foreach ( $victims as $size_name => $size_data ) {
                if ( isset( $deletions[ $size_name ] ) ) {
                    continue;
                }

                local_crush()->output_processor->remove_output_entry( $attachment_id, $size_name );

                $shared_record = local_crush()->database->get_record( $attachment_id, $size_name );

                if ( $shared_record ) {
                    local_crush()->database->delete_record( $shared_record['id'] );
                }

                local_crush()->output_processor->forget_fallback_records( $attachment_id, array( $size_name ) );
            }

            if ( ! empty( $fallback_by_size ) ) {
                $deleted_fallback_sizes = array();

                foreach ( $fallback_by_size as $size_name => $entry ) {
                    if ( ! is_file( $entry['from'] ) && ! is_link( $entry['from'] ) ) {
                        $deleted_fallback_sizes[] = $size_name;
                    }
                }

                if ( ! empty( $deleted_fallback_sizes ) ) {
                    local_crush()->output_processor->forget_fallback_records( $attachment_id, $deleted_fallback_sizes );
                }
            }

            return array(
                'deleted'  => $deleted_base + $deleted,
                'replaced' => $replaced_base + $replaced,
            );
        } finally {
            local_crush()->processor->release_attachment_lock( $global_lock );
            local_crush()->processor->release_attachment_lock( $lock );
        }
    }

    /**
     * 为被删尺寸挑一个像素面积最接近的替代尺寸，平手时选更大的
     *
     * 删除按钮与恢复原图都要把正文链接改到仍然存在的尺寸上，共用同一套挑选规则。
     * 原图也在这套候选里：它的面积通常差得最远，自然排在最后，只有真的最接近时才轮到它。
     * 不设“差太多就放弃”的门槛——放弃一支尺寸就会让页面里的图打不开。
     *
     * @param array $victim     被删尺寸的文件与宽高
     * @param array $candidates 仍然存在的候选尺寸
     * @return array|null 一个候选都没有时返回 null
     */
    public function nearest_replacement( array $victim, array $candidates ) {
        $victim_area = max( 1, $victim['width'] * $victim['height'] );
        $best        = null;
        $best_score  = null;

        foreach ( $candidates as $candidate ) {
            $area  = max( 1, $candidate['width'] * $candidate['height'] );
            $score = abs( $area - $victim_area );

            if ( null === $best_score || $score < $best_score || ( $score === $best_score && $area > $best['area'] ) ) {
                $best       = array(
                    'file' => $candidate['file'],
                    'area' => $area,
                );
                $best_score = $score;
            }
        }

        return $best;
    }

    /**
     * 给单个附件补齐缺失尺寸
     *
     * @param int $attachment_id 附件 ID
     * @return int|string|null 新增文件数；无需处理时返回原因字符串；失败返回 null
     */
    public function generate_missing_thumbnails( int $attachment_id ) {
        return $this->generate_missing_for_attachment(
            $attachment_id,
            self::enabled_sizes(),
            wp_get_registered_image_subsizes()
        );
    }

    /**
     * 给单个附件补齐缺失尺寸
     *
     * 只生成缺失的尺寸：核心生成完会整体覆盖元数据，所以事后把原有尺寸并回去，
     * 已存在的缩略图文件不会被重建。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $enabled       已勾选的尺寸名
     * @param array $registered    注册尺寸配置
     * @return int|string|null 新增文件数；无需处理时返回原因字符串；失败返回 null
     */
    private function generate_missing_for_attachment( int $attachment_id, array $enabled, array $registered ) {
        $lock = local_crush()->processor->acquire_attachment_lock( $attachment_id );
        if ( false === $lock ) {
            return null;
        }

        $global_lock = local_crush()->processor->acquire_global_lock( false );
        if ( false === $global_lock ) {
            local_crush()->processor->release_attachment_lock( $lock );
            return null;
        }

        try {
            $file = get_attached_file( $attachment_id );

            if ( ! $file || ! file_exists( $file ) ) {
                return null;
            }

            // 转换后的附件主文件是 WebP/AVIF，生成缺失尺寸时要回到 uploads 中
            // 的未缩放旧格式原图。不能使用备份文件，也不能拿 -scaled 图当原图。
            $legacy_source = $this->find_legacy_original_source( $attachment_id, wp_get_attachment_metadata( $attachment_id ) );

            // 没有 WordPress 能解码的旧格式栅格源时，这两种格式都补不了缩略图，
            // 但结果不一样：
            // - SVG 本来就没有栅格源，跳过不算失败；
            // - JXL 是占位格式，暂时解不出来就按失败报，不能悄悄算作“无需处理”。
            if ( null === $legacy_source ) {
                $mime_type = (string) get_post_mime_type( $attachment_id );

                if ( 'image/svg+xml' === $mime_type ) {
                    return 'unsupported_format';
                }

                if ( 'image/jxl' === $mime_type ) {
                    return null;
                }
            }

            // 先按当前缩放设置补建/修正 -scaled 缩放图（与缩略图缺失无关）
            $scaled = $this->ensure_scaled( $attachment_id );

            if ( null === $scaled ) {
                return null;
            }

            $scaled_count = isset( $scaled['generated'] ) ? (int) $scaled['generated'] : 0;

            // ensure_scaled 可能改写了主文件，重新取当前主文件用于后续生成
            $file = get_attached_file( $attachment_id );

            if ( ! $file || ! file_exists( $file ) ) {
                return null;
            }

            $metadata = wp_get_attachment_metadata( $attachment_id );

            if ( empty( $metadata ) || empty( $metadata['file'] ) ) {
                return 'no_metadata';
            }

            $upload   = wp_upload_dir();
            $base_dir = untrailingslashit( $upload['basedir'] );
            $file_dir = dirname( $metadata['file'] );
            $width    = isset( $metadata['width'] ) ? (int) $metadata['width'] : 0;
            $height   = isset( $metadata['height'] ) ? (int) $metadata['height'] : 0;

            if ( null !== $legacy_source ) {
                $source_size = wp_getimagesize( $legacy_source['path'] );

                if ( is_array( $source_size ) && ! empty( $source_size[0] ) && ! empty( $source_size[1] ) ) {
                    $width  = (int) $source_size[0];
                    $height = (int) $source_size[1];
                }
            }

            $missing_new = array();

            foreach ( $enabled as $size_name ) {
                if ( ! isset( $registered[ $size_name ] ) ) {
                    continue;
                }

                if ( ! empty( $metadata['sizes'][ $size_name ]['file'] ) ) {
                    $existing = $base_dir . '/' . $file_dir . '/' . $metadata['sizes'][ $size_name ]['file'];

                    if ( file_exists( $existing ) ) {
                        continue;
                    }
                }

                if ( ! $this->size_applies_to_image( $registered[ $size_name ], $width, $height ) ) {
                    continue;
                }

                $missing_new[] = $size_name;
            }

            $legacy_entries = array();
            if ( null !== $legacy_source ) {
                foreach ( local_crush()->output_processor->get_output_entries( $attachment_id ) as $entry ) {
                    $size_name = isset( $entry['size_type'] ) ? (string) $entry['size_type'] : '';

                    if ( '' !== $size_name && is_file( $entry['from'] ) && $this->is_safe_upload_path( $entry['from'] ) ) {
                        $legacy_entries[ $size_name ] = $entry;
                    }
                }
            }

            $missing_legacy = array();
            if ( null !== $legacy_source ) {
                foreach ( $enabled as $size_name ) {
                    if ( ! isset( $registered[ $size_name ] ) || isset( $legacy_entries[ $size_name ] ) ) {
                        continue;
                    }

                    if ( ! $this->size_applies_to_image( $registered[ $size_name ], $width, $height ) ) {
                        continue;
                    }

                    $missing_legacy[] = $size_name;
                }
            }

            if ( empty( $missing_new ) && empty( $missing_legacy ) ) {
                return 0 === $scaled_count ? 'nothing' : $scaled_count;
            }

            // 生成缩略图时 WordPress 会先覆盖附件元数据；任何失败都必须把原记录写回去，
            // 否则已有尺寸会从元数据里消失，磁盘文件却还在。
            $metadata_before_generation = $metadata;
            $metadata_committed         = false;

            try {

                // 旧原图可能超过 WordPress 的大图阈值；这里只生成尺寸，不能顺手生成 -scaled。
                $disable_scaling = static function () {
                    return 0;
                };

                $processor = local_crush()->processor;
                $was       = $processor->is_auto_compress_suppressed();
                $processor->set_auto_compress_suppressed( true );

                $generate_sizes = function ( string $source, array $wanted ) use ( $attachment_id, $disable_scaling ) {
                    if ( empty( $wanted ) ) {
                        return array();
                    }

                    $filter = function ( $sizes ) use ( $wanted ) {
                        return array_intersect_key( is_array( $sizes ) ? $sizes : array(), array_flip( $wanted ) );
                    };

                    add_filter( 'intermediate_image_sizes_advanced', $filter, 20 );
                    add_filter( 'big_image_size_threshold', $disable_scaling, 20 );

                    $fresh = array();

                    // wp_generate_attachment_metadata 定义在后台文件里；wp-cron / WP-CLI 不会自动加载。
                    require_once ABSPATH . 'wp-admin/includes/image.php';

                    try {
                        $fresh = wp_generate_attachment_metadata( $attachment_id, $source );
                    } finally {
                        remove_filter( 'intermediate_image_sizes_advanced', $filter, 20 );
                        remove_filter( 'big_image_size_threshold', $disable_scaling, 20 );
                    }

                    return is_array( $fresh ) && ! empty( $fresh['sizes'] ) && is_array( $fresh['sizes'] ) ? $fresh['sizes'] : array();
                };

                $new_source = $file;
                if ( ! empty( $metadata['original_image'] ) ) {
                    $original_rel  = ( '.' === $file_dir ? '' : $file_dir . '/' ) . wp_basename( (string) $metadata['original_image'] );
                    $original_path = $base_dir . '/' . $original_rel;

                    if ( is_file( $original_path ) && $this->is_safe_upload_path( $original_path ) ) {
                        $new_source = $original_path;
                    }
                }

                try {
                    $new_sizes    = $generate_sizes( $new_source, $missing_new );
                    $legacy_sizes = null !== $legacy_source ? $generate_sizes( $legacy_source['path'], $missing_legacy ) : array();
                } finally {
                    $processor->set_auto_compress_suppressed( $was );
                }

                $merged          = $metadata;
                $merged['sizes'] = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
                $generated       = count( $legacy_sizes );

                // 两套缩略图都由 WordPress 独立生成，但新文件名要沿用旧文件的主体，
                // 这样前台按“双扩展名”规则才能找到对应的旧格式文件。
                $legacy_names = array();
                foreach ( $legacy_entries as $size_name => $entry ) {
                    $legacy_names[ $size_name ] = wp_basename( $entry['from'] );
                }
                foreach ( $legacy_sizes as $size_name => $size_data ) {
                    if ( ! empty( $size_data['file'] ) ) {
                        $legacy_names[ $size_name ] = (string) $size_data['file'];
                    }
                }

                foreach ( $new_sizes as $size_name => $size_data ) {
                    if ( ! in_array( $size_name, $missing_new, true ) ) {
                        continue;
                    }

                    if ( ! empty( $size_data['file'] ) && isset( $legacy_names[ $size_name ] ) ) {
                        $new_path     = $base_dir . '/' . $file_dir . '/' . $size_data['file'];
                        $new_extension = pathinfo( $size_data['file'], PATHINFO_EXTENSION );
                        $desired_name = $legacy_names[ $size_name ] . '.' . $new_extension;
                        $desired_path = $base_dir . '/' . $file_dir . '/' . $desired_name;

                        if ( $new_path !== $desired_path && is_file( $new_path ) ) {
                            if ( is_file( $desired_path ) ) {
                                if ( is_link( $desired_path ) ) {
                                    return null;
                                }

                                if ( $this->is_safe_upload_path( $new_path ) && ! is_link( $new_path ) ) {
                                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                                    unlink( $new_path );
                                }

                                if ( is_file( $desired_path ) ) {
                                    $size_data['file'] = $desired_name;
                                }
                            } else {
                                // phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename -- 目标路径已确认在 uploads 内，改名为同目录重命名，不会移出上传目录。
                                if ( ! $this->is_safe_upload_path( $desired_path )
                                    || ! rename( $new_path, $desired_path ) ) {
                                    return null;
                                }
                                // phpcs:enable

                                $size_data['file'] = $desired_name;
                            }
                        }
                    }

                    $merged['sizes'][ $size_name ] = $size_data;
                    $generated++;
                }

                if ( empty( $new_sizes ) && ! empty( $missing_new ) ) {
                    return null;
                }

                if ( 0 === $generated && empty( $new_sizes ) ) {
                    return 0 === $scaled_count ? 'nothing' : $scaled_count;
                }

                if ( ! $this->write_metadata( $attachment_id, $merged ) ) {
                    return null;
                }

                $metadata_committed = true;

                foreach ( $legacy_names as $size_name => $legacy_name ) {
                    if ( empty( $merged['sizes'][ $size_name ]['file'] ) ) {
                        continue;
                    }

                    $legacy_path = $base_dir . '/' . $file_dir . '/' . $legacy_name;
                    $new_path    = $base_dir . '/' . $file_dir . '/' . $merged['sizes'][ $size_name ]['file'];

                    if ( is_file( $legacy_path ) && is_file( $new_path ) ) {
                        local_crush()->output_processor->save_output_mapping( $attachment_id, $size_name, $legacy_path, $new_path );
                    }
                }

                return $generated + $scaled_count;
            } finally {
                if ( ! $metadata_committed ) {
                    $this->write_metadata( $attachment_id, $metadata_before_generation );
                }
            }
        } finally {
            local_crush()->processor->release_attachment_lock( $global_lock );
            local_crush()->processor->release_attachment_lock( $lock );
        }
    }

    /**
     * 找到 uploads 中未缩放的旧格式原图。
     *
     * 优先使用 original_image 映射；没有映射时再从双扩展名的新格式路径推导。
     * 任何文件名带 -scaled 的候选都不作为生成源。
     *
     * @param int        $attachment_id 附件 ID
     * @param mixed      $metadata       附件元数据
     * @return array|null path、size_type
     */
    private function find_legacy_original_source( int $attachment_id, $metadata ): ?array {
        $entries = local_crush()->output_processor->get_output_entries( $attachment_id );

        foreach ( array( 'original_image', 'full' ) as $wanted_type ) {
            foreach ( $entries as $entry ) {
                if ( $wanted_type !== (string) ( $entry['size_type'] ?? '' ) ) {
                    continue;
                }

                $source = (string) ( $entry['from'] ?? '' );
                $target = (string) ( $entry['to'] ?? '' );

                if ( ! is_file( $source ) || ! $this->is_safe_upload_path( $source ) || ! $this->is_double_extension_target( $target ) ) {
                    continue;
                }

                if ( preg_match( '/-scaled\.[^.]+$/i', wp_basename( $source ) ) ) {
                    continue;
                }

                return array( 'path' => $source, 'size_type' => $wanted_type );
            }
        }

        if ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
            $directory = dirname( (string) $metadata['file'] );
            $targets   = array();

            if ( ! empty( $metadata['original_image'] ) ) {
                $targets[] = (string) $metadata['original_image'];
            }

            // 没有 original_image 时，未缩放附件本身就是最大尺寸图。
            $targets[] = wp_basename( (string) $metadata['file'] );

            foreach ( $targets as $target ) {
                if ( ! $this->is_double_extension_target( $target ) ) {
                    continue;
                }

                $extension = pathinfo( $target, PATHINFO_EXTENSION );
                $relative  = substr( $target, 0, strlen( $target ) - strlen( $extension ) - 1 );
                $relative  = ( '.' === $directory ? '' : $directory . '/' ) . wp_basename( $relative );
                $path      = untrailingslashit( wp_normalize_path( wp_upload_dir()['basedir'] ) ) . '/' . ltrim( wp_normalize_path( $relative ), '/' );

                if ( is_file( $path ) && ! preg_match( '/-scaled\.[^.]+$/i', wp_basename( $path ) ) && $this->is_safe_upload_path( $path ) ) {
                    return array( 'path' => $path, 'size_type' => 'original_image' );
                }
            }
        }

        return null;
    }

    /**
     * 判断目标是否为插件双扩展名新格式文件。
     *
     * @param string $path 文件路径
     * @return bool
     */
    private function is_double_extension_target( string $path ): bool {
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, array( 'webp', 'avif', 'jxl' ), true ) ) {
            return false;
        }

        return '' !== pathinfo( substr( $path, 0, strlen( $path ) - strlen( $extension ) - 1 ), PATHINFO_EXTENSION );
    }

    /**
     * 判断某个尺寸对这张图是否适用
     *
     * 等比缩放是按最长边缩的：只要原图在任一维度大于目标就会产出（2000x1500 对
     * 1536x1536 会得到 1536x1152）。核心不产出的尺寸不能算“缺失”，否则每次点击都会
     * 白跑一遍，永远补不齐。
     *
     * @param array $config 尺寸配置
     * @param int   $width  原图宽
     * @param int   $height 原图高
     * @return bool
     */
    private function size_applies_to_image( array $config, int $width, int $height ): bool {
        $target_width  = isset( $config['width'] ) ? (int) $config['width'] : 0;
        $target_height = isset( $config['height'] ) ? (int) $config['height'] : 0;

        if ( 0 === $target_width && 0 === $target_height ) {
            return false;
        }

        if ( $width <= 0 || $height <= 0 ) {
            return false;
        }

        // 口径必须和 WordPress 核心一致：目标宽高都不小于原图时，核心直接跳过这个尺寸，
        // 裁剪尺寸（crop）也一样不例外。所以这里不能因为 crop 就认定“一定适用”，
        // 否则小图会被当成“有尺寸可补”，生成结果为空又被判成失败。
        return ( $target_width > 0 && $width > $target_width ) || ( $target_height > 0 && $height > $target_height );
    }

    /**
     * 规范化路径用于大小写不敏感的比较
     *
     * Windows 文件系统不区分大小写，比较缩放图与原图是否为同一文件时必须忽略大小写。
     *
     * @param string $path 路径
     * @return string
     */
    private function normalized_path( string $path ): string {
        $normalized = wp_normalize_path( $path );

        return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? strtolower( $normalized ) : $normalized;
    }

    /**
     * 计算附件的 -scaled 缩放图上下文
     *
     * @param array $metadata 附件元数据
     * @return array threshold、enabled、main_rel、orig_rel、source_rel、is_scaled
     */
    private function scaled_context( array $metadata ): array {
        $threshold = Local_Crush_Settings::image_size_threshold();
        $enabled   = Local_Crush_Settings::image_scale_enabled();

        $main_rel  = isset( $metadata['file'] ) ? ltrim( wp_normalize_path( (string) $metadata['file'] ), '/' ) : '';
        $orig_name = isset( $metadata['original_image'] ) ? (string) $metadata['original_image'] : '';
        $is_scaled = '' !== $orig_name && '' !== $main_rel;

        // original_image 存的是文件名，与主文件同目录，拼成完整相对路径
        $file_dir   = dirname( $main_rel );
        $orig_rel   = $is_scaled ? ( ( '.' === $file_dir ? '' : $file_dir . '/' ) . $orig_name ) : '';
        $source_rel = $is_scaled ? $orig_rel : $main_rel;

        return array(
            'threshold'  => $threshold,
            'enabled'    => $enabled,
            'main_rel'   => $main_rel,
            'orig_rel'   => $orig_rel,
            'source_rel' => $source_rel,
            'is_scaled'  => $is_scaled,
        );
    }

    /**
     * 删除主文件相关的压缩记录
     *
     * 缩放图的生成与去缩放都改变了主文件结构，full 与 original_image 两条记录
     * 描述的是旧文件，删除后让主文件回到待压缩状态，用户可重新压缩。
     *
     * @param int $attachment_id 附件 ID
     */
    private function forget_main_records( int $attachment_id ): void {
        $database = local_crush()->database;

        foreach ( array( 'full', 'original_image' ) as $size_type ) {
            $record = $database->get_record( $attachment_id, $size_type );

            if ( $record ) {
                $database->delete_record( (int) $record['id'] );
            }
        }
    }

    /**
     * 删除按钮：按当前缩放设置清理 -scaled 缩放图
     *
     * 关闭了自动缩放，或现有缩放图长边超过阈值（阈值被调小）时，删掉缩放图、
     * 让主文件退回未缩放的原图，并同步元数据、正文链接和压缩记录。
     * 缩放图长边不超阈值（符合当前设置）时不动。调用前须已持有附件锁。
     *
     * @param int $attachment_id 附件 ID
     * @return array|null deleted、replaced；处理失败返回 null
     */
    private function cleanup_scaled( int $attachment_id ): ?array {
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            return array( 'deleted' => 0, 'replaced' => 0 );
        }

        $context = $this->scaled_context( $metadata );

        if ( ! $context['is_scaled'] ) {
            return array( 'deleted' => 0, 'replaced' => 0 );
        }

        $upload   = wp_upload_dir();
        $base_dir = untrailingslashit( wp_normalize_path( $upload['basedir'] ) );
        $main_abs = $base_dir . '/' . ltrim( wp_normalize_path( $context['main_rel'] ), '/' );
        $orig_abs = $base_dir . '/' . ltrim( wp_normalize_path( $context['orig_rel'] ), '/' );

        // 原图必须还在，否则无法退回
        if ( ! is_file( $orig_abs ) ) {
            return array( 'deleted' => 0, 'replaced' => 0 );
        }

        // 关闭缩放，或缩放图长边超过阈值，才需要清理
        $need_cleanup = ! $context['enabled'];

        if ( ! $need_cleanup ) {
            if ( ! is_file( $main_abs ) ) {
                // 缩放图文件已不在，元数据还挂着 original_image，属悬空条目，清理元数据即可
                $need_cleanup = true;
            } else {
                $main_size = wp_getimagesize( $main_abs );

                if ( is_array( $main_size ) && ! empty( $main_size[0] ) && ! empty( $main_size[1] )
                    && max( (int) $main_size[0], (int) $main_size[1] ) > $context['threshold'] ) {
                    $need_cleanup = true;
                }
            }
        }

        if ( ! $need_cleanup ) {
            return array( 'deleted' => 0, 'replaced' => 0 );
        }

        $deleted  = 0;
        $replaced = 0;

        if ( is_file( $main_abs ) ) {
            // 绝不删到原图头上，也不删 uploads 外的路径
            if ( ! $this->is_safe_upload_path( $main_abs )
                || $this->normalized_path( $main_abs ) === $this->normalized_path( $orig_abs ) ) {
                return null;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( ! unlink( $main_abs ) || file_exists( $main_abs ) ) {
                return null;
            }

            $deleted = 1;

            // 全站固化的缩放图地址改回原图
            local_crush()->references->update_references(
                array(
                    array(
                        'from' => $main_abs,
                        'to'   => $orig_abs,
                    ),
                ),
                $replaced
            );
        }

        $metadata['file'] = $context['orig_rel'];
        unset( $metadata['original_image'] );

        $orig_size = wp_getimagesize( $orig_abs );

        if ( is_array( $orig_size ) && ! empty( $orig_size[0] ) && ! empty( $orig_size[1] ) ) {
            $metadata['width']  = (int) $orig_size[0];
            $metadata['height'] = (int) $orig_size[1];
        }

        clearstatcache( true, $orig_abs );
        $metadata['filesize'] = (int) filesize( $orig_abs );

        if ( ! $this->write_metadata( $attachment_id, $metadata ) ) {
            return null;
        }

        update_attached_file( $attachment_id, $context['orig_rel'] );

        $this->forget_main_records( $attachment_id );

        return array( 'deleted' => $deleted, 'replaced' => $replaced );
    }

    /**
     * 补生成按钮：按当前缩放设置补建或修正 -scaled 缩放图
     *
     * 开启自动缩放且未缩放原图长边超过阈值时，若无缩放图或缩放图长边超过阈值，
     * 就从原图重新生成 -scaled 缩放图并接管主文件，同步元数据、正文链接和压缩记录。
     * 调用前须已持有附件锁。
     *
     * @param int $attachment_id 附件 ID
     * @return array|null generated、replaced；处理失败返回 null
     */
    private function ensure_scaled( int $attachment_id ): ?array {
        if ( ! Local_Crush_Settings::image_scale_enabled() ) {
            return array( 'generated' => 0, 'replaced' => 0 );
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            return array( 'generated' => 0, 'replaced' => 0 );
        }

        $context    = $this->scaled_context( $metadata );
        $upload     = wp_upload_dir();
        $base_dir   = untrailingslashit( wp_normalize_path( $upload['basedir'] ) );
        $source_abs = $base_dir . '/' . ltrim( wp_normalize_path( $context['source_rel'] ), '/' );
        $main_abs   = $base_dir . '/' . ltrim( wp_normalize_path( $context['main_rel'] ), '/' );

        if ( ! is_file( $source_abs ) ) {
            return array( 'generated' => 0, 'replaced' => 0 );
        }

        $source_size = wp_getimagesize( $source_abs );

        if ( ! is_array( $source_size ) || empty( $source_size[0] ) || empty( $source_size[1] ) ) {
            return array( 'generated' => 0, 'replaced' => 0 );
        }

        // 原图没超阈值，不需要缩放图
        if ( max( (int) $source_size[0], (int) $source_size[1] ) <= $context['threshold'] ) {
            return array( 'generated' => 0, 'replaced' => 0 );
        }

        // 已有缩放图且长边不超阈值（符合当前设置），不用重建；超过则先删掉旧的
        if ( $context['is_scaled'] && is_file( $main_abs ) ) {
            $main_size = wp_getimagesize( $main_abs );

            if ( is_array( $main_size ) && ! empty( $main_size[0] ) && ! empty( $main_size[1] )
                && max( (int) $main_size[0], (int) $main_size[1] ) <= $context['threshold'] ) {
                return array( 'generated' => 0, 'replaced' => 0 );
            }

            if ( $this->is_safe_upload_path( $main_abs )
                && $this->normalized_path( $main_abs ) !== $this->normalized_path( $source_abs ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( ! unlink( $main_abs ) || file_exists( $main_abs ) ) {
                    return null;
                }
            }
        }

        $scaled = local_crush()->processor->generate_scaled_file( $source_abs, $context['threshold'] );

        if ( ! is_array( $scaled ) || empty( $scaled['path'] ) || ! is_file( $scaled['path'] ) ) {
            return null;
        }

        $scaled_abs = wp_normalize_path( (string) $scaled['path'] );
        $scaled_rel = ltrim( substr( $scaled_abs, strlen( $base_dir ) ), '/' );

        $replaced = 0;

        // 主文件名变化时，全站固化的旧地址改到新缩放图
        if ( $this->normalized_path( $scaled_rel ) !== $this->normalized_path( $context['main_rel'] ) ) {
            local_crush()->references->update_references(
                array(
                    array(
                        'from' => $main_abs,
                        'to'   => $scaled_abs,
                    ),
                ),
                $replaced
            );
        }

        $metadata['file']           = $scaled_rel;
        $metadata['original_image'] = wp_basename( $source_abs );
        $metadata['width']          = isset( $scaled['width'] ) ? (int) $scaled['width'] : (int) $source_size[0];
        $metadata['height']         = isset( $scaled['height'] ) ? (int) $scaled['height'] : (int) $source_size[1];

        clearstatcache( true, $scaled_abs );
        $metadata['filesize'] = isset( $scaled['filesize'] ) ? (int) $scaled['filesize'] : (int) filesize( $scaled_abs );

        if ( ! $this->write_metadata( $attachment_id, $metadata ) ) {
            return null;
        }

        update_attached_file( $attachment_id, $scaled_rel );

        $this->forget_main_records( $attachment_id );

        return array( 'generated' => 1, 'replaced' => $replaced );
    }

    /**
     * 写入元数据并读回校验
     *
     * 只比较尺寸集合不比较顺序：WordPress 按键名取用缩略图，顺序没有语义，
     * 其他插件重排顺序不再误判；真正增删尺寸仍对不上，失败保护还在。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $metadata      待写入的元数据
     * @return bool
     */
    private function write_metadata( int $attachment_id, array $metadata ): bool {
        wp_update_attachment_metadata( $attachment_id, $metadata );

        $stored  = wp_get_attachment_metadata( $attachment_id );
        $current = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? array_keys( $metadata['sizes'] ) : array();

        if ( ! is_array( $stored ) ) {
            return false;
        }

        $stored_sizes = isset( $stored['sizes'] ) && is_array( $stored['sizes'] ) ? array_keys( $stored['sizes'] ) : array();

        sort( $stored_sizes );
        sort( $current );

        return $stored_sizes === $current;
    }

    /**
     * 验证文件位于 uploads 目录内
     *
     * @param string $file_path 文件路径
     * @return bool
     */
    private function is_safe_upload_path( string $file_path ): bool {
        if ( false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );

        if ( false === $base_dir ) {
            return false;
        }

        $real_path = realpath( $file_path );

        if ( false !== $real_path && is_link( $real_path ) ) {
            return false;
        }

        // 文件已不存在时按规范化后的字面路径判断，保证删除动作只落在 uploads 内
        $candidate = false === $real_path ? $file_path : $real_path;
        $base_dir  = untrailingslashit( wp_normalize_path( $base_dir ) );
        $candidate = untrailingslashit( wp_normalize_path( $candidate ) );

        return 0 === strpos( $candidate, $base_dir . '/' );
    }
}
