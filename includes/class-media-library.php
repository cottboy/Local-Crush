<?php
/**
 * 媒体库集成类
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 媒体库集成类
 *
 * 负责在媒体库中显示压缩状态和操作按钮
 */
class Local_Crush_Media_Library {

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
        // 添加媒体库列
        add_filter( 'manage_media_columns', array( $this, 'add_compression_column' ) );
        add_action( 'manage_media_custom_column', array( $this, 'render_compression_column' ), 10, 2 );
        add_action( 'admin_head-upload.php', array( $this, 'output_media_library_styles' ) );

        // 注册 AJAX 接口（批量操作统一走后台任务，见 Local_Crush_Task）
        add_action( 'wp_ajax_local_crush_compress_single', array( $this, 'ajax_compress_single' ) );
        add_action( 'wp_ajax_local_crush_get_details', array( $this, 'ajax_get_details' ) );
        add_action( 'wp_ajax_local_crush_restore', array( $this, 'ajax_restore' ) );
        add_action( 'wp_ajax_local_crush_attachment_action', array( $this, 'ajax_attachment_action' ) );
    }

    /**
     * 添加压缩状态列
     *
     * 状态和按钮只有管理员能用，非管理员直接不显示这一列。
     *
     * @param array $columns 现有列
     * @return array 修改后的列
     */
    public function add_compression_column( $columns ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return $columns;
        }

        $columns['local_crush'] = __( '压缩', 'local-crush' );
        return $columns;
    }

    /**
     * 输出媒体库列样式
     */
    public function output_media_library_styles() {
        ?>
        <style>
            /* WordPress 后台表格是固定列宽模式，列宽只能写具体数值，写"按内容伸缩"浏览器直接忽略。
               注意表格单元格的 width 不含左右内边距（各 10px），所以这里写的正好是两栏的宽度和：
               左栏 130 + 间距 4 + 右栏 62 = 196。右栏 62 是按钮最多四个字时的宽度：
               继续压缩／恢复原图＝11px 字 4 个＝44，加左右各 8px 内边距和 1px 边框，共 62。
               按钮刚好顶到右边缘，剩下的只有 WP 自己那 10px 格子内边距。
               多出来的宽度会留给左边的文件名栏，所以右边缘不会再留白 */
            .wp-list-table .column-local_crush {
                width: 196px;
            }

            /* 左右两栏宽度写死，每行的文字左边缘、按钮左边缘才对齐在一条竖线上，
               表头"压缩"两个字也和内容对得上，两栏之间只留 4px。
               文字长过 130px 会自动换行，只会多占一行，不会撑破表格，所以换语言也不会散架 */
            .wp-list-table .column-local_crush .local-crush-status {
                display: grid;
                grid-template-columns: 130px 62px;
                align-items: center;
                gap: 0 4px;
            }

            .wp-list-table .column-local_crush .local-crush-info {
                min-width: 0;
            }

            /* 按钮一行一个竖着排，按文字大小走，绝不去拉伸按钮 */
            .local-crush-actions {
                display: flex;
                flex-direction: column;
                gap: 4px;
                align-items: flex-start;
            }

            .local-crush-storage {
                color: #666;
                margin-top: 3px;
            }

            .local-crush-storage .local-crush-detail-btn {
                margin-left: 4px;
                padding: 0;
                vertical-align: baseline;
            }

            .local-crush-details-modal {
                position: fixed;
                z-index: 100000;
                inset: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
                background: rgba(0, 0, 0, 0.55);
            }

            .local-crush-details-dialog {
                width: min(1200px, 100%);
                max-height: min(720px, 90vh);
                overflow: auto;
                background: #fff;
                padding: 20px;
                box-shadow: 0 8px 30px rgba(0, 0, 0, 0.28);
            }

            .local-crush-details-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin-bottom: 14px;
            }

            .local-crush-details-head h2 {
                margin: 0;
            }

            /* 标题下面那排按钮：一行放不下自动换行，开关与按钮同高 */
            .local-crush-details-toolbar {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px;
                margin: -4px 0 14px;
            }

            .local-crush-details-toolbar .button {
                margin: 0;
            }

            /* 详情页的开关放在同一组里，整组靠右，组内两个开关并排 */
            .local-crush-details-switches {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 8px 20px;
                margin-left: auto;
            }

            /* 闹钟式开关：轨道 + 滑块，整块可点 */
            .local-crush-switch {
                position: relative;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
                user-select: none;
            }

            /* 藏掉原生复选框；WordPress 会给禁用的复选框加 0.7 不透明度，
               保存开关时会把这个小输入框露成一条横线，所以要连禁用态一起藏 */
            .local-crush-switch input,
            .local-crush-switch input:disabled {
                position: absolute;
                width: 1px;
                height: 1px;
                min-width: 0;
                margin: 0;
                padding: 0;
                border: 0;
                background: none;
                opacity: 0;
            }

            .local-crush-switch-track {
                position: relative;
                flex: none;
                width: 40px;
                height: 22px;
                background: #8c8f94;
                border-radius: 11px;
                transition: background .2s ease;
            }

            .local-crush-switch-thumb {
                position: absolute;
                top: 2px;
                left: 2px;
                width: 18px;
                height: 18px;
                background: #fff;
                border-radius: 50%;
                box-shadow: 0 1px 2px rgba( 0, 0, 0, .3 );
                transition: transform .2s ease;
            }

            .local-crush-switch input:checked + .local-crush-switch-track {
                background: #2271b1;
            }

            .local-crush-switch input:checked + .local-crush-switch-track .local-crush-switch-thumb {
                transform: translateX( 18px );
            }

            /* 只有键盘操作才画焦点圈；鼠标点击时输入框会先聚焦、随即被禁用失焦，
               画了就会闪一圈阴影 */
            .local-crush-switch input:focus-visible + .local-crush-switch-track {
                box-shadow: 0 0 0 2px #fff, 0 0 0 4px #2271b1;
            }

            .local-crush-details-table .local-crush-details-actions {
                text-align: right;
                white-space: nowrap;
            }

            /* 删除按钮改成灰色，不抢眼；悬停时加深一点提示可以点 */
            .local-crush-details-table .local-crush-size-delete {
                color: #646970;
            }

            .local-crush-details-table .local-crush-size-delete:hover,
            .local-crush-details-table .local-crush-size-delete:focus {
                color: #1d2327;
            }

            .local-crush-details-table {
                width: 100%;
                border-collapse: collapse;
            }

            .local-crush-details-table th,
            .local-crush-details-table td {
                padding: 8px;
                border-bottom: 1px solid #dcdcde;
                text-align: left;
                vertical-align: top;
            }

            .local-crush-details-table th {
                background: #f6f7f7;
                white-space: nowrap;
            }

            @media screen and (max-width: 782px) {
                .local-crush-details-modal {
                    padding: 10px;
                }

                .local-crush-details-dialog {
                    padding: 14px;
                }

                .local-crush-details-table {
                    font-size: 12px;
                }

                .local-crush-details-table th,
                .local-crush-details-table td {
                    padding: 6px 4px;
                }
            }
        </style>
        <?php
    }

    /**
     * 渲染压缩状态列内容
     *
     * @param string $column_name 列名
     * @param int    $attachment_id 附件 ID
     */
    public function render_compression_column( $column_name, $attachment_id ) {
        if ( 'local_crush' !== $column_name ) {
            return;
        }

        // 检查是否为图片
        $mime_type     = get_post_mime_type( $attachment_id );
        $allowed_types = local_crush()->processor->get_supported_mimes();

        if ( ! in_array( $mime_type, $allowed_types, true ) ) {
            echo '<span class="local-crush-na">—</span>';
            return;
        }

        $processor  = local_crush()->processor;
        $state      = $processor->get_attachment_state( $attachment_id );
        $has_backup = local_crush()->backup->has_backup( $attachment_id );

        // 引用已还原但残留尚未清理时，恢复按钮仍要可用，否则用户无法重试收尾。
        $can_restore = $has_backup || $processor->has_pending_restore( $attachment_id );

        // 设为不再压缩后，这一行的单图操作按钮全部收起来，只留状态和详情入口。
        $compression_disabled = Local_Crush_Processor::compression_disabled_for_attachment( $attachment_id );

        if ( 'complete' === $state['status'] ) {
            $this->render_compressed_status( $attachment_id, $state, $can_restore, $compression_disabled );
        } elseif ( 'partial' === $state['status'] ) {
            $this->render_partial_status( $attachment_id, $state, $can_restore, $compression_disabled );
        } elseif ( 'failed' === $state['status'] ) {
            $this->render_failed_status( $attachment_id, $can_restore, $compression_disabled );
        } elseif ( 'skipped' === $state['status'] ) {
            $this->render_skipped_status( $attachment_id, $can_restore, $compression_disabled );
        } else {
            $this->render_uncompressed_status( $attachment_id, $compression_disabled );
        }
    }

    /**
     * 渲染未压缩状态
     *
     * @param int  $attachment_id       附件 ID
     * @param bool $compression_disabled 是否已设为不再压缩
     */
    private function render_uncompressed_status( int $attachment_id, bool $compression_disabled ) {
        ?>
        <div class="local-crush-status" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
            <div class="local-crush-info">
                <span class="status-text"><?php esc_html_e( '未压缩', 'local-crush' ); ?></span>
                <?php $this->render_storage_summary( $attachment_id ); ?>
            </div>
            <?php
            if ( ! $compression_disabled ) {
                $this->render_action_buttons( $attachment_id, 'compress', __( '压缩', 'local-crush' ), false );
            }
            ?>
        </div>
        <?php
    }

    /**
     * 渲染压缩失败状态
     *
     * @param int  $attachment_id        附件 ID
     * @param bool $can_restore          是否可恢复
     * @param bool $compression_disabled 是否已设为不再压缩
     */
    private function render_failed_status( int $attachment_id, bool $can_restore, bool $compression_disabled ) {
        ?>
        <div class="local-crush-status" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
            <div class="local-crush-info">
                <span class="status-text" style="color: #d63638;"><?php esc_html_e( '压缩失败', 'local-crush' ); ?></span>
                <?php $this->render_storage_summary( $attachment_id ); ?>
            </div>
            <?php
            if ( ! $compression_disabled ) {
                $this->render_action_buttons( $attachment_id, 'compress', __( '重试', 'local-crush' ), $can_restore );
            }
            ?>
        </div>
        <?php
    }

    /**
     * 渲染主动跳过状态
     *
     * @param int  $attachment_id        附件 ID
     * @param bool $can_restore          是否可恢复
     * @param bool $compression_disabled 是否已设为不再压缩
     */
    private function render_skipped_status( int $attachment_id, bool $can_restore, bool $compression_disabled ) {
        ?>
        <div class="local-crush-status" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
            <div class="local-crush-info">
                <span class="status-text" style="color: #dba617;"><?php esc_html_e( '已跳过', 'local-crush' ); ?></span>
                <?php $this->render_storage_summary( $attachment_id ); ?>
            </div>
            <?php
            if ( ! $compression_disabled ) {
                $this->render_action_buttons( $attachment_id, 'compress', __( '重试', 'local-crush' ), $can_restore );
            }
            ?>
        </div>
        <?php
    }

    /**
     * 渲染部分压缩状态
     *
     * @param int   $attachment_id        附件 ID
     * @param array $state                统一状态
     * @param bool  $can_restore          是否可恢复
     * @param bool  $compression_disabled 是否已设为不再压缩
     */
    private function render_partial_status( int $attachment_id, array $state, bool $can_restore, bool $compression_disabled ) {
        ?>
        <div class="local-crush-status" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
            <div class="local-crush-info">
                <span class="status-text" style="color: #dba617;">
                    <?php
                    printf(
                        /* translators: 1: 已成功文件数，2: 总文件数 */
                        esc_html__( '部分已压缩 %1$d/%2$d', 'local-crush' ),
                        (int) $state['success_count'],
                        (int) $state['total_files']
                    );
                    ?>
                </span>
                <?php $this->render_storage_summary( $attachment_id ); ?>
            </div>
            <?php
            if ( ! $compression_disabled ) {
                $this->render_action_buttons( $attachment_id, 'compress', __( '继续压缩', 'local-crush' ), $can_restore );
            }
            ?>
        </div>
        <?php
    }

    /**
     * 渲染已压缩状态
     *
     * @param int   $attachment_id        附件 ID
     * @param array $stats                压缩统计
     * @param bool  $can_restore          是否可恢复
     * @param bool  $compression_disabled 是否已设为不再压缩
     */
    private function render_compressed_status( int $attachment_id, array $stats, bool $can_restore, bool $compression_disabled ) {
        ?>
        <div class="local-crush-status" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
            <div class="local-crush-info">
                <span class="status-text" style="color: #00a32a;">
                    <?php
                    printf(
                        /* translators: %s: 压缩比例 */
                        esc_html__( '已压缩%s%%', 'local-crush' ),
                        esc_html( $stats['total_ratio'] )
                    );
                    ?>
                </span>
                <?php $this->render_storage_summary( $attachment_id ); ?>
            </div>
            <?php
            if ( ! $compression_disabled ) {
                $this->render_action_buttons( $attachment_id, '', '', $can_restore );
            }
            ?>
        </div>
        <?php
    }

    /**
     * 输出状态文字右侧的操作按钮
     *
     * @param int    $attachment_id  附件 ID
     * @param string $primary_action 主按钮动作，空字符串表示没有主按钮
     * @param string $primary_label  主按钮文字
     * @param bool   $can_restore    是否可恢复
     */
    private function render_action_buttons( int $attachment_id, string $primary_action, string $primary_label, bool $can_restore ) {
        // 先把按钮都收集起来，再一行一个排下去
        $buttons = array();

        if ( '' !== $primary_action ) {
            $buttons[] = array(
                'label'  => $primary_label,
                'action' => $primary_action,
            );
        }
        if ( $can_restore ) {
            $buttons[] = array(
                'label'  => __( '恢复原图', 'local-crush' ),
                'action' => 'restore',
            );
        }

        if ( empty( $buttons ) ) {
            return;
        }
        ?>
        <div class="local-crush-actions">
            <?php foreach ( $buttons as $button ) : ?>
                <button type="button" class="button button-small local-crush-btn" data-action="<?php echo esc_attr( $button['action'] ); ?>" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
                    <?php echo esc_html( $button['label'] ); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * 输出附件当前所有相关文件的占用空间和详情按钮。
     *
     * @param int $attachment_id 附件 ID
     */
    private function render_storage_summary( int $attachment_id ) {
        $details = local_crush()->processor->get_attachment_details( $attachment_id );
        $bytes   = isset( $details['total_size'] ) ? max( 0, (int) $details['total_size'] ) : 0;
        ?>
        <div class="local-crush-storage">
            <?php
            printf(
                /* translators: %s: 当前所有相关文件的总占用空间 */
                esc_html__( '占用 %s', 'local-crush' ),
                esc_html( $this->format_file_size( $bytes ) )
            );
            ?>
            <button type="button" class="button-link local-crush-btn local-crush-detail-btn" data-action="details" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
                <?php esc_html_e( '详情', 'local-crush' ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * AJAX: 使用统一流程压缩单个附件
     */
    public function ajax_compress_single() {
        check_ajax_referer( 'local_crush_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'local-crush' ) ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! local_crush()->processor->is_valid_attachment( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => __( '无效的图片附件', 'local-crush' ) ) );
        }

        wp_send_json_success( local_crush()->processor->compress_attachment( $attachment_id ) );
    }

    /**
     * AJAX：获取媒体库详情。
     */
    public function ajax_get_details() {
        check_ajax_referer( 'local_crush_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'local-crush' ) ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! local_crush()->processor->is_valid_attachment( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => __( '无效的图片附件', 'local-crush' ) ) );
        }

        $details = local_crush()->processor->get_attachment_details( $attachment_id );
        if ( empty( $details ) ) {
            wp_send_json_error( array( 'message' => __( '无法读取图片详情', 'local-crush' ) ) );
        }

        // 详情页的开关状态：是否已设为不再生成缩略图、是否已设为不再压缩
        $details['thumbnails_disabled']  = Local_Crush_Thumbnail_Manager::thumbnails_disabled_for_attachment( $attachment_id );
        $details['compression_disabled'] = Local_Crush_Processor::compression_disabled_for_attachment( $attachment_id );

        wp_send_json_success( $details );
    }

    /**
     * AJAX: 恢复原图
     */
    public function ajax_restore() {
        // 验证 nonce
        check_ajax_referer( 'local_crush_nonce', 'nonce' );

        // 验证权限
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'local-crush' ) ) );
        }

        // 获取参数
        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

        if ( ! $attachment_id ) {
            wp_send_json_error( array( 'message' => __( '无效的附件 ID', 'local-crush' ) ) );
        }

        $backup = local_crush()->backup;

        // 检查是否有备份，或是否有恢复做了一半等待收尾的附件
        if ( ! $backup->has_backup( $attachment_id ) && ! local_crush()->processor->has_pending_restore( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => __( '没有可用的备份', 'local-crush' ) ) );
        }

        // 使用附件级锁和统一恢复流程，避免恢复后立即再次自动压缩。
        $result = local_crush()->processor->restore_attachment( $attachment_id );

        if ( $result ) {
            wp_send_json_success( array( 'message' => __( '恢复成功', 'local-crush' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( '恢复失败', 'local-crush' ) ) );
        }
    }

    /**
     * AJAX: 详情页的单图操作
     *
     * 详情页上新增的按钮全部走这一个入口，每个分支只做参数校验和结果播报，
     * 真正的清理逻辑与批量操作、媒体库按钮共用同一套流程，不另写一份。
     */
    public function ajax_attachment_action() {
        check_ajax_referer( 'local_crush_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( '权限不足', 'local-crush' ) ) );
        }

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        if ( ! local_crush()->processor->is_valid_attachment( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => __( '无效的图片附件', 'local-crush' ) ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce 已在上面校验过。
        $operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
        $manager   = local_crush()->thumbnail_manager;

        if ( 'set-no-thumbnails' === $operation ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 同上。
            $disabled = isset( $_POST['disabled'] ) && '1' === (string) absint( $_POST['disabled'] );

            if ( ! $manager->set_thumbnails_disabled( $attachment_id, $disabled ) ) {
                wp_send_json_error( array( 'message' => __( '设置保存失败，请重试', 'local-crush' ) ) );
            }

            wp_send_json_success(
                array(
                    'message'  => $disabled
                        ? __( '已停止为这张图片生成缩略图', 'local-crush' )
                        : __( '已恢复为这张图片生成缩略图', 'local-crush' ),
                    'disabled' => $disabled,
                )
            );
        }

        if ( 'set-no-compress' === $operation ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 同上。
            $disabled = isset( $_POST['disabled'] ) && '1' === (string) absint( $_POST['disabled'] );

            if ( ! local_crush()->processor->set_compression_disabled( $attachment_id, $disabled ) ) {
                wp_send_json_error( array( 'message' => __( '设置保存失败，请重试', 'local-crush' ) ) );
            }

            wp_send_json_success(
                array(
                    'message'  => $disabled
                        ? __( '这张图片已设为不再压缩', 'local-crush' )
                        : __( '这张图片已恢复为可压缩', 'local-crush' ),
                    'disabled' => $disabled,
                )
            );
        }

        // 每个分支都以 wp_send_json_* 收尾（它会直接结束请求），所以不需要 break。
        switch ( $operation ) {
            case 'delete-size':
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 同上。
                $size_name = isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : '';

                if ( '' === $size_name ) {
                    wp_send_json_error( array( 'message' => __( '无效的缩略图尺寸', 'local-crush' ) ) );
                }

                $result = $manager->delete_size_for_attachment( $attachment_id, array( $size_name ) );

                if ( null === $result ) {
                    wp_send_json_error( array( 'message' => __( '删除失败，这张图片正在被其他操作占用', 'local-crush' ) ) );
                }

                if ( ! empty( $result['content_failed'] ) ) {
                    wp_send_json_error( array( 'message' => __( '图片链接替换失败，没有删除任何文件', 'local-crush' ) ) );
                }

                if ( empty( $result['deleted'] ) && empty( $result['removed'] ) ) {
                    wp_send_json_error( array( 'message' => __( '没有可删除的缩略图尺寸', 'local-crush' ) ) );
                }

                wp_send_json_success( array( 'message' => __( '缩略图已删除', 'local-crush' ) ) );

            case 'delete-fallback-size':
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 同上。
                $size_name = isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : '';

                if ( '' === $size_name ) {
                    wp_send_json_error( array( 'message' => __( '无效的缩略图尺寸', 'local-crush' ) ) );
                }

                $result = local_crush()->processor->delete_fallback( $attachment_id, array( $size_name ) );

                if ( ! empty( $result['failed'] ) ) {
                    wp_send_json_error( array( 'message' => __( '删除失败', 'local-crush' ) ) );
                }

                if ( empty( $result['deleted_files'] ) ) {
                    wp_send_json_error( array( 'message' => __( '没有可删除的兼容格式回退', 'local-crush' ) ) );
                }

                wp_send_json_success( array( 'message' => __( '旧格式图片已删除', 'local-crush' ) ) );

            case 'delete-fallback':
                $result = local_crush()->processor->delete_fallback( $attachment_id );

                if ( ! empty( $result['failed'] ) ) {
                    wp_send_json_error( array( 'message' => __( '部分兼容格式回退删除失败，请重试', 'local-crush' ) ) );
                }

                if ( empty( $result['deleted_files'] ) ) {
                    wp_send_json_error( array( 'message' => __( '没有可删除的兼容格式回退', 'local-crush' ) ) );
                }

                wp_send_json_success( array( 'message' => __( '兼容格式回退已删除', 'local-crush' ) ) );

            case 'delete-all':
                $result = $manager->delete_all_for_attachment( $attachment_id );

                if ( null === $result ) {
                    wp_send_json_error( array( 'message' => __( '删除失败，这张图片正在被其他操作占用', 'local-crush' ) ) );
                }

                if ( ! empty( $result['content_failed'] ) ) {
                    wp_send_json_error( array( 'message' => __( '图片链接替换失败，没有删除任何文件', 'local-crush' ) ) );
                }

                if ( empty( $result['deleted'] ) && empty( $result['removed'] ) ) {
                    wp_send_json_error( array( 'message' => __( '没有可删除的缩略图尺寸', 'local-crush' ) ) );
                }

                wp_send_json_success( array( 'message' => __( '这张图片的缩略图已全部删除', 'local-crush' ) ) );

            case 'delete-disabled':
                $result = $manager->delete_disabled_for_attachment( $attachment_id );

                if ( null === $result ) {
                    wp_send_json_error( array( 'message' => __( '删除失败，这张图片正在被其他操作占用', 'local-crush' ) ) );
                }

                if ( ! empty( $result['content_failed'] ) ) {
                    wp_send_json_error( array( 'message' => __( '图片链接替换失败，没有删除任何文件', 'local-crush' ) ) );
                }

                if ( empty( $result['deleted'] ) && empty( $result['removed'] ) ) {
                    wp_send_json_error( array( 'message' => __( '没有需要删除的未勾选或已失效尺寸缩略图', 'local-crush' ) ) );
                }

                wp_send_json_success( array( 'message' => __( '未勾选及已失效尺寸的缩略图已删除', 'local-crush' ) ) );

            case 'delete-backup':
                $result = local_crush()->processor->delete_attachment_backups( $attachment_id );

                if ( empty( $result['ok'] ) ) {
                    wp_send_json_error( array( 'message' => __( '删除失败', 'local-crush' ) ) );
                }

                wp_send_json_success( array( 'message' => __( '备份已删除', 'local-crush' ) ) );

            case 'delete-records':
                if ( empty( local_crush()->database->get_records_by_attachment( $attachment_id ) ) ) {
                    wp_send_json_error( array( 'message' => __( '没有可清除的压缩记录', 'local-crush' ) ) );
                }

                $result = local_crush()->processor->delete_compression_records( $attachment_id );

                if ( null === $result ) {
                    wp_send_json_error( array( 'message' => __( '清除失败，这张图片正在被其他操作占用', 'local-crush' ) ) );
                }

                if ( ! $result ) {
                    wp_send_json_error( array( 'message' => __( '清除失败，请重试', 'local-crush' ) ) );
                }

                wp_send_json_success( array( 'message' => __( '压缩记录已清除', 'local-crush' ) ) );

            case 'delete-scaled-main':
                $result = $manager->delete_scaled_main( $attachment_id );

                if ( null === $result ) {
                    wp_send_json_error( array( 'message' => __( '删除失败，这张图片正在被其他操作占用', 'local-crush' ) ) );
                }

                if ( empty( $result['deleted'] ) ) {
                    wp_send_json_error( array( 'message' => __( '这张图片的主图不是 -scaled 缩放图，或未缩放原图已不存在', 'local-crush' ) ) );
                }

                wp_send_json_success( array( 'message' => __( '-scaled 缩放图已删除，主图已退回未缩放原图', 'local-crush' ) ) );

            case 'delete-original':
                $result = local_crush()->processor->delete_original_image( $attachment_id );

                if ( ! empty( $result['failed'] ) ) {
                    wp_send_json_error( array( 'message' => __( '删除失败', 'local-crush' ) ) );
                }

                if ( empty( $result['deleted_files'] ) ) {
                    wp_send_json_error( array( 'message' => __( '没有可删除的未缩放原始大图', 'local-crush' ) ) );
                }

                wp_send_json_success( array( 'message' => __( '未缩放的原始大图已删除', 'local-crush' ) ) );

            case 'generate-missing':
                $result = $manager->generate_missing_thumbnails( $attachment_id );

                if ( null === $result ) {
                    wp_send_json_error( array( 'message' => __( '补生成失败', 'local-crush' ) ) );
                }

                if ( 'disabled' === $result ) {
                    wp_send_json_error( array( 'message' => __( '这张图片已设为不再生成缩略图，先关掉开关再补生成', 'local-crush' ) ) );
                }

                if ( 'unsupported_format' === $result ) {
                    wp_send_json_error( array( 'message' => __( '当前格式没有可用的原图，无法补生成缩略图', 'local-crush' ) ) );
                }

                if ( is_int( $result ) && $result > 0 ) {
                    wp_send_json_success(
                        array(
                            'message' => sprintf(
                                /* translators: %d: 新增缩略图数量 */
                                __( '已补生成 %d 个缩略图', 'local-crush' ),
                                (int) $result
                            ),
                        )
                    );
                }

                wp_send_json_error( array( 'message' => __( '没有缺失的尺寸需要补生成', 'local-crush' ) ) );
        }

        wp_send_json_error( array( 'message' => __( '无法识别的操作', 'local-crush' ) ) );
    }

    /**
     * 格式化文件大小
     *
     * @param int $bytes 字节数
     * @return string 格式化后的大小
     */
    private function format_file_size( int $bytes ): string {
        $units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
        $value = max( 0, (float) $bytes );
        $index = 0;

        while ( $value >= 1024 && $index < count( $units ) - 1 ) {
            $value /= 1024;
            $index++;
        }

        if ( 0 === $index ) {
            return (string) (int) $value . $units[ $index ];
        }

        return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' ) . $units[ $index ];
    }
}
