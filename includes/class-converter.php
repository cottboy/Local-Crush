<?php
/**
 * 目标格式输出处理器
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 目标格式输出处理器
 *
 * 将勾选格式的图片（PNG/JPG/GIF）压缩输出为 WebP 或 AVIF，是统一压缩入口
 * 的一部分：结果小于源文件时生成真实的新文件并接管附件引用，同步附件路径、元数据、
 * MIME 和文章内已固化的图片链接。
 *
 * 新格式以"源文件名.目标格式"的双扩展名生成（photo.jpg → photo.jpg.webp）。旧格式文件
 * 是否留下由兼容格式回退开关决定：开启时原地保留并同样经过压缩，作为前台 <picture> 的
 * 兼容格式回退，回退地址由新格式名去掉最后一段扩展名确定性推导，不查询数据库；关闭时
 * 提交成功后直接删除源文件，新格式完整替换旧格式，磁盘上不留没人引用的副本。
 *
 * 单文件处理分成准备和提交两步。准备阶段只写目标文件和映射，提交阶段负责引用、
 * 链接，任何一步失败都会把引用退回源文件并记录失败，下次执行凭映射接管已有结果
 * 继续提交，不会重复编码。
 */
class Local_Crush_Output {

    /**
     * 目标格式输出映射 post meta 键（供恢复原图时反向处理）
     *
     * @var string
     */
    const OUTPUT_META_KEY = '_local_crush_output';

    /**
     * 正文链接扫描的单页文章数量
     *
     * @var int
     */
    const REFERENCE_BATCH_SIZE = 100;

    /**
     * 目标格式对应的编码工具注册名
     *
     * @var array
     */
    private $target_tools = array(
        'webp' => 'cwebp',
        'avif' => 'avifenc',
        'jxl'  => 'cjxl',
    );

    /**
     * 目标格式对应的 MIME 类型
     *
     * @var array
     */
    private $target_mimes = array(
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'jxl'  => 'image/jxl',
    );

    /**
     * 支持目标格式输出的源扩展名（jpg 涵盖 jpeg）
     *
     * @var array
     */
    private $source_formats = array( 'png', 'jpg', 'gif' );

    /**
     * 源格式对应的 MIME 类型
     *
     * @var array
     */
    private $format_mimes = array(
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'jxl'  => 'image/jxl',
    );

    /**
     * 转换链路产生的方向临时文件
     *
     * @var string[]
     */
    private $encode_temp_paths = array();

    /**
     * 获取目标格式输出设置
     *
     * @return array target: webp|avif|jxl, formats: 已勾选的源格式列表, apng: 是否转换 APNG
     */
    public function get_output_settings(): array {
        $general = get_option( 'local_crush_general', array() );

        // 默认 AVIF：存的目标格式必须在白名单里，缺失或被篡改一律按 AVIF 处理。
        $target = isset( $general['output_format'] ) && in_array( $general['output_format'], array( 'webp', 'avif', 'jxl' ), true )
            ? $general['output_format']
            : 'avif';

        $formats = array();
        foreach ( $this->source_formats as $format ) {
            if ( ! empty( $general[ 'output_' . $format ] ) ) {
                $formats[] = $format;
            }
        }

        return array(
            'target'  => $target,
            'formats' => $formats,
            'apng'    => ! empty( $general['output_apng'] ),
        );
    }

    /**
     * 判断文件是否应按设置输出为目标格式
     *
     * 只有 PNG（含 APNG）、JPG、GIF（含动图）三种源参与转换，是否转换由各自的勾选决定，
     * 其中 APNG 有自己的勾选框，不看 PNG 那个；WebP / AVIF / JXL 无论静图动图都只做同格式压缩。
     * 动图还要求动画层确认工具齐备，否则宁可保持原格式，也不能产出丢帧的静图。
     *
     * @param string $file_path 文件路径
     * @return bool
     */
    public function should_output_target( string $file_path ): bool {
        $settings = $this->get_output_settings();
        $format   = $this->format_of( $file_path );

        if ( 'png' === $format && Local_Crush_Animation::is_animated( $file_path ) ) {
            return ! empty( $settings['apng'] ) && Local_Crush_Animation::convert_supported( 'png', $settings['target'] );
        }

        if ( 'gif' === $format && Local_Crush_Animation::is_animated( $file_path ) ) {
            return in_array( 'gif', $settings['formats'], true )
                && Local_Crush_Animation::convert_supported( 'gif', $settings['target'] );
        }

        if ( ! in_array( $format, $settings['formats'], true ) ) {
            return false;
        }

        return true;
    }

    /**
     * 是否在转换后保留旧格式文件作为兼容格式回退
     *
     * 与前台输出共用同一个开关，但看的不是同一件事：这里决定转换要不要把旧格式文件留下。
     * 关掉时旧格式文件没有任何用处——前台不包 <picture>，正文链接也已改到新格式，
     * 留着只是一份既不被引用也没有浏览器会加载的磁盘垃圾，所以提交后直接删除。
     *
     * @return bool
     */
    public function fallback_retained(): bool {
        $general = get_option( 'local_crush_general', array() );

        return ! empty( $general['fallback_enabled'] );
    }

    /**
     * 将单个文件压缩为目标格式（准备阶段）
     *
     * 流程固定为：目标名重名时先给源文件改名 → 备份原图 → 转换生成新格式 →
     * 单独压缩旧格式。备份必须早于改名和转换，否则备份下来的是动过的文件；
     * 旧格式必须在转换之后再压缩，否则压缩损失会叠加到转换质量上。
     *
     * 只生成目标文件、压缩记录和恢复映射，不改动附件引用；引用同步由提交阶段
     * 统一完成，因此结果不更小或备份失败时源文件必然完好。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     源文件绝对路径
     * @param string $size_type     尺寸类型
     * @param string $target        本次操作固定的目标格式
     * @return array 压缩结果
     */
    public function compress_to_target_format( int $attachment_id, string $file_path, string $size_type = 'full', string $target = '' ): array {
        $result_template = array(
            'file_path'       => $file_path,
            'from'            => $file_path,
            'to'              => '',
            'status'          => 'failed',
            'message'         => '',
            'original_size'   => 0,
            'compressed_size' => 0,
        );

        if ( ! file_exists( $file_path ) ) {
            $result_template['message'] = __( '文件不存在', 'local-crush' );
            return $result_template;
        }

        if ( ! $this->is_safe_path( $file_path ) ) {
            $result_template['message'] = __( '文件路径不安全', 'local-crush' );
            return $result_template;
        }

        $settings = $this->get_output_settings();
        $target   = in_array( $target, array( 'webp', 'avif', 'jxl' ), true ) ? $target : $settings['target'];
        $format   = $this->format_of( $file_path );

        // 后缀不是 png 但内容其实是 APNG：不能按单帧静图转换，否则会丢动画。
        if ( 'png' !== $format && Local_Crush_Compressor::is_apng( $file_path ) ) {
            $result_template['status']  = 'skipped';
            $result_template['message'] = __( '文件后缀与内容不符（内容是 APNG 动画），已跳过以保护动画内容', 'local-crush' );
            return $result_template;
        }

        // 动图判定：APNG 与 GIF 的多帧情况由动画层处理，其余源一律静图路径
        $animation_plan = in_array( $format, array( 'png', 'gif' ), true ) ? Local_Crush_Animation::analyze( $file_path ) : null;
        $is_animation   = null !== $animation_plan && $animation_plan['frames'] > 1;

        if ( 'gif' === $format && Local_Crush_Compressor::is_animated_gif( $file_path ) && null === $animation_plan ) {
            $result_template['status'] = 'skipped';
            $result_template['message'] = __( '无法读取 GIF 动画信息，已跳过以保护动画内容', 'local-crush' );
            return $result_template;
        }

        if ( 'png' === $format && Local_Crush_Compressor::is_apng( $file_path ) && null === $animation_plan ) {
            $result_template['status']  = 'skipped';
            $result_template['message'] = __( '无法读取 APNG 动画信息，已跳过以保护动画内容', 'local-crush' );
            return $result_template;
        }

        // APNG 有自己的勾选框，不看 PNG 那个；其余源格式看各自的勾选。
        $format_enabled = ( $is_animation && 'png' === $format )
            ? ! empty( $settings['apng'] )
            : in_array( $format, $settings['formats'], true );

        if ( ! $format_enabled ) {
            $result_template['status']  = 'skipped';
            $result_template['message'] = __( '该格式未配置格式转换', 'local-crush' );
            return $result_template;
        }

        if ( $is_animation && ! Local_Crush_Animation::convert_supported( $format, $target ) ) {
            $result_template['status']  = 'skipped';
            $result_template['message'] = Local_Crush_Animation::skip_reason( $format, $target );
            return $result_template;
        }

        $original_size = (int) filesize( $file_path );
        $tool_name     = $target;

        // 已有映射说明插件此前确实把该文件转换过一次，可能是上次提交中断。
        // 只认映射，不能凭同名文件或大小猜测，否则会误把无关图片当作压缩结果接管。
        $entry          = $this->find_output_entry( $attachment_id, $size_type );
        $natural_target = $this->natural_target_path( $file_path, $target );

        if ( null !== $entry && $this->normalized_path( $entry['to'] ) !== $this->normalized_path( $natural_target ) ) {
            // 映射指向旧的命名规则，按新规则从头再来：先把引用退回源文件、清掉残留文件，
            // 否则附件路径谁都不认识，这次转换会被当成失败。
            if ( ! $this->discard_stale_output_entry( $attachment_id, $entry ) ) {
                $result_template['message']       = __( '无法清理旧的转换结果，已保留原文件', 'local-crush' );
                $result_template['original_size'] = $original_size;
                return $result_template;
            }
            $entry = null;
        }

        // 目标名已被占用时，先看占用者是不是本附件按同一源文件转出的结果：WordPress 会让
        // 宽高相同的两个尺寸（如 large 与 medium_large）共用同一张缩略图，先转的尺寸已经
        // 把这份文件转成了新格式。是就共用那份结果，不重复编码，也不会把前者留下的回退
        // 文件改名挪走；其余情况（上次转换中断的残留，或同名文件）才给源文件改一个
        // 独一无二的名字。新规则下唯一可能的冲突就是目标文件本身已存在：photo.jpg 与
        // photo.jpg.webp 不可能互相撞名，不必再扫目录比主名。
        if ( null === $entry && file_exists( $natural_target ) ) {
            $shared = $this->share_existing_output( $attachment_id, $file_path, $size_type, $natural_target, $original_size, $tool_name );

            if ( null !== $shared ) {
                return $shared;
            }

            $renamed = $this->rename_source_for_target( $attachment_id, $file_path );

            if ( null === $renamed ) {
                $result_template['message']       = __( '无法为源文件分配新文件名，已保留原文件', 'local-crush' );
                $result_template['original_size'] = $original_size;
                return $result_template;
            }

            $file_path                   = $renamed;
            $natural_target              = $this->natural_target_path( $file_path, $target );
            $result_template['file_path'] = $file_path;
            $result_template['from']     = $file_path;
        }

        if ( null !== $entry && $this->is_safe_path( $entry['to'] ) && (int) filesize( $entry['to'] ) < $original_size ) {
            // 上次已生成的转换结果仍然有效，直接接管，不重复编码。
            $adopted = $this->prepare_existing_output( $attachment_id, $file_path, $entry['to'], $size_type, $original_size, $tool_name );

            if ( isset( $adopted['status'] ) && 'success' === $adopted['status'] && $this->fallback_retained() ) {
                // 上次可能中断在旧格式压缩之前：这里补上兼容格式回退的压缩。
                $this->compress_fallback_file( $attachment_id, $file_path, $size_type );
            }

            return $adopted;
        }

        // 没有可接管的结果时重新编码；复用了旧映射的目标名，避免另起新名字留下多余文件。
        $target_path = null !== $entry ? $entry['to'] : $natural_target;

        $temp_token     = wp_generate_password( 12, false, false );
        $temp_output    = $file_path . '.lc-compress-' . $temp_token . '.' . $target;
        $encode_ok      = false;
        $error_msg      = '';
        $temp_source    = '';
        $command        = false;
        $animation_done = false;

        // 编码器可能删掉方向；先记下原图方向，输出后再按格式补回。
        $orientation = Local_Crush_Orientation::from_file( $file_path );

        if ( $is_animation ) {
            $animation = Local_Crush_Animation::convert( $file_path, $target, $temp_output );
            $animation_done = true;
            $encode_ok = $animation['success'];
            $error_msg = $animation['message'];
            $tool_name = $animation['tool'];
        } elseif ( 'gif' === $format ) {
            // 静态 GIF 只有一条帧，不用拆帧：WebP 交给 gif2webp、JPEG XL 交给 cjxl 直接读原文件
            //（cwebp 读 GIF 会丢透明，实测过），只有 AVIF 必须先经 GD 解成 PNG —— avifenc 不认 GIF。
            // 静态 GIF 的三条转换各用自己那一组参数：它们的工具链和别的路径都不一样。
            if ( 'webp' === $target ) {
                $gif2webp = Local_Crush_Animation::gif2webp_command( $file_path, $temp_output, 'gif_to_webp' );
                $command  = false === $gif2webp ? false : array( $gif2webp );
            } elseif ( 'jxl' === $target ) {
                $cjxl    = Local_Crush_Cjxl::build_command( $file_path, $temp_output, 'jxl' );
                $command = false === $cjxl ? false : array( $cjxl );
            } else {
                $temp_source = $file_path . '.lc-source-' . $temp_token . '.png';
                if ( ! function_exists( 'imagecreatefromgif' ) || ! function_exists( 'imagepng' ) ) {
                    $result_template['status']  = 'skipped';
                    $result_template['message'] = __( 'GIF 转 AVIF 需要 GD 扩展', 'local-crush' );
                    return $result_template;
                }
                if ( ! $this->decode_gif_to_png( $file_path, $temp_source ) ) {
                    if ( file_exists( $temp_source ) ) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                        unlink( $temp_source );
                    }
                    $result_template['message'] = __( 'GIF 压缩预处理失败', 'local-crush' );
                    return $result_template;
                }
                $command = $this->build_encode_command( $temp_source, $temp_output, $target, 'avif_convert_gif' );
            }
        } else {
            // WebP 与 JXL 与同格式压缩共用一组；AVIF 转格式只调 avifenc，与同格式压缩分开。
            $group   = 'avif' === $target ? 'avif_convert' : $target;
            $command = $this->build_encode_command( $file_path, $temp_output, $target, $group, $orientation );
        }

        if ( $animation_done ) {
            // 动画层已经执行完命令并核对过帧数、时长与透明，这里不再跑命令链
            if ( ! $encode_ok && '' === $error_msg ) {
                $error_msg = __( '动图转换失败', 'local-crush' );
            }
        } elseif ( false === $command ) {
            // 缺编码工具（cwebp/avifenc/cjxl）：环境未配齐，不写入压缩记录，保留为待处理。
            $this->cleanup_encode_temp_paths();
            $error_msg                 = __( '没有可用的压缩工具', 'local-crush' );
            $result_template['status'] = 'skipped';
        } else {
            // 命令链按顺序执行，任一步失败即整体失败且不再执行后续步骤
            $exec_result = array( 'success' => false, 'output' => '', 'return_code' => -1 );

            foreach ( $command as $step ) {
                $exec_result = Local_Crush_Tool_Base::run_command( $step );

                if ( ! $exec_result['success'] ) {
                    break;
                }
            }

            // 方向临时文件只服务于本次编码，命令跑完立刻清理。
            $this->cleanup_encode_temp_paths();

            clearstatcache( true, $temp_output );

            if ( ! $exec_result['success'] || ! file_exists( $temp_output ) || filesize( $temp_output ) <= 0 ) {
                $error_msg = __( '压缩失败', 'local-crush' );

                // 编码失败时清理可能残留的部分输出文件
                if ( file_exists( $temp_output ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $temp_output );
                }
            } elseif ( 'webp' === $target && $orientation > 1 && ! Local_Crush_Orientation::ensure_webp( $temp_output, $orientation ) ) {
                // WebP 编码成功但方向写不回去：按失败处理，避免产出横着的图。
                $error_msg = __( '压缩失败', 'local-crush' );

                if ( file_exists( $temp_output ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    unlink( $temp_output );
                }
            } else {
                $encode_ok = true;
            }
        }

        // 清理临时源文件
        if ( '' !== $temp_source && file_exists( $temp_source ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_source );
        }

        // 动画层与命令链都可能在本次请求内刚写出目标文件，必须绕过 stat 缓存取真实大小
        clearstatcache( true, $temp_output );

        if ( ! $encode_ok ) {
            $result_template['message'] = $error_msg;
            return $result_template;
        }

        $compressed_size = (int) filesize( $temp_output );

        if ( $compressed_size >= $original_size ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );

            // 转换后更大说明目标格式对该图片没有收益，保留原图并按 0% 记为已压缩。
            if ( ! $this->save_record( $attachment_id, $file_path, $size_type, $original_size, $original_size, $tool_name, 'success', '' ) ) {
                $result_template['message'] = __( '压缩记录保存失败，请重新压缩', 'local-crush' );
                return $result_template;
            }

            $this->remove_output_entry( $attachment_id, $size_type );

            // 复用了旧映射的目标名时，上一轮的转换结果文件会变成无人引用的孤儿：
            // 映射刚被删掉，它既不会被下次重跑覆盖，也不会被恢复流程删除。
            // 但宽高相同的两个尺寸可能共用这份结果：还有别的映射或元数据条目
            // 指着它时绝不能删，否则另一尺寸的图会短暂 404。
            if ( $entry && file_exists( $target_path ) && $this->is_safe_path( $target_path )
                && ! $this->target_referenced_elsewhere( $attachment_id, $target_path, $size_type ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $target_path );
            }

            $result_template['status']          = 'success';
            $result_template['message']         = __( '转换结果没有变小，已保留原图', 'local-crush' );
            $result_template['original_size']   = $original_size;
            $result_template['compressed_size'] = $original_size;
            return $result_template;
        }

        // 禁止把结果写到符号链接或上传目录之外。
        if ( is_link( $target_path ) || is_dir( $target_path ) || ! $this->is_safe_destination_path( $target_path ) ) {
            if ( file_exists( $temp_output ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $temp_output );
            }

            $result_template['message'] = __( '压缩结果文件写入失败', 'local-crush' );
            return $result_template;
        }

        // 先写入目标文件并保留临时文件，状态写入失败时源文件仍然完好。
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
        if ( ! copy( $temp_output, $target_path ) || ! file_exists( $target_path ) || (int) filesize( $target_path ) !== $compressed_size ) {
            if ( file_exists( $target_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $target_path );
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );
            $result_template['message'] = __( '压缩结果文件写入失败', 'local-crush' );
            return $result_template;
        }

        if ( ! $this->save_record( $attachment_id, $target_path, $size_type, $original_size, $compressed_size, $tool_name, 'success', '' ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $target_path );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );
            $result_template['message'] = __( '压缩记录保存失败，已保留原文件', 'local-crush' );
            return $result_template;
        }

        if ( ! $this->add_output_entry( $attachment_id, $size_type, $file_path, $target_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $target_path );
            $this->save_record( $attachment_id, $file_path, $size_type, $original_size, $original_size, $tool_name, 'failed', __( '恢复映射保存失败，已保留原文件', 'local-crush' ) );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_output );
            $result_template['message'] = __( '恢复映射保存失败，已保留原文件', 'local-crush' );
            return $result_template;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $temp_output );

        // 旧格式压缩成兼容格式回退：新格式已从未被压缩过的原图生成，这里再单独压缩旧格式。
        // 顺序不能反——先压缩旧格式再转换会让转换质量受损。关闭兼容格式回退时不必压缩，
        // 这个文件马上就会在提交成功后删除。
        if ( $this->fallback_retained() ) {
            $this->compress_fallback_file( $attachment_id, $file_path, $size_type );
        }

        return array(
            'file_path'       => $target_path,
            'from'            => $file_path,
            'to'              => $target_path,
            'size_type'       => $size_type,
            'status'          => 'success',
            'message'         => __( '压缩成功', 'local-crush' ),
            'original_size'   => $original_size,
            'compressed_size' => $compressed_size,
            'tool_name'       => $tool_name,
        );
    }

    /**
     * 接管此前已生成的转换结果
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     源文件绝对路径
     * @param string $target_path   已生成的结果文件绝对路径
     * @param string $size_type     尺寸类型
     * @param int    $original_size 源文件大小
     * @param string $tool_name     工具名称
     * @return array 压缩结果
     */
    private function prepare_existing_output( int $attachment_id, string $file_path, string $target_path, string $size_type, int $original_size, string $tool_name ): array {
        $compressed_size = (int) filesize( $target_path );

        if ( ! $this->save_record( $attachment_id, $target_path, $size_type, $original_size, $compressed_size, $tool_name, 'success', '' ) ) {
            return array(
                'file_path'       => $file_path,
                'from'            => $file_path,
                'to'              => '',
                'status'          => 'failed',
                'message'         => __( '压缩记录保存失败，已保留原文件', 'local-crush' ),
                'size_type'       => $size_type,
                'original_size'   => $original_size,
                'compressed_size' => 0,
            );
        }

        return array(
            'file_path'       => $target_path,
            'from'            => $file_path,
            'to'              => $target_path,
            'size_type'       => $size_type,
            'status'          => 'success',
            'message'         => __( '压缩成功', 'local-crush' ),
            'original_size'   => $original_size,
            'compressed_size' => $compressed_size,
            'tool_name'       => $tool_name,
        );
    }

    /**
     * 目标名被同附件同源文件的转换结果占着时，直接共用那份结果
     *
     * WordPress 会让宽高完全相同的两个尺寸（如 large 与 medium_large）共用同一张
     * 缩略图文件。先转的尺寸已经把这份文件转成新格式并留下旧格式回退，后转的尺寸
     * 若按改名流程另起炉灶，会把前者留下的回退文件改名挪走，回退列表从此缺一个
     * 尺寸，还多存一份一模一样的转换结果。共用则两个尺寸各自记一条映射（指向同一
     * 对文件），元数据由提交阶段统一改到新格式，回退文件也只有那一份。
     *
     * 占用者不是本附件按同一源文件转出的结果时返回 null，交回调用方走改名流程。
     *
     * @param int    $attachment_id  附件 ID
     * @param string $file_path      源文件绝对路径
     * @param string $size_type      尺寸类型
     * @param string $natural_target 被占用的目标路径
     * @param int    $original_size  源文件大小
     * @param string $tool_name      目标格式工具名
     * @return array|null 共用成功时返回转换结果
     */
    private function share_existing_output( int $attachment_id, string $file_path, string $size_type, string $natural_target, int $original_size, string $tool_name ): ?array {
        $owner_type = '';

        foreach ( $this->get_output_entries( $attachment_id ) as $candidate ) {
            if ( $this->normalized_path( (string) $candidate['to'] ) !== $this->normalized_path( $natural_target ) ) {
                continue;
            }

            // 占用者必须由同一个源文件转出，否则是无关文件占了名字，交给改名流程处理。
            if ( $this->normalized_path( (string) $candidate['from'] ) !== $this->normalized_path( $file_path ) ) {
                return null;
            }

            $owner_type = (string) $candidate['size_type'];
            break;
        }

        if ( '' === $owner_type || $owner_type === $size_type || ! is_file( $natural_target ) ) {
            return null;
        }

        clearstatcache( true, $natural_target );
        $compressed_size = (int) filesize( $natural_target );

        if ( $compressed_size <= 0 ) {
            return null;
        }

        // 记录沿用占用者的压缩前后大小，明细里两个尺寸的数字才能对得上。
        $owner_record      = local_crush()->database->get_record( $attachment_id, $owner_type );
        $record_original   = is_array( $owner_record ) ? max( 0, (int) $owner_record['original_size'] ) : $original_size;
        $record_compressed = is_array( $owner_record ) ? max( 0, (int) $owner_record['compressed_size'] ) : $compressed_size;

        if ( ! $this->save_record( $attachment_id, $natural_target, $size_type, $record_original, $record_compressed, $tool_name, 'success', '' ) ) {
            return array(
                'file_path'       => $file_path,
                'from'            => $file_path,
                'to'              => '',
                'status'          => 'failed',
                'message'         => __( '压缩记录保存失败，请重新压缩', 'local-crush' ),
                'original_size'   => $original_size,
                'compressed_size' => 0,
            );
        }

        if ( ! $this->add_output_entry( $attachment_id, $size_type, $file_path, $natural_target ) ) {
            $this->save_record( $attachment_id, $file_path, $size_type, $original_size, $original_size, $tool_name, 'failed', __( '恢复映射保存失败，已保留原文件', 'local-crush' ) );

            return array(
                'file_path'       => $file_path,
                'from'            => $file_path,
                'to'              => '',
                'status'          => 'failed',
                'message'         => __( '恢复映射保存失败，已保留原文件', 'local-crush' ),
                'original_size'   => $original_size,
                'compressed_size' => 0,
            );
        }

        // 回退文件就是占用者留下的那一份，把回退压缩记录也复制到本尺寸名下，
        // 否则这个尺寸的回退每轮批量都会被当成待处理，对已经压缩过的旧格式文件再压一遍。
        $this->clone_fallback_record( $attachment_id, $owner_type, $size_type, $file_path );

        return array(
            'file_path'       => $natural_target,
            'from'            => $file_path,
            'to'              => $natural_target,
            'size_type'       => $size_type,
            'status'          => 'success',
            'message'         => __( '该尺寸与另一尺寸共用同一份转换结果，未重复生成文件', 'local-crush' ),
            // 没有新文件产生，本次运行不重复计入节省字节数。
            'original_size'   => $compressed_size,
            'compressed_size' => $compressed_size,
            'tool_name'       => $tool_name,
        );
    }

    /**
     * 把另一尺寸已生成的转换结果认领到本尺寸名下
     *
     * 压缩主循环按文件去重后，共用文件的第二个尺寸不再自己走一遍转换流程，
     * 由调用方拿着第一份结果调这里补齐名册（映射、压缩记录与回退记录）。
     * 前提校验与共用逻辑一致：目标路径确实是本附件按同一源文件转出的结果，
     * 对不上时返回 null 交回调用方按独立文件处理。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     源文件绝对路径
     * @param string $size_type     认领方的尺寸类型
     * @param string $target_path   已生成的转换结果路径
     * @param int    $original_size 源文件大小
     * @param string $tool_name     目标格式工具名
     * @return array|null 认领成功时返回转换结果
     */
    public function adopt_shared_output( int $attachment_id, string $file_path, string $size_type, string $target_path, int $original_size, string $tool_name ): ?array {
        return $this->share_existing_output( $attachment_id, $file_path, $size_type, $target_path, $original_size, $tool_name );
    }

    /**
     * 共用转换结果时，把占用者的回退压缩记录复制到共用尺寸名下
     *
     * 回退文件只有一份，但压缩记录按“附件 + 尺寸”唯一：不复制的话，共用尺寸的回退
     * 每轮批量都会被当成待处理，对已经压缩过的旧格式文件再压一遍，白损一次质量。
     * 占用者自己还没有回退记录（上次中断在压缩回退之前）时不复制，等它补上后下轮对齐。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $owner_type    占用者的尺寸类型
     * @param string $size_type     共用尺寸的类型
     * @param string $fallback_path 共用的回退文件绝对路径
     */
    private function clone_fallback_record( int $attachment_id, string $owner_type, string $size_type, string $fallback_path ): void {
        $record = local_crush()->database->get_record( $attachment_id, $this->fallback_size_type( $owner_type ) );

        // 记录里存的是 uploads 内相对路径，必须与回退文件的相对路径比对。
        $fallback_relative = $this->get_relative_upload_path( $fallback_path );

        if ( ! is_array( $record )
            || 'success' !== ( isset( $record['status'] ) ? (string) $record['status'] : '' )
            || $this->normalized_path( (string) $record['file_path'] ) !== $this->normalized_path( $fallback_relative ) ) {
            return;
        }

        $this->save_record(
            $attachment_id,
            $fallback_path,
            $this->fallback_size_type( $size_type ),
            max( 0, (int) $record['original_size'] ),
            max( 0, (int) $record['compressed_size'] ),
            (string) ( isset( $record['tool_name'] ) ? $record['tool_name'] : '' ),
            'success',
            ''
        );
    }

    /**
     * 提交格式转换结果
     *
     * 顺序固定为：附件路径与元数据 → MIME → 文章内图片链接。
     * 旧格式文件是否保留由兼容格式回退开关决定：开启时原地留作回退，提交不删除任何源文件；
     * 关闭时提交成功后删除源文件，新格式完整替换。任一步失败都把引用退回源文件并把记录写成
     * 失败，下次执行凭映射继续提交，不会留下指向已删除文件的引用。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $output_map    size_type => array( from, to )
     * @return bool 是否提交成功
     */
    public function commit_attachment_format( int $attachment_id, array $output_map ): bool {
        if ( empty( $output_map ) ) {
            return true;
        }

        $entries    = $this->describe_entries( $output_map );
        $snapshot   = $this->get_reference_snapshot( $attachment_id );
        $target     = $this->target_of_entries( $entries );
        $mime       = isset( $this->target_mimes[ $target ] ) ? $this->target_mimes[ $target ] : '';

        if ( ! $this->sync_attachment_paths( $attachment_id, $entries, $target ) ) {
            return $this->abort_commit( $attachment_id, $snapshot, $entries, __( '附件路径同步失败，已保留原文件', 'local-crush' ) );
        }

        if ( '' !== $mime && ! $this->sync_attachment_mime( $attachment_id, $entries, $mime ) ) {
            return $this->abort_commit( $attachment_id, $snapshot, $entries, __( '附件 MIME 类型同步失败，已保留原文件', 'local-crush' ) );
        }

        if ( ! $this->update_content_references( $this->reference_pairs( $entries, false ) ) ) {
            return $this->abort_commit( $attachment_id, $snapshot, $entries, __( '文章图片链接替换失败，已保留原文件', 'local-crush' ) );
        }

        // 引用和正文链接都已指向新格式，这时删除旧格式源文件才不会留下任何悬空引用。
        $this->discard_replaced_sources( $attachment_id, $entries );

        return true;
    }

    /**
     * 删除已被新格式替换掉的旧格式源文件
     *
     * 只在关闭兼容格式回退时执行：此时旧格式文件前台不输出、正文链接也已改写，留着只是
     * 没人会加载的磁盘垃圾。原图备份在提交前就已单独存过，恢复原图不受影响。
     *
     * 删除失败不阻断提交：转换结果已经接管全部引用，残留文件只是垃圾，仍可由媒体库和
     * 设置页的删除入口清理，为它把整个转换判失败反而会让图片退回旧格式。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $entries       转换条目
     */
    private function discard_replaced_sources( int $attachment_id, array $entries ): void {
        if ( $this->fallback_retained() ) {
            return;
        }

        $discarded = array();

        foreach ( $entries as $entry ) {
            $source = $entry['from'];

            // 上次提交已经删掉过的源文件不会再次出现；符号链接一律不当文件删。
            if ( ! is_file( $source ) || is_link( $source ) || ! $this->is_safe_path( $source ) ) {
                continue;
            }

            // 路径由映射读出，仍按不可信数据校验：绝不能删到转换结果自己头上。
            if ( $this->normalized_path( $source ) === $this->normalized_path( $entry['to'] ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( ! unlink( $source ) ) {
                continue;
            }

            $discarded[] = $entry['size_type'];
        }

        // 回退文件已不存在，指向它的压缩记录只会让状态与实际不符。
        if ( $discarded ) {
            $this->forget_fallback_records( $attachment_id, $discarded );
        }
    }

    /**
     * 获取附件当前的引用快照，供提交失败时退回
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    private function get_reference_snapshot( int $attachment_id ): array {
        return array(
            'attached' => get_post_meta( $attachment_id, '_wp_attached_file', true ),
            'metadata' => wp_get_attachment_metadata( $attachment_id ),
            'mime'     => get_post_mime_type( $attachment_id ),
        );
    }

    /**
     * 提交失败时退回引用、链接和记录
     *
     * @param int    $attachment_id 附件 ID
     * @param array  $snapshot      引用快照
     * @param array  $entries       转换条目
     * @param string $message       失败原因
     * @return bool 恒为 false
     */
    private function abort_commit( int $attachment_id, array $snapshot, array $entries, string $message ): bool {
        // 源文件原地保留，回退引用永远有文件可指。
        $this->restore_reference_snapshot( $attachment_id, $snapshot );
        $this->update_content_references( $this->reference_pairs( $entries, true ) );

        foreach ( $entries as $entry ) {
            $size  = file_exists( $entry['from'] ) ? (int) filesize( $entry['from'] ) : 0;
            $saved = $this->save_record( $attachment_id, $entry['from'], $entry['size_type'], $size, $size, 'target-output', 'failed', $message );

            if ( ! $saved ) {
                // 连失败记录都写不进去时，绝不能留下成功记录造成误判。
                local_crush()->database->delete_records_by_attachment( $attachment_id );
            }
        }

        return false;
    }

    /**
     * 把附件路径、元数据和 MIME 退回快照值
     *
     * @param int   $attachment_id 附件 ID
     * @param array $snapshot      引用快照
     */
    private function restore_reference_snapshot( int $attachment_id, array $snapshot ): void {
        $attached = isset( $snapshot['attached'] ) ? (string) $snapshot['attached'] : '';

        if ( '' === $attached ) {
            delete_post_meta( $attachment_id, '_wp_attached_file' );
        } else {
            update_post_meta( $attachment_id, '_wp_attached_file', wp_slash( $attached ) );
        }

        $metadata = isset( $snapshot['metadata'] ) ? $snapshot['metadata'] : null;
        if ( is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        } else {
            delete_post_meta( $attachment_id, '_wp_attachment_metadata' );
        }

        $mime = isset( $snapshot['mime'] ) ? (string) $snapshot['mime'] : '';
        if ( '' !== $mime && $mime !== get_post_mime_type( $attachment_id ) ) {
            wp_update_post(
                array(
                    'ID'             => $attachment_id,
                    'post_mime_type' => $mime,
                ),
                true
            );
        }

        clean_post_cache( $attachment_id );
    }

    /**
     * 同步转换后的附件路径与元数据
     *
     * @param int   $attachment_id 附件 ID
     * @param array $entries       转换条目
     * @param string $target       目标格式
     * @return bool
     */
    private function sync_attachment_paths( int $attachment_id, array $entries, string $target ): bool {
        $full     = isset( $entries['full'] ) ? $entries['full'] : null;
        $metadata = wp_get_attachment_metadata( $attachment_id );
        $attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

        if ( null !== $full ) {
            $known = array(
                $this->normalized_path( $full['from_relative'] ),
                $this->normalized_path( $full['to_relative'] ),
            );

            // 附件路径和元数据都不认识这次转换，说明引用已被外部改动，不能盲目覆盖。
            if ( ! in_array( $this->normalized_path( $attached ), $known, true )
                && ! ( is_array( $metadata ) && ! empty( $metadata['file'] ) && in_array( $this->normalized_path( (string) $metadata['file'] ), $known, true ) ) ) {
                return false;
            }

            if ( ! $this->write_attached_file( $attachment_id, $full['to'] ) ) {
                return false;
            }
        }

        if ( ! is_array( $metadata ) || empty( $metadata['file'] ) ) {
            // 允许没有完整元数据的图片（第三方上传）：按目标图片的真实信息建立，读不到就不伪造。
            $first   = null !== $full ? $full : reset( $entries );
            $source  = is_array( $first ) ? $first['to'] : '';
            $created = $this->build_attachment_metadata( $source );

            if ( ! is_array( $created ) ) {
                return null === $full;
            }

            return $this->write_metadata( $attachment_id, $created );
        }

        return $this->write_metadata( $attachment_id, $this->apply_converted_metadata( $metadata, $entries, $target ) );
    }

    /**
     * 按转换条目更新元数据中的文件名与 MIME
     *
     * @param array  $metadata 现有元数据
     * @param array  $entries  转换条目
     * @param string $target   目标格式
     * @return array
     */
    private function apply_converted_metadata( array $metadata, array $entries, string $target ): array {
        $mime = isset( $this->target_mimes[ $target ] ) ? $this->target_mimes[ $target ] : '';

        if ( isset( $entries['full'] ) ) {
            $metadata['file']     = $entries['full']['to_relative'];
            $metadata['filesize'] = file_exists( $entries['full']['to'] ) ? (int) filesize( $entries['full']['to'] ) : 0;
        }

        if ( isset( $entries['original_image'] ) && ! empty( $metadata['original_image'] ) ) {
            $metadata['original_image'] = $entries['original_image']['to_name'];
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                $entry = isset( $entries[ $size_name ] ) ? $entries[ $size_name ] : null;

                if ( null === $entry && ! empty( $size_data['file'] ) ) {
                    // 尺寸名与映射不一致时按文件名匹配，避免元数据停留在旧文件。
                    foreach ( $entries as $candidate ) {
                        if ( $this->normalized_path( (string) $size_data['file'] ) === $this->normalized_path( $candidate['from_name'] )
                            || $this->normalized_path( (string) $size_data['file'] ) === $this->normalized_path( $candidate['to_name'] ) ) {
                            $entry = $candidate;
                            break;
                        }
                    }
                }

                if ( null === $entry ) {
                    continue;
                }

                $metadata['sizes'][ $size_name ]['file']      = $entry['to_name'];
                $metadata['sizes'][ $size_name ]['mime-type'] = $mime;
            }
        }

        return $metadata;
    }

    /**
     * 为缺少元数据的图片读取真实信息
     *
     * @param string $file_path 图片绝对路径
     * @return array|null 读不到真实尺寸时返回 null
     */
    private function build_attachment_metadata( string $file_path ) {
        if ( '' === $file_path || ! file_exists( $file_path ) ) {
            return null;
        }

        $size = wp_getimagesize( $file_path );

        if ( empty( $size[0] ) || empty( $size[1] ) ) {
            return null;
        }

        $metadata = array(
            'file'     => $this->get_relative_upload_path( $file_path ),
            'width'    => (int) $size[0],
            'height'   => (int) $size[1],
            'filesize' => (int) filesize( $file_path ),
        );

        if ( ! empty( $size['mime'] ) ) {
            $metadata['mime-type'] = (string) $size['mime'];
        }

        return $metadata;
    }

    /**
     * 同步附件级 MIME（只跟随主文件）
     *
     * @param int    $attachment_id 附件 ID
     * @param array  $entries       转换条目
     * @param string $mime          目标 MIME
     * @return bool
     */
    private function sync_attachment_mime( int $attachment_id, array $entries, string $mime ): bool {
        if ( ! isset( $entries['full'] ) ) {
            return true;
        }

        $updated = wp_update_post(
            array(
                'ID'             => $attachment_id,
                'post_mime_type' => $mime,
            ),
            true
        );

        return ! is_wp_error( $updated ) && $mime === get_post_mime_type( $attachment_id );
    }

    /**
     * 写入附件路径并读回校验
     *
     * 只传相对路径：update_attached_file 内部用 str_starts_with 与 uploads 的 basedir
     * 逐字符比对，Windows 下 basedir 是反斜杠，而本插件的路径一律是正斜杠，
     * 传绝对路径会匹配失败并把整个绝对路径存进 _wp_attached_file，
     * 之后 get_attached_file 会拼出 uploads + 绝对路径 的双重前缀。
     *
     * update_post_meta 在值未变化时同样返回 false，因此一律以读回结果为准。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $absolute_path 目标绝对路径
     * @return bool
     */
    private function write_attached_file( int $attachment_id, string $absolute_path ): bool {
        // uploads 外的路径一律不写，否则会把任意绝对路径存进 _wp_attached_file。
        if ( ! $this->is_safe_destination_path( $absolute_path ) ) {
            return false;
        }

        $relative = $this->get_relative_upload_path( $absolute_path );

        update_attached_file( $attachment_id, $relative );

        return $this->normalized_path( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) ) === $this->normalized_path( $relative );
    }

    /**
     * 写入附件元数据并读回校验
     *
     * @param int   $attachment_id 附件 ID
     * @param array $metadata      期望的元数据
     * @return bool
     */
    private function write_metadata( int $attachment_id, array $metadata ): bool {
        if ( ! empty( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        $saved = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $saved ) ) {
            return empty( $metadata );
        }

        if ( isset( $metadata['file'] ) && $this->normalized_path( (string) $saved['file'] ) !== $this->normalized_path( (string) $metadata['file'] ) ) {
            return false;
        }

        if ( isset( $metadata['original_image'] ) && (string) $saved['original_image'] !== (string) $metadata['original_image'] ) {
            return false;
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! isset( $saved['sizes'][ $size_name ]['file'] ) || $saved['sizes'][ $size_name ]['file'] !== $size_data['file'] ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 获取附件的转换映射条目（绝对路径形式，按尺寸类型索引）
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function get_output_entries( int $attachment_id ): array {
        $upload_dir = wp_upload_dir();
        $base_dir   = untrailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
        $entries    = array();

        foreach ( $this->read_output_entries( $attachment_id ) as $entry ) {
            $entries[] = array(
                'size_type'     => $entry['size_type'],
                'from'          => $base_dir . '/' . $entry['from'],
                'to'            => $base_dir . '/' . $entry['to'],
                'from_relative' => $entry['from'],
                'to_relative'   => $entry['to'],
                'from_name'     => basename( $entry['from'] ),
                'to_name'       => basename( $entry['to'] ),
            );
        }

        return $entries;
    }

    /**
     * 该文件是否是本附件的格式转换产物
     *
     * 只认转换映射，不靠文件名的双扩展名猜测：映射才是插件确实转换过这个文件的凭据。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     文件绝对路径
     * @return bool
     */
    public function is_conversion_output( int $attachment_id, string $file_path ): bool {
        if ( '' === $file_path ) {
            return false;
        }

        $candidate = $this->normalized_path( $file_path );

        foreach ( $this->get_output_entries( $attachment_id ) as $entry ) {
            if ( $this->normalized_path( $entry['to'] ) === $candidate ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 读取校验后的映射原始数据
     *
     * 映射是持久化数据，取出时按不可信处理：只接受 uploads 内的相对路径。
     *
     * @param int $attachment_id 附件 ID
     * @return array size_type => array( from, to, size_type )
     */
    private function read_output_entries( int $attachment_id ): array {
        $stored = get_post_meta( $attachment_id, self::OUTPUT_META_KEY, true );

        if ( ! is_array( $stored ) ) {
            return array();
        }

        $entries = array();

        foreach ( $stored as $entry ) {
            if ( empty( $entry['from'] ) || empty( $entry['to'] ) ) {
                continue;
            }

            $from = $this->sanitize_relative_path( (string) $entry['from'] );
            $to   = $this->sanitize_relative_path( (string) $entry['to'] );

            if ( '' === $from || '' === $to ) {
                continue;
            }

            $size_type                          = isset( $entry['size_type'] ) ? sanitize_text_field( (string) $entry['size_type'] ) : '';
            $size_type                          = '' === $size_type ? 'full' : $size_type;
            $entries[ $size_type ]              = array(
                'from'      => $from,
                'to'        => $to,
                'size_type' => $size_type,
            );
        }

        return $entries;
    }

    /**
     * 校验并规范化 uploads 内的相对路径
     *
     * @param string $path 相对路径
     * @return string 非法时返回空字符串
     */
    private function sanitize_relative_path( string $path ): string {
        $normalized = ltrim( wp_normalize_path( $path ), '/' );

        if ( '' === $normalized || false !== strpos( $normalized, '..' ) || false !== strpos( $normalized, ':' ) || false !== strpos( $normalized, "\0" ) ) {
            return '';
        }

        return $normalized;
    }

    /**
     * 按尺寸类型删除转换映射条目
     *
     * @param int    $attachment_id 附件 ID
     * @param string $size_type     尺寸类型
     * @return bool 没有条目或删除成功时为 true
     */
    public function remove_output_entry( int $attachment_id, string $size_type ): bool {
        $entries = $this->read_output_entries( $attachment_id );

        if ( ! isset( $entries[ $size_type ] ) ) {
            return true;
        }

        unset( $entries[ $size_type ] );

        return $this->store_output_entries( $attachment_id, $entries );
    }

    /**
     * 查找该尺寸已有的转换映射
     *
     * @param int    $attachment_id 附件 ID
     * @param string $size_type     尺寸类型
     * @return array|null
     */
    private function find_output_entry( int $attachment_id, string $size_type ) {
        foreach ( $this->get_output_entries( $attachment_id ) as $entry ) {
            if ( $entry['size_type'] === $size_type ) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * 保存同尺寸的新旧文件对应关系。
     *
     * 缩略图可以由 WordPress 分别生成，不经过格式转换；保存映射后，删除和前台回退
     * 仍能知道两个独立生成的文件属于同一个尺寸。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $size_type     尺寸类型
     * @param string $from_path     旧格式文件绝对路径
     * @param string $to_path       新格式文件绝对路径
     * @return bool
     */
    public function save_output_mapping( int $attachment_id, string $size_type, string $from_path, string $to_path ): bool {
        if ( ! is_file( $from_path ) || ! is_file( $to_path ) || ! $this->is_safe_path( $from_path ) || ! $this->is_safe_path( $to_path ) ) {
            return false;
        }

        return $this->add_output_entry( $attachment_id, $size_type, $from_path, $to_path );
    }

    /**
     * 写入转换映射，供中断续跑和恢复原图使用
     *
     * @param int    $attachment_id 附件 ID
     * @param string $size_type     尺寸类型
     * @param string $from_path     源文件绝对路径
     * @param string $to_path       结果文件绝对路径
     * @return bool 是否保存成功
     */
    private function add_output_entry( int $attachment_id, string $size_type, string $from_path, string $to_path ): bool {
        $entries                    = $this->read_output_entries( $attachment_id );
        $entries[ $size_type ]      = array(
            'from'      => $this->get_relative_upload_path( $from_path ),
            'to'        => $this->get_relative_upload_path( $to_path ),
            'size_type' => $size_type,
        );

        return $this->store_output_entries( $attachment_id, $entries );
    }

    /**
     * 持久化映射并读回校验
     *
     * 映射只存 uploads 内相对路径，避免 Windows 绝对路径的反斜杠在元数据写入时被剥掉。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $entries       size_type => 映射条目
     * @return bool
     */
    private function store_output_entries( int $attachment_id, array $entries ): bool {
        if ( empty( $entries ) ) {
            return $this->forget_output_entries( $attachment_id );
        }

        update_post_meta( $attachment_id, self::OUTPUT_META_KEY, wp_slash( array_values( $entries ) ) );
        $saved = $this->read_output_entries( $attachment_id );

        foreach ( $entries as $size_type => $entry ) {
            if ( ! isset( $saved[ $size_type ] )
                || $saved[ $size_type ]['from'] !== $entry['from']
                || $saved[ $size_type ]['to'] !== $entry['to'] ) {
                return false;
            }
        }

        return count( $saved ) === count( $entries );
    }

    /**
     * 删除转换映射
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function forget_output_entries( int $attachment_id ): bool {
        if ( '' === get_post_meta( $attachment_id, self::OUTPUT_META_KEY, true ) ) {
            return true;
        }

        delete_post_meta( $attachment_id, self::OUTPUT_META_KEY );

        return '' === get_post_meta( $attachment_id, self::OUTPUT_META_KEY, true );
    }

    /**
     * 把转换条目整理成提交阶段使用的统一结构
     *
     * @param array $output_map size_type => array( from, to )
     * @return array
     */
    private function describe_entries( array $output_map ): array {
        $entries = array();

        foreach ( $output_map as $size_type => $entry ) {
            if ( empty( $entry['from'] ) || empty( $entry['to'] ) ) {
                continue;
            }

            $from_relative = $this->get_relative_upload_path( $entry['from'] );
            $to_relative   = $this->get_relative_upload_path( $entry['to'] );

            $entries[ $size_type ] = array(
                'size_type'     => (string) $size_type,
                'from'          => $entry['from'],
                'to'            => $entry['to'],
                'from_relative' => $from_relative,
                'to_relative'   => $to_relative,
                'from_name'     => basename( $from_relative ),
                'to_name'       => basename( $to_relative ),
            );
        }

        return $entries;
    }

    /**
     * 推断本批转换条目的目标格式
     *
     * @param array $entries 条目集合
     * @return string webp|avif，无法判断时返回空字符串
     */
    private function target_of_entries( array $entries ): string {
        foreach ( $entries as $entry ) {
            $format = $this->format_of( $entry['to'] );

            // 以目标格式映射表为准，新增格式时不会再漏掉这里
            if ( isset( $this->target_mimes[ $format ] ) ) {
                return $format;
            }
        }

        return '';
    }

    /**
     * 生成正文链接替换用的路径对
     *
     * @param array $entries 条目集合
     * @param bool  $reverse 是否反向（转换结果 → 源文件）
     * @return array
     */
    private function reference_pairs( array $entries, bool $reverse ): array {
        $pairs = array();

        foreach ( $entries as $entry ) {
            $pairs[] = $reverse
                ? array( 'from' => $entry['to'], 'to' => $entry['from'] )
                : array( 'from' => $entry['from'], 'to' => $entry['to'] );
        }

        return $pairs;
    }

    /**
     * 恢复格式转换前的引用与正文链接
     *
     * 只处理引用和链接，不删除任何文件；调用方需先确认备份已经写回原路径。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $entries       需要恢复的映射条目（get_output_entries 的形式）
     * @return bool
     */
    public function revert_attachment_format( int $attachment_id, array $entries ): bool {
        if ( empty( $entries ) ) {
            return true;
        }

        $mapped   = array();
        foreach ( $entries as $entry ) {
            $mapped[ $entry['size_type'] ] = $entry;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        $attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
        $full     = isset( $mapped['full'] ) ? $mapped['full'] : null;

        if ( null !== $full ) {
            $points_to_target = $this->normalized_path( $attached ) === $this->normalized_path( $full['to_relative'] )
                || ( is_array( $metadata ) && ! empty( $metadata['file'] ) && $this->normalized_path( $metadata['file'] ) === $this->normalized_path( $full['to_relative'] ) );
            $points_to_source = $this->normalized_path( $attached ) === $this->normalized_path( $full['from_relative'] )
                || ( is_array( $metadata ) && ! empty( $metadata['file'] ) && $this->normalized_path( $metadata['file'] ) === $this->normalized_path( $full['from_relative'] ) );

            // 既不是转换结果也不是源文件，说明引用已被外部改动，不能盲目写回。
            if ( ! $points_to_target && ! $points_to_source ) {
                return false;
            }

            if ( $points_to_target && ! $this->write_attached_file( $attachment_id, $full['from'] ) ) {
                return false;
            }
        }

        if ( is_array( $metadata ) ) {
            $metadata = $this->apply_reverted_metadata( $metadata, $mapped );

            if ( ! $this->write_metadata( $attachment_id, $metadata ) ) {
                return false;
            }
        }

        if ( null !== $full ) {
            $source_mime = $this->source_mime_of( $full['from'] );

            if ( '' !== $source_mime && $source_mime !== get_post_mime_type( $attachment_id ) ) {
                $updated = wp_update_post(
                    array(
                        'ID'             => $attachment_id,
                        'post_mime_type' => $source_mime,
                    ),
                    true
                );

                if ( is_wp_error( $updated ) || $source_mime !== get_post_mime_type( $attachment_id ) ) {
                    return false;
                }
            }
        }

        return $this->update_content_references( $this->reference_pairs( $mapped, true ) );
    }

    /**
     * 按映射把元数据中的文件名改回源格式
     *
     * @param array $metadata 现有元数据
     * @param array $mapped   size_type => 条目
     * @return array
     */
    private function apply_reverted_metadata( array $metadata, array $mapped ): array {
        if ( isset( $mapped['full'] ) && ! empty( $metadata['file'] )
            && (
                $this->normalized_path( $metadata['file'] ) === $this->normalized_path( $mapped['full']['to_relative'] )
                || $this->normalized_path( $metadata['file'] ) === $this->normalized_path( $mapped['full']['from_relative'] )
            ) ) {
            $metadata['file'] = $mapped['full']['from_relative'];

            if ( file_exists( $mapped['full']['from'] ) ) {
                $metadata['filesize'] = (int) filesize( $mapped['full']['from'] );
            }
        }

        if ( isset( $mapped['original_image'] ) && ! empty( $metadata['original_image'] ) ) {
            $entry = $mapped['original_image'];

            if ( in_array(
                $this->normalized_path( (string) $metadata['original_image'] ),
                array( $this->normalized_path( $entry['to_name'] ), $this->normalized_path( $entry['from_name'] ) ),
                true
            ) ) {
                $metadata['original_image'] = $entry['from_name'];
            }
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $entry = isset( $mapped[ $size_name ] ) ? $mapped[ $size_name ] : null;

                if ( null === $entry ) {
                    foreach ( $mapped as $mapped_entry ) {
                        if ( $this->normalized_path( $size_data['file'] ) === $this->normalized_path( $mapped_entry['to_name'] ) ) {
                            $entry = $mapped_entry;
                            break;
                        }
                    }
                }

                if ( null === $entry ) {
                    continue;
                }

                $metadata['sizes'][ $size_name ]['file']      = $entry['from_name'];
                $metadata['sizes'][ $size_name ]['mime-type'] = $this->source_mime_of( $entry['from'] );
            }
        }

        return $metadata;
    }

    /**
     * 获取源格式对应的 MIME
     *
     * @param string $file_path 文件路径
     * @return string
     */
    private function source_mime_of( string $file_path ): string {
        $format = $this->format_of( $file_path );

        return isset( $this->format_mimes[ $format ] ) ? $this->format_mimes[ $format ] : '';
    }

    /**
     * 双扩展名规则下的目标文件路径
     *
     * 新格式文件名 = 源文件完整文件名 + "." + 目标格式，如 photo.jpg → photo.jpg.webp。
     * 源文件原地不动仍作回退，前台去掉最后一段扩展名即可确定性推导回退地址。
     *
     * @param string $file_path 源文件绝对路径
     * @param string $target    目标格式
     * @return string
     */
    private function natural_target_path( string $file_path, string $target ): string {
        return dirname( $file_path ) . '/' . basename( $file_path ) . '.' . $target;
    }

    /**
     * 转换结果是否还被本附件的其他尺寸引用
     *
     * 宽高相同的两个尺寸（如 large 与 medium_large）可能共用同一份转换结果：
     * 检查其他映射条目与元数据里的缩略图条目，只要还有一处指着它就不能删，
     * 否则那个尺寸的新格式图会失去文件。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $target_path   转换结果路径
     * @param string $size_type     当前处理的尺寸类型（不算它自己）
     * @return bool
     */
    private function target_referenced_elsewhere( int $attachment_id, string $target_path, string $size_type ): bool {
        $candidate   = $this->normalized_path( $target_path );
        $target_name = wp_basename( $target_path );

        foreach ( $this->get_output_entries( $attachment_id ) as $other ) {
            if ( (string) $other['size_type'] === $size_type ) {
                continue;
            }

            if ( $this->normalized_path( (string) $other['to'] ) === $candidate ) {
                return true;
            }
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_data ) {
                if ( is_array( $size_data ) && ! empty( $size_data['file'] )
                    && $this->normalized_path( (string) $size_data['file'] ) === $this->normalized_path( $target_name ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 丢弃指向旧命名规则的转换映射，按新规则从头再来
     *
     * 映射的 to 与新规则算出的目标名不一致，说明它是旧方案留下的。要真的"重新开始"，
     * 必须先把附件引用和正文链接退回源文件、删掉磁盘上残留的旧目标文件，最后才删映射；
     * 只删映射会让 _wp_attached_file 既不认识源文件也不认识新目标，同步直接失败。
     * 任一步失败都返回 false，保留原映射等下次重试。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $entry         映射条目（get_output_entries 的形式）
     * @return bool 是否清理成功
     */
    private function discard_stale_output_entry( int $attachment_id, array $entry ): bool {
        $source = isset( $entry['from'] ) ? (string) $entry['from'] : '';
        $stale  = isset( $entry['to'] ) ? (string) $entry['to'] : '';

        // 源文件（要退回并留作回退的那个）已经不在了：旧目标就是仅存的图片，删掉会让附件
        // 失去全部文件。保留映射等下次重试，前台也会因去掉扩展名后无后缀而不包 <picture>。
        if ( '' === $source || ! is_file( $source ) ) {
            return false;
        }

        // 先把附件路径、元数据、MIME 和正文链接退回源文件，否则删掉旧目标后引用会悬空。
        if ( ! $this->revert_attachment_format( $attachment_id, array( $entry ) ) ) {
            return false;
        }

        clearstatcache( true, $stale );

        // 删除残留的旧目标文件：路径安全、是普通文件、不是符号链接，且不能就是源文件本身，
        // 全部满足才 unlink，宁可不删也不能误伤。文件已经不在了（上次清理过）时不阻塞。
        if ( '' !== $stale
            && $this->normalized_path( $stale ) !== $this->normalized_path( $source )
            && is_file( $stale ) ) {
            if ( ! $this->is_safe_path( $stale ) || is_link( $stale ) ) {
                return false;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( ! unlink( $stale ) ) {
                return false;
            }
        }

        $this->remove_output_entry( $attachment_id, isset( $entry['size_type'] ) ? (string) $entry['size_type'] : 'full' );

        return true;
    }

    /**
     * 目标名被占用时给源文件改一个独一无二的名字
     *
     * 顺序固定为：正文链接 → 元数据与附件路径 → 磁盘改名。正文链接先改，失败时还没有
     * 动过任何文件；任一步失败都会把已完成的步骤退回原状，绝不留下引用不到的图片。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     源文件绝对路径
     * @return string|null 改名后的绝对路径，失败返回 null
     */
    private function rename_source_for_target( int $attachment_id, string $file_path ): ?string {
        $new_path = local_crush()->naming->allocate_unique_source_path( $file_path );

        if ( '' === $new_path ) {
            return null;
        }

        // 正文里固化的旧地址先改到新名字，失败时磁盘和元数据都还没动。
        if ( ! $this->update_content_references(
            array(
                array(
                    'from' => $file_path,
                    'to'   => $new_path,
                ),
            )
        ) ) {
            return null;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        $attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
        $updated  = is_array( $metadata ) ? $this->apply_renamed_metadata( $metadata, $file_path, $new_path ) : $metadata;

        // 只有附件路径本来就指向这个文件时才跟着改名，缩略图文件不能写进附件路径。
        $is_source = '' !== $attached
            && $this->normalized_path( $attached ) === $this->normalized_path( $this->get_relative_upload_path( $file_path ) );

        // 元数据没跟上就把元数据和正文退回原名字，文件一个字节都没动。
        if ( ! $this->write_metadata( $attachment_id, is_array( $updated ) ? $updated : array() )
            || ( $is_source && ! $this->write_attached_file( $attachment_id, $new_path ) ) ) {
            if ( is_array( $metadata ) ) {
                $this->write_metadata( $attachment_id, $metadata );
            }
            if ( $is_source ) {
                $this->write_attached_file( $attachment_id, $file_path );
            }
            $this->update_content_references(
                array(
                    array(
                        'from' => $new_path,
                        'to'   => $file_path,
                    ),
                )
            );

            return null;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
        if ( ! rename( $file_path, $new_path ) || ! is_file( $new_path ) ) {
            // 改名失败：元数据、附件路径和正文全部退回原名，磁盘保持原样。
            if ( is_array( $metadata ) ) {
                $this->write_metadata( $attachment_id, $metadata );
            }
            if ( $is_source ) {
                $this->write_attached_file( $attachment_id, $file_path );
            }
            $this->update_content_references(
                array(
                    array(
                        'from' => $new_path,
                        'to'   => $file_path,
                    ),
                )
            );

            return null;
        }

        return $new_path;
    }

    /**
     * 按改名后的路径更新元数据中的文件名
     *
     * @param array  $metadata 现有元数据
     * @param string $old_path 改名前的绝对路径
     * @param string $new_path 改名后的绝对路径
     * @return array
     */
    private function apply_renamed_metadata( array $metadata, string $old_path, string $new_path ): array {
        $old_relative = $this->get_relative_upload_path( $old_path );
        $new_relative = $this->get_relative_upload_path( $new_path );
        $old_name     = basename( $old_relative );
        $new_name     = basename( $new_relative );

        if ( ! empty( $metadata['file'] )
            && $this->normalized_path( (string) $metadata['file'] ) === $this->normalized_path( $old_relative ) ) {
            $metadata['file'] = $new_relative;
        }

        if ( ! empty( $metadata['original_image'] ) && (string) $metadata['original_image'] === $old_name ) {
            $metadata['original_image'] = $new_name;
        }

        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_name => $size_data ) {
                if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
                    continue;
                }

                if ( (string) $size_data['file'] === $old_name ) {
                    $metadata['sizes'][ $size_name ]['file'] = $new_name;
                }
            }
        }

        return $metadata;
    }

    /**
     * 把旧格式文件压缩成兼容格式回退文件
     *
     * 转换此时已从没有被压缩过的原图生成新格式，旧格式再单独压缩：先压缩旧格式再转换
     * 会让转换质量受损，顺序不能反。压缩记录写在后缀区分的尺寸类型下，
     * 避免与新格式文件的记录争用同一行。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $file_path     旧格式文件绝对路径
     * @param string $size_type     尺寸类型
     */
    private function compress_fallback_file( int $attachment_id, string $file_path, string $size_type ): void {
        $fallback_size_type = $this->fallback_size_type( $size_type );
        $record             = local_crush()->database->get_record( $attachment_id, $fallback_size_type );

        // 已经按同样大小压缩过就跳过：接管旧结果时重复压缩没有收益。
        if ( $this->record_matches_file( $record, $file_path ) ) {
            return;
        }

        local_crush()->compressor->compress_file( $attachment_id, $file_path, $fallback_size_type );
    }

    /**
     * 兼容格式回退文件专用的记录尺寸类型
     *
     * 压缩记录表以 (attachment_id, size_type) 唯一：新旧格式文件同属一个尺寸，
     * 回退文件必须落在另一个键下，否则会顶掉新格式文件的记录。
     *
     * 截断长度与 size_type 列宽一致，纯粹是写入兜底。列宽 189 是这个复合索引在
     * 最老环境下的安全上限：InnoDB 的 REDUNDANT/COMPACT 行格式只给索引键 767
     * 字节，扣掉 attachment_id 的 8 字节，utf8mb4 每字符最多 4 字节，得到
     * (767 - 8) / 4 = 189。WordPress 自己声明的最低 MySQL 版本是 5.5.5，默认
     * 行格式正是 COMPACT，所以按这个数取值，不去赌用户开了 DYNAMIC。
     *
     * WordPress 核心常用的 VARCHAR(191) 是给单列索引算的（191×4 = 764 < 767），
     * 放进这个带 8 字节 BIGINT 的复合索引会变成 772 字节，反而超限。
     *
     * 实际尺寸名最长的是 medium_large（12 字符），加前缀也才 21，189 的额度
     * 正常路径永远走不到截断，也就不存在两个尺寸名撞到同一个键上。
     *
     * @param string $size_type 尺寸类型
     * @return string
     */
    private function fallback_size_type( string $size_type ): string {
        return substr( 'fallback_' . $size_type, 0, 189 );
    }

    /**
     * 获取兼容格式回退文件对应的压缩记录名称。
     *
     * @param string $size_type 尺寸类型
     * @return string
     */
    public function get_fallback_record_size_type( string $size_type ): string {
        return $this->fallback_size_type( $size_type );
    }

    /**
     * 压缩记录是否与当前文件完全匹配
     *
     * @param array|null $record    压缩记录
     * @param string     $file_path 文件绝对路径
     * @return bool
     */
    private function record_matches_file( $record, string $file_path ): bool {
        if ( ! is_array( $record ) || 'success' !== ( isset( $record['status'] ) ? (string) $record['status'] : '' ) || empty( $record['file_path'] ) ) {
            return false;
        }

        clearstatcache( true, $file_path );

        if ( ! is_file( $file_path ) ) {
            return false;
        }

        return $this->normalized_path( (string) $record['file_path'] ) === $this->normalized_path( $this->get_relative_upload_path( $file_path ) )
            && (int) $record['compressed_size'] === (int) filesize( $file_path );
    }

    /**
     * 获取附件可以删除的兼容格式回退条目
     *
     * 仍在使用的文件、磁盘上已不存在的文件和没有新格式对应的文件都不删：
     * 删掉任何一个都会让附件或正文失去可用的图片。
     *
     * @param int $attachment_id 附件 ID
     * @return array 每项包含 from、to 和 size_type
     */
    public function get_fallback_entries( int $attachment_id ): array {
        $live = array();

        foreach ( local_crush()->compressor->get_attachment_files( $attachment_id ) as $file ) {
            $live[] = $this->normalized_path( $file['file_path'] );
        }

        $entries = array();

        foreach ( $this->get_output_entries( $attachment_id ) as $entry ) {
            if ( in_array( $this->normalized_path( $entry['from'] ), $live, true ) ) {
                continue;
            }

            if ( ! is_file( $entry['from'] ) || ! is_file( $entry['to'] ) ) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * 删除附件在媒体库里的旧格式回退文件
     *
     * 旧格式文件原地保留，不写进附件元数据，WordPress 删除附件时不会带走
     * 它们，只能由插件按映射清理，否则会一直占着磁盘。
     *
     * @param int $attachment_id 附件 ID
     */
    public function delete_fallback_files( int $attachment_id ): bool {
        $success = true;

        foreach ( $this->get_output_entries( $attachment_id ) as $entry ) {
            if ( is_link( $entry['from'] ) || ! is_file( $entry['from'] ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( ! unlink( $entry['from'] ) && file_exists( $entry['from'] ) ) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * 删除兼容格式回退文件的压缩记录
     *
     * 回退文件已删除，记录指向的文件不再存在，留着只会让状态与实际不符。
     *
     * @param int   $attachment_id 附件 ID
     * @param array $size_types    已删除回退文件对应的尺寸类型
     */
    public function forget_fallback_records( int $attachment_id, array $size_types ): void {
        foreach ( $size_types as $size_type ) {
            $record = local_crush()->database->get_record( $attachment_id, $this->fallback_size_type( (string) $size_type ) );

            if ( is_array( $record ) && ! empty( $record['id'] ) ) {
                local_crush()->database->delete_record( (int) $record['id'] );
            }
        }
    }

    /**
     * 写入统一压缩记录
     *
     * @param int    $attachment_id   附件 ID
     * @param string $file_path       文件路径
     * @param string $size_type       尺寸类型
     * @param int    $original_size   处理前大小
     * @param int    $compressed_size 处理后大小
     * @param string $tool_name       工具名称
     * @param string $status          状态
     * @param string $message         附加说明
     * @return bool 是否写入成功
     */
    private function save_record( int $attachment_id, string $file_path, string $size_type, int $original_size, int $compressed_size, string $tool_name, string $status, string $message ): bool {
        return local_crush()->compressor->save_compression_record(
            $attachment_id,
            $file_path,
            $size_type,
            $original_size,
            $compressed_size,
            $tool_name,
            $status,
            $message
        );
    }

    /**
     * 分页更新文章中的图片链接
     *
     * 新格式接管附件引用，正文里已固化的旧地址必须一并改写，否则读者点的还是旧格式图；
     * 关闭兼容格式回退时源文件随后即被删除，不改写更会直接 404。写入前比较原内容，
     * 避免覆盖用户在编辑器和别处同时做出的修改。
     *
     * @param array $replacements 每项包含 from 和 to 绝对路径
     * @param int   $replaced     输出参数：实际替换掉的引用数量
     * @return bool 是否全部更新成功
     */
    public function update_content_references( array $replacements, &$replaced = 0 ): bool {
        global $wpdb;

        $replaced = 0;

        // 同一对地址可能出现多次（共用文件的尺寸各记一条映射）：先去重，
        // 否则替换本身幂等无碍，“替换了 N 处”的计数会按重复对翻倍。
        $unique_pairs = array();
        $unique_keys  = array();

        foreach ( $replacements as $replacement ) {
            $key = $this->normalized_path( (string) $replacement['from'] ) . '|' . $this->normalized_path( (string) $replacement['to'] );

            if ( isset( $unique_keys[ $key ] ) ) {
                continue;
            }

            $unique_keys[ $key ] = true;
            $unique_pairs[]      = $replacement;
        }

        $replacements = $unique_pairs;

        if ( empty( $replacements ) ) {
            return true;
        }

        $like_sql  = array();
        $like_args = array();

        foreach ( $replacements as $replacement ) {
            $needle = $this->content_path_of( $replacement['from'] );

            if ( '' === $needle ) {
                continue;
            }

            // SQL 侧只做粗筛：同时匹配普通路径和区块 JSON 里 \/ 的转义写法，精确判断交给替换逻辑。
            foreach ( array( $needle, str_replace( '/', '\\/', $needle ) ) as $pattern ) {
                $like_sql[]  = 'post_content LIKE %s';
                $like_args[] = '%' . $wpdb->esc_like( $pattern ) . '%';
            }
        }

        if ( empty( $like_sql ) ) {
            return true;
        }

        $after_id = 0;
        $success  = true;

        do {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 每个 LIKE 片段都是固定的 %s 占位符，模式经 esc_like 处理后由 prepare 逐一绑定，参数数量与占位符一致；这里分页改写正文，没有可缓存对象。
            $posts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts}
                    WHERE ID > %d
                    AND post_type <> 'revision'
                    AND post_status NOT IN ('trash', 'auto-draft')
                    AND ( " . implode( ' OR ', $like_sql ) . ' )
                    ORDER BY ID ASC
                    LIMIT %d',
                    array_merge( array( $after_id ), $like_args, array( self::REFERENCE_BATCH_SIZE ) )
                )
            );
            // phpcs:enable

            $posts     = is_array( $posts ) ? $posts : array();
            $page_size = count( $posts );

            foreach ( $posts as $post ) {
                $post_id = (int) $post->ID;
                $content = (string) $post->post_content;
                $updated = $this->replace_content_references( $content, $replacements );

                $after_id = max( $after_id, $post_id );

                if ( $updated === $content ) {
                    continue;
                }

                if ( $this->write_post_content( $post_id, $content, $updated ) ) {
                    $replaced += $this->count_missing_references( $content, $updated, $replacements );
                    continue;
                }

                // 写入未生效：可能已被并发修改，重读最新内容后再替换一次。
                $current = $this->read_post_content( $post_id );

                if ( $current === $content ) {
                    $success = false;
                    continue;
                }

                $retry = $this->replace_content_references( $current, $replacements );

                if ( $retry === $current ) {
                    continue;
                }

                if ( $this->write_post_content( $post_id, $current, $retry ) ) {
                    $replaced += $this->count_missing_references( $current, $retry, $replacements );
                } else {
                    $success = false;
                }
            }
        } while ( $page_size >= self::REFERENCE_BATCH_SIZE );

        return $success;
    }

    /**
     * 统计一次改写实际消除了多少处引用
     *
     * 同一个文件在 srcset 里可能出现多次，按出现次数计更接近用户看到的“替换了多少链接”。
     *
     * 必须按新地址统计：新文件名是旧文件名再加一段扩展名（photo.jpg → photo.jpg.webp），
     * 旧地址是新地址的前缀，用旧地址做子串统计时改写后的正文里仍能匹配到同名前缀，
     * 一减永远是 0。
     *
     * @param string $before       改写前的正文
     * @param string $after        改写后的正文
     * @param array  $replacements 每项包含 from、to 绝对路径
     * @return int
     */
    private function count_missing_references( string $before, string $after, array $replacements ): int {
        $count = 0;

        foreach ( $replacements as $replacement ) {
            $needle = $this->content_path_of( $replacement['to'] );

            if ( '' === $needle ) {
                continue;
            }

            $needles = array( $needle );
            $encoded = $this->encode_url_path( $needle );

            if ( $encoded !== $needle ) {
                $needles[] = $encoded;
            }

            foreach ( $needles as $current ) {
                $count += max( 0, substr_count( $after, $current ) - substr_count( $before, $current ) );
            }
        }

        return $count;
    }

    /**
     * 读取文章内容
     *
     * @param int $post_id 文章 ID
     * @return string
     */
    private function read_post_content( int $post_id ): string {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (string) $wpdb->get_var(
            $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id )
        );
    }

    /**
     * 比较原内容后写入文章内容
     *
     * @param int    $post_id  文章 ID
     * @param string $expected 读取到的原内容
     * @param string $content  新内容
     * @return bool 是否写入成功
     */
    private function write_post_content( int $post_id, string $expected, string $content ): bool {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update(
            $wpdb->posts,
            array( 'post_content' => $content ),
            array(
                'ID'           => $post_id,
                'post_content' => $expected,
            ),
            array( '%s' ),
            array( '%d', '%s' )
        );

        if ( 1 !== (int) $updated ) {
            return false;
        }

        clean_post_cache( $post_id );

        return true;
    }

    /**
     * 按实际映射替换内容中的图片地址
     *
     * 只替换文件名部分，保留协议、域名、查询参数、锚点和 srcset 的候选结构；
     * 同时兼容区块 JSON 里被转义成 \/ 的路径，外站地址与同名前缀文件不动。
     *
     * @param string $content     文章内容
     * @param array  $replacements 每项包含 from 和 to 绝对路径
     * @return string
     */
    public function replace_content_references( string $content, array $replacements ): string {
        foreach ( $replacements as $replacement ) {
            $content = $this->replace_reference_in_content( $content, $replacement['from'], $replacement['to'] );
        }

        return $content;
    }

    /**
     * 替换内容中指向某个 uploads 文件的地址
     *
     * @param string $content  文章内容
     * @param string $from     源文件绝对路径
     * @param string $to       结果文件绝对路径
     * @return string
     */
    private function replace_reference_in_content( string $content, string $from, string $to ): string {
        $needle = $this->content_path_of( $from );

        if ( '' === $needle ) {
            return $content;
        }

        $new_name = basename( $this->content_path_of( $to ) );

        if ( '' === $new_name ) {
            return $content;
        }

        $content = $this->replace_reference_needle( $content, $needle, $new_name );

        // 编辑器或浏览器可能把中文等非 ASCII 文件名存成百分号编码，
        // 只匹配原始路径会让这些链接在源文件删除后变成死链。
        $encoded_needle = $this->encode_url_path( $needle );

        if ( $encoded_needle !== $needle ) {
            $content = $this->replace_reference_needle( $content, $encoded_needle, rawurlencode( $new_name ) );
        }

        return $content;
    }

    /**
     * 把内容里的一个 URL 路径文件名替换成新文件名
     *
     * @param string $content  文章内容
     * @param string $needle   uploads 内的 URL 路径
     * @param string $new_name 新文件名（已按 needle 的编码方式处理）
     * @return string
     */
    private function replace_reference_needle( string $content, string $needle, string $new_name ): string {
        if ( false === strpos( str_replace( '\\', '', $content ), str_replace( '\\', '', $needle ) ) ) {
            return $content;
        }

        $separator = '(?:\\\\)?/';
        $filename  = basename( $needle );
        $directory = rtrim( dirname( $needle ), '/' );
        $pattern   = $separator;

        foreach ( explode( '/', $directory ) as $segment ) {
            if ( '' === $segment ) {
                continue;
            }

            $pattern .= preg_quote( $segment, '#' ) . $separator;
        }

        // 文件名后面必须正好结束，避免把 a.jpg.extra 这类不同的文件一起改掉。
        $pattern .= '(?P<file>' . preg_quote( $filename, '#' ) . ')(?![\w.\-%~/-])(?![\\\\])';

        $matches = array();
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! preg_match_all( '#' . $pattern . '#i', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            return $content;
        }

        // 从后往前替换，避免前面的替换让后面的偏移失效。
        for ( $index = count( $matches ) - 1; $index >= 0; $index-- ) {
            if ( ! isset( $matches[ $index ][0][1], $matches[ $index ]['file'][1] ) ) {
                continue;
            }

            $file_offset = (int) $matches[ $index ]['file'][1];

            // 以整段路径的起点判断宿主，匹配段自身以 / 开头，前面的内容正好是协议和域名。
            if ( ! $this->content_host_allowed( substr( $content, 0, (int) $matches[ $index ][0][1] ) ) ) {
                continue;
            }

            $content = substr_replace( $content, $new_name, $file_offset, strlen( $matches[ $index ]['file'][0] ) );
        }

        return $content;
    }

    /**
     * 按 URL 路径逐段做百分号编码，保留目录分隔符
     *
     * @param string $path URL 路径
     * @return string
     */
    private function encode_url_path( string $path ): string {
        return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
    }

    /**
     * 把上传目录内的文件路径转换为站点内的 URL 路径
     *
     * @param string $file_path 绝对路径
     * @return string 无法定位时返回空字符串
     */
    private function content_path_of( string $file_path ): string {
        $upload    = wp_get_upload_dir();
        $base_path = isset( $upload['baseurl'] ) ? wp_parse_url( $upload['baseurl'], PHP_URL_PATH ) : '';
        $base_dir  = isset( $upload['basedir'] ) ? $upload['basedir'] : '';

        if ( ! is_string( $base_path ) || '' === $base_path || '' === $base_dir ) {
            return '';
        }

        $relative = $this->get_relative_upload_path( $file_path );

        if ( $relative === $this->normalized_path( $file_path ) ) {
            // 不在上传目录内，没有可替换的站点路径。
            return '';
        }

        return untrailingslashit( $base_path ) . '/' . ltrim( $relative, '/' );
    }

    /**
     * 判断待替换地址的宿主是否属于本站上传目录
     *
     * @param string $prefix 匹配位置之前的内容
     * @return bool
     */
    private function content_host_allowed( string $prefix ): bool {
        $delimiters = "\"'<> \t\n\r(),;=[]{}";
        $start      = strlen( $prefix );

        while ( $start > 0 && false === strpos( $delimiters, $prefix[ $start - 1 ] ) ) {
            $start--;
        }

        $token = str_replace( '\\', '', substr( $prefix, $start ) );

        // 站内相对路径
        if ( '' === $token ) {
            return true;
        }

        if ( ! preg_match( '~^(?:[a-z][a-z0-9+.\-]*:)?(?://(?P<host>[^/?#]*))?$~i', $token, $matched ) ) {
            return false;
        }

        if ( empty( $matched['host'] ) ) {
            return false;
        }

        $upload    = wp_get_upload_dir();
        $base_host = isset( $upload['baseurl'] ) ? wp_parse_url( $upload['baseurl'], PHP_URL_HOST ) : '';

        return is_string( $base_host ) && strtolower( $matched['host'] ) === strtolower( $base_host );
    }

    /**
     * 构建编码命令
     *
     * 复用压缩工具的二进制查找与质量设置
     *
     * @param string $source      编码输入文件路径
     * @param string $output      编码输出文件路径
     * @param string $target      目标格式 webp|avif|jxl
     * @param string $group       这条路径的参数分组
     * @param int    $orientation 原图方向，1 表示不需要处理
     * @return array|false 命令链（参数数组的数组）或 false
     */
    private function build_encode_command( string $source, string $output, string $target, string $group, int $orientation = 1 ) {
        $this->encode_temp_paths = array();

        $tool_name = isset( $this->target_tools[ $target ] ) ? $this->target_tools[ $target ] : '';
        $tools     = local_crush()->compressor->get_tools();

        if ( '' === $tool_name || empty( $tools[ $tool_name ] ) ) {
            return false;
        }

        $tool_available = 'avifenc' === $tool_name && method_exists( $tools[ $tool_name ], 'has_encoder' )
            ? $tools[ $tool_name ]->has_encoder()
            : $tools[ $tool_name ]->is_tool_available();

        if ( ! $tool_available ) {
            return false;
        }

        $binary = $tools[ $tool_name ]->get_tool_binary_path();

        if ( 'webp' === $target ) {
            // 参数拼装与同格式压缩共用一份，避免两边走样。
            return array( array_merge( array( $binary ), Local_Crush_Cwebp::build_encode_args( $group, $source, $output ) ) );
        }

        if ( 'jxl' === $target ) {
            $orientation_exif = '';

            if ( $orientation > 1 && Local_Crush_Settings::strips_metadata() ) {
                $orientation_exif = Local_Crush_Orientation::write_exif_temp( $orientation, false );

                if ( '' === $orientation_exif ) {
                    return false;
                }

                $this->encode_temp_paths[] = $orientation_exif;
            }

            $command = Local_Crush_Cjxl::build_command( $source, $output, $group, $orientation_exif );

            if ( false === $command ) {
                $this->cleanup_encode_temp_paths();
                return false;
            }

            return array( $command );
        }

        $orientation_exif = '';

        if ( $orientation > 1 && Local_Crush_Settings::strips_metadata() ) {
            $orientation_exif = Local_Crush_Orientation::write_exif_temp( $orientation, true );

            if ( '' === $orientation_exif ) {
                return false;
            }

            $this->encode_temp_paths[] = $orientation_exif;
        }

        return array( array_merge( array( $binary ), Local_Crush_Avif::build_encode_args( $group, $source, $output, $orientation_exif ) ) );
    }

    /**
     * 清理转换链路产生的方向临时文件
     */
    private function cleanup_encode_temp_paths(): void {
        foreach ( $this->encode_temp_paths as $path ) {
            if ( is_string( $path ) && '' !== $path && file_exists( $path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                unlink( $path );
            }
        }

        $this->encode_temp_paths = array();
    }

    /**
     * 用 GD 将 GIF 解码为 PNG
     *
     * 只用于静态 GIF 转 AVIF：avifenc 不认 GIF，又没有别的现成工具能读 GIF 转 PNG
     *（gifsicle 只能写 GIF）。静态 GIF 转 WebP / JPEG XL 都不需要这一步。
     *
     * @param string $gif_path GIF 源路径
     * @param string $png_path PNG 输出路径
     * @return bool 是否成功
     */
    private function decode_gif_to_png( string $gif_path, string $png_path ): bool {
        if ( ! function_exists( 'imagecreatefromgif' ) || ! function_exists( 'imagepng' ) ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $image = @imagecreatefromgif( $gif_path );

        if ( false === $image ) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $ok = @imagepng( $image, $png_path );

        return $ok && file_exists( $png_path ) && filesize( $png_path ) > 0;
    }

    /**
     * 查找本地辅助工具（gif2webp、ffmpeg 等）
     *
     * 统一走 Local_Crush_Tool_Base::resolve_executable()，查找顺序与压缩工具一致。
     * 设置页复用此方法展示辅助工具的安装状态与路径。
     *
     * @param string $name 可执行文件名
     * @return string|false 路径或 false
     */
    public static function find_local_tool( string $name ) {
        return Local_Crush_Tool_Base::resolve_executable( $name );
    }

    /**
     * 获取文件的格式标识（jpeg 归一为 jpg）
     *
     * @param string $file_path 文件路径
     * @return string
     */
    private function format_of( string $file_path ): string {
        $format = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        return 'jpeg' === $format ? 'jpg' : $format;
    }

    /**
     * 获取上传目录内的相对路径
     *
     * 返回值只统一分隔符、不动大小写：写进元数据、映射和正文链接的是用户可见的文件名，
     * 改成小写会让 URL 大小写敏感的环境（Linux、CDN）直接 404。
     * 大小写只在比较时由 normalized_path 忽略。
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
     * 规范化路径用于精确比较
     *
     * @param string $path 路径
     * @return string
     */
    private function normalized_path( string $path ): string {
        $normalized = wp_normalize_path( $path );
        return 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) ? strtolower( $normalized ) : $normalized;
    }

    /**
     * 验证上传目录内的目标路径，允许文件当前不存在
     *
     * @param string $file_path 目标路径
     * @return bool
     */
    private function is_safe_destination_path( string $file_path ): bool {
        if ( false !== strpos( $file_path, '..' ) || is_link( $file_path ) ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $parent_dir = realpath( dirname( $file_path ) );

        if ( false === $base_dir || false === $parent_dir ) {
            return false;
        }

        $base_dir   = untrailingslashit( wp_normalize_path( $base_dir ) );
        $parent_dir = untrailingslashit( wp_normalize_path( $parent_dir ) );

        return 0 === strpos( $parent_dir . '/', $base_dir . '/' );
    }

    /**
     * 检查文件路径是否安全（必须位于上传目录内）
     *
     * @param string $file_path 文件路径
     * @return bool 是否安全
     */
    private function is_safe_path( string $file_path ): bool {
        if ( is_link( $file_path ) ) {
            return false;
        }

        $upload_dir = wp_get_upload_dir();
        $base_dir   = realpath( $upload_dir['basedir'] );
        $real_path  = realpath( $file_path );

        if ( false === $real_path || false === $base_dir ) {
            return false;
        }

        $base_dir  = untrailingslashit( wp_normalize_path( $base_dir ) );
        $real_path = untrailingslashit( wp_normalize_path( $real_path ) );

        if ( 0 !== strpos( $real_path, $base_dir . '/' ) ) {
            return false;
        }

        if ( false !== strpos( $file_path, '..' ) ) {
            return false;
        }

        return true;
    }
}
