<?php
/**
 * 数据库操作类
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 本文件拼接进 SQL 的只有 $wpdb->prefix 生成的固定表名和内置 MIME 白名单（逐项 esc_sql），都不含用户输入。

/**
 * 数据库操作类
 *
 * 负责创建和管理压缩记录表、备份记录表
 */
class Local_Crush_Database {

    /**
     * 压缩记录表名
     *
     * @var string
     */
    private $records_table;

    /**
     * 备份记录表名
     *
     * @var string
     */
    private $backups_table;

    /**
     * 构造函数
     */
    public function __construct() {
        global $wpdb;
        $this->records_table = $wpdb->prefix . 'local_crush_records';
        $this->backups_table = $wpdb->prefix . 'local_crush_backups';
    }

    /**
     * 获取压缩记录表名
     *
     * @return string
     */
    public function get_records_table() {
        return $this->records_table;
    }

    /**
     * 获取备份记录表名
     *
     * @return string
     */
    public function get_backups_table() {
        return $this->backups_table;
    }

    /**
     * 创建数据库表
     */
    public function create_tables() {
        $this->create_records_table();
        $this->create_backups_table();
    }

    /**
     * 创建压缩记录表
     */
    private function create_records_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->records_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            size_type VARCHAR(189) NOT NULL DEFAULT 'full',
            original_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            compressed_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            compression_ratio DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            tool_name VARCHAR(50) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            error_message TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY status (status),
            UNIQUE KEY attachment_size (attachment_id, size_type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * 创建备份记录表
     */
    private function create_backups_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->backups_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            original_path VARCHAR(500) NOT NULL,
            backup_path VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY original_path (original_path)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
    
    /* 
     * 添加压缩记录
     *
     * @param array $data 记录数据
     * @return int|false 插入的记录 ID 或 false
     */
    public function add_record( $data ) {
        global $wpdb;

        // 验证必需字段
        if ( empty( $data['attachment_id'] ) || empty( $data['file_path'] ) ) {
            return false;
        }

        $file_path = self::sanitize_stored_path( (string) $data['file_path'] );

        if ( '' === $file_path ) {
            return false;
        }

        // 清理和验证数据
        $insert_data = array(
            'attachment_id'     => absint( $data['attachment_id'] ),
            'file_path'         => $file_path,
            'size_type'         => isset( $data['size_type'] ) ? sanitize_text_field( $data['size_type'] ) : 'full',
            'original_size'     => isset( $data['original_size'] ) ? absint( $data['original_size'] ) : 0,
            'compressed_size'   => isset( $data['compressed_size'] ) ? absint( $data['compressed_size'] ) : 0,
            'compression_ratio' => isset( $data['compression_ratio'] ) ? floatval( $data['compression_ratio'] ) : 0.00,
            'tool_name'         => isset( $data['tool_name'] ) ? sanitize_text_field( $data['tool_name'] ) : '',
            'status'            => isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'success',
            'error_message'     => isset( $data['error_message'] ) ? sanitize_textarea_field( $data['error_message'] ) : '',
        );

        $format = array(
            '%d', // attachment_id
            '%s', // file_path
            '%s', // size_type
            '%d', // original_size
            '%d', // compressed_size
            '%f', // compression_ratio
            '%s', // tool_name
            '%s', // status
            '%s', // error_message
        );

        $result = $wpdb->insert( $this->records_table, $insert_data, $format );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * 根据附件 ID 和尺寸类型获取单条记录
     *
     * @param int    $attachment_id 附件 ID
     * @param string $size_type     尺寸类型
     * @return array|null 压缩记录或 null
     */
    public function get_record( $attachment_id, $size_type = 'full' ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );
        $size_type     = sanitize_text_field( $size_type );

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->records_table} WHERE attachment_id = %d AND size_type = %s",
                $attachment_id,
                $size_type
            ),
            ARRAY_A
        );
    }

    /**
     * 获取某个附件的全部压缩记录
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function get_records_by_attachment( $attachment_id ): array {
        global $wpdb;

        $attachment_id = absint( $attachment_id );

        if ( ! $attachment_id ) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->records_table} WHERE attachment_id = %d ORDER BY id ASC",
                $attachment_id
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * 更新压缩记录
     *
     * @param int   $record_id 记录 ID
     * @param array $data      更新数据
     * @return bool
     */
    public function update_record( $record_id, $data ) {
        global $wpdb;

        $record_id = absint( $record_id );

        if ( ! $record_id ) {
            return false;
        }

        $update_data = array();
        $format      = array();

        if ( isset( $data['file_path'] ) ) {
            $file_path = self::sanitize_stored_path( (string) $data['file_path'] );

            if ( '' === $file_path ) {
                return false;
            }

            $update_data['file_path'] = $file_path;
            $format[]                  = '%s';
        }

        if ( isset( $data['original_size'] ) ) {
            $update_data['original_size'] = absint( $data['original_size'] );
            $format[]                     = '%d';
        }

        if ( isset( $data['compressed_size'] ) ) {
            $update_data['compressed_size'] = absint( $data['compressed_size'] );
            $format[]                       = '%d';
        }

        if ( isset( $data['compression_ratio'] ) ) {
            $update_data['compression_ratio'] = floatval( $data['compression_ratio'] );
            $format[]                         = '%f';
        }

        if ( isset( $data['tool_name'] ) ) {
            $update_data['tool_name'] = sanitize_text_field( $data['tool_name'] );
            $format[]                  = '%s';
        }

        if ( isset( $data['status'] ) ) {
            $update_data['status'] = sanitize_text_field( $data['status'] );
            $format[]              = '%s';
        }

        if ( isset( $data['error_message'] ) ) {
            $update_data['error_message'] = sanitize_textarea_field( $data['error_message'] );
            $format[]                     = '%s';
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $result = $wpdb->update(
            $this->records_table,
            $update_data,
            array( 'id' => $record_id ),
            $format,
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * 删除单条压缩记录
     *
     * @param int $record_id 记录 ID
     * @return bool
     */
    public function delete_record( $record_id ) {
        global $wpdb;

        $record_id = absint( $record_id );

        if ( ! $record_id ) {
            return false;
        }

        $result = $wpdb->delete(
            $this->records_table,
            array( 'id' => $record_id ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * 根据附件删除压缩记录
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function delete_records_by_attachment( $attachment_id ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );

        if ( ! $attachment_id ) {
            return false;
        }

        $result = $wpdb->delete(
            $this->records_table,
            array( 'attachment_id' => $attachment_id ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * 按附件 ID 游标获取图片附件
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @param int $limit    本批数量
     * @return int[]
     */
    public function get_image_attachment_ids_after( $after_id = 0, $limit = 100 ): array {
        global $wpdb;

        $after_id = absint( $after_id );
        $limit    = max( 1, min( 200, absint( $limit ) ) );
        $mimes    = $this->get_compressible_image_mimes();
        $mimes_sql = "'" . implode( "','", array_map( 'esc_sql', $mimes ) ) . "'";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_type = 'attachment'
                AND post_status != 'trash'
                AND post_mime_type IN ({$mimes_sql})
                AND ID > %d
                ORDER BY ID ASC
                LIMIT %d",
                $after_id,
                $limit
            )
        );

        return array_map( 'absint', $ids );
    }

    /**
     * 按附件 ID 游标获取“带未缩放原图”的图片附件
     *
     * 未缩放原图记在附件的 _wp_attachment_metadata 里（original_image 键），
     * 没有独立字段可查，只能按元数据内容筛。meta_key 上有索引，
     * 扫描范围限定在附件元数据这一批，不会被文章正文拖累。
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @param int $limit    本批数量
     * @return int[]
     */
    public function get_original_image_attachment_ids_after( $after_id = 0, $limit = 100 ): array {
        global $wpdb;

        $after_id = absint( $after_id );
        $limit    = max( 1, min( 200, absint( $limit ) ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.post_id FROM {$wpdb->postmeta} p
                INNER JOIN {$wpdb->posts} o ON o.ID = p.post_id
                WHERE p.meta_key = '_wp_attachment_metadata'
                AND p.meta_value LIKE %s
                AND o.post_type = 'attachment'
                AND o.post_status != 'trash'
                AND p.post_id > %d
                ORDER BY p.post_id ASC
                LIMIT %d",
                '%' . $wpdb->esc_like( 'original_image' ) . '%',
                $after_id,
                $limit
            )
        );

        return array_map( 'absint', $ids );
    }

    /**
     * 统计带未缩放原图的图片附件数量
     *
     * @return int
     */
    public function count_original_image_attachments(): int {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} p
                INNER JOIN {$wpdb->posts} o ON o.ID = p.post_id
                WHERE p.meta_key = '_wp_attachment_metadata'
                AND p.meta_value LIKE %s
                AND o.post_type = 'attachment'
                AND o.post_status != 'trash'",
                '%' . $wpdb->esc_like( 'original_image' ) . '%'
            )
        );
    }

    /**
     * 按附件 ID 游标获取被单独设为“不再生成缩略图”的图片附件
     *
     * 只扫带这个标记的图片：没锁定的图片不需要解除，
     * 进度条的分母也就是真正要处理的数量。
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @param int $limit    本批数量
     * @return int[]
     */
    public function get_no_thumbnails_attachment_ids_after( $after_id = 0, $limit = 20 ): array {
        global $wpdb;

        $after_id = absint( $after_id );
        $limit    = max( 1, min( 100, absint( $limit ) ) );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT m.post_id FROM {$wpdb->postmeta} m
                INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
                WHERE m.meta_key = %s
                  AND m.meta_value = '1'
                  AND m.post_id > %d
                  AND p.post_type = 'attachment'
                  AND p.post_status <> 'trash'
                ORDER BY m.post_id ASC
                LIMIT %d",
                Local_Crush_Thumbnail_Manager::SKIP_META_KEY,
                $after_id,
                $limit
            )
        );

        return array_map( 'absint', $ids );
    }

    /**
     * 统计被单独设为“不再生成缩略图”的图片附件数
     *
     * @return int
     */
    public function count_no_thumbnails_attachments(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} m
                INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
                WHERE m.meta_key = %s
                  AND m.meta_value = '1'
                  AND p.post_type = 'attachment'
                  AND p.post_status <> 'trash'",
                Local_Crush_Thumbnail_Manager::SKIP_META_KEY
            )
        );
    }

    /**
     * 统计图片附件总数
     *
     * 批量压缩与缩略图任务按“看过多少张图”推进进度，分母就是它。
     *
     * @return int
     */
    public function count_image_attachments(): int {
        global $wpdb;

        $mimes     = $this->get_compressible_image_mimes();
        $mimes_sql = "'" . implode( "','", array_map( 'esc_sql', $mimes ) ) . "'";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_status != 'trash'
            AND post_mime_type IN ({$mimes_sql})"
        );
    }

    /**
     * 获取插件当前负责的图片 MIME 类型。
     *
     * @return string[]
     */
    private function get_compressible_image_mimes(): array {
        return array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/jxl', 'image/gif', 'image/svg+xml' );
    }

    /**
     * 清理 uploads 内的相对路径
     *
     * 不能用 sanitize_text_field()：它会把文件名里的 %20 等百分号编码整段删掉，
     * 导致记录与磁盘上的真实文件名对不上。这里只做路径安全处理，保留百分号。
     *
     * @param string $path 相对路径
     * @return string 非法时返回空字符串
     */
    private static function sanitize_stored_path( string $path ): string {
        $path = wp_normalize_path( $path );
        $path = preg_replace( '/[\x00-\x1F\x7F]/', '', $path );

        if ( ! is_string( $path ) ) {
            return '';
        }

        $path = ltrim( $path, '/' );

        if ( '' === $path || false !== strpos( $path, '..' ) ) {
            return '';
        }

        return $path;
    }

    /**
     * 添加备份记录
     *
     * @param array $data 备份数据
     * @return int|false
     */
    public function add_backup( $data ) {
        global $wpdb;

        if ( empty( $data['attachment_id'] ) || empty( $data['original_path'] ) || empty( $data['backup_path'] ) ) {
            return false;
        }

        $original_path = self::sanitize_stored_path( (string) $data['original_path'] );
        $backup_path   = self::sanitize_stored_path( (string) $data['backup_path'] );

        if ( '' === $original_path || '' === $backup_path ) {
            return false;
        }

        $insert_data = array(
            'attachment_id' => absint( $data['attachment_id'] ),
            'original_path' => $original_path,
            'backup_path'   => $backup_path,
        );

        $format = array( '%d', '%s', '%s' );

        $result = $wpdb->insert( $this->backups_table, $insert_data, $format );
        $insert_id = $wpdb->insert_id;

        return $result ? $insert_id : false;
    }

    /**
     * 根据附件获取备份列表
     *
     * @param int $attachment_id 附件 ID
     * @return array
     */
    public function get_backups_by_attachment( $attachment_id ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );

        // 按建立顺序返回：恢复流程取第一条当原图，旧版本可能为同一附件留下多条，
        // 最早那条才是未压缩原图，不能交给不确定的返回顺序决定。
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->backups_table} WHERE attachment_id = %d ORDER BY id ASC",
                $attachment_id
            ),
            ARRAY_A
        );
    }

    /**
     * 按附件 ID 与原始路径精确匹配单条备份
     *
     * 只按路径匹配会让后一个附件白拿前一个附件的备份，
     * 结果它自己恢复不了，而清理前一个附件时又把它唯一的恢复依据一起删掉。
     *
     * @param int    $attachment_id 附件 ID
     * @param string $original_path uploads 目录内的相对路径
     * @return array|null
     */
    public function get_backup_by_attachment_and_path( $attachment_id, $original_path ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );
        $original_path = self::sanitize_stored_path( (string) $original_path );

        if ( '' === $original_path ) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->backups_table} WHERE attachment_id = %d AND original_path = %s",
                $attachment_id,
                $original_path
            ),
            ARRAY_A
        );
    }

    /**
     * 删除单条备份
     *
     * @param int $backup_id 备份 ID
     * @return bool
     */
    public function delete_backup( $backup_id ) {
        global $wpdb;

        $backup_id = absint( $backup_id );

        if ( ! $backup_id ) {
            return false;
        }

        $result = $wpdb->delete(
            $this->backups_table,
            array( 'id' => $backup_id ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * 根据附件删除备份
     *
     * @param int $attachment_id 附件 ID
     * @return bool
     */
    public function delete_backups_by_attachment( $attachment_id ) {
        global $wpdb;

        $attachment_id = absint( $attachment_id );

        if ( ! $attachment_id ) {
            return false;
        }

        $result = $wpdb->delete(
            $this->backups_table,
            array( 'attachment_id' => $attachment_id ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * 按附件 ID 游标获取原图备份已全部过期的附件
     *
     * 到期时间必须由 MySQL 的 NOW() 计算：WordPress 把 PHP 时区强制设成 UTC，
     * 而 created_at 由数据库时钟写入，用 PHP 时间戳去比会让保留期整体偏移数小时。
     * 用 MAX(created_at) 判断是为了让同一附件的所有备份一起淘汰——只删一部分会
     * 把恢复做成“主图回到原图、缩略图还是压缩版”的半新半旧状态。
     *
     * @param int $days     保留天数，小于 1 表示不清理
     * @param int $after_id 上一批最后处理的附件 ID
     * @param int $limit    本批数量
     * @return int[]
     */
    public function get_expired_backup_attachment_ids_after( $days, $after_id = 0, $limit = 20 ): array {
        global $wpdb;

        // 不用 absint：-1（永久保留）会被它变成 1，等于把保留期改成一天。
        $days     = is_numeric( $days ) ? (int) $days : 0;
        $after_id = absint( $after_id );
        $limit    = max( 1, min( 100, absint( $limit ) ) );

        if ( $days < 1 ) {
            return array();
        }

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT attachment_id FROM {$this->backups_table}
                 WHERE attachment_id > %d
                 GROUP BY attachment_id
                 HAVING MAX(created_at) < DATE_SUB(NOW(), INTERVAL %d DAY)
                 ORDER BY attachment_id ASC
                 LIMIT %d",
                $after_id,
                $days,
                $limit
            )
        );

        return array_map( 'absint', (array) $ids );
    }

    /**
     * 按附件 ID 游标获取留有兼容格式回退的附件
     *
     * 以格式转换映射为准：只有转换过的附件才在媒体库里留下旧格式回退文件。
     * 文件是否还在磁盘上由调用方逐个确认，这里只负责把候选范围缩到最小。
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @param int $limit    本批数量
     * @return int[]
     */
    public function get_fallback_attachment_ids_after( $after_id = 0, $limit = 20 ): array {
        global $wpdb;

        $after_id = absint( $after_id );
        $limit    = max( 1, min( 100, absint( $limit ) ) );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT m.post_id FROM {$wpdb->postmeta} m
                INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
                WHERE m.meta_key = %s
                  AND m.post_id > %d
                  AND p.post_type = 'attachment'
                  AND p.post_status <> 'trash'
                ORDER BY m.post_id ASC
                LIMIT %d",
                Local_Crush_Output::OUTPUT_META_KEY,
                $after_id,
                $limit
            )
        );

        return array_map( 'absint', $ids );
    }

    /**
     * 统计留有兼容格式回退的附件数
     *
     * @return int
     */
    public function count_fallback_attachments(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} m
                INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
                WHERE m.meta_key = %s
                  AND p.post_type = 'attachment'
                  AND p.post_status <> 'trash'",
                Local_Crush_Output::OUTPUT_META_KEY
            )
        );
    }

    /**
     * 按附件 ID 游标获取仍有原图备份索引的附件
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @param int $limit    本批数量
     * @return int[]
     */
    public function get_backup_attachment_ids_after( $after_id = 0, $limit = 20 ): array {
        global $wpdb;

        $after_id = absint( $after_id );
        $limit    = max( 1, min( 100, absint( $limit ) ) );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT b.attachment_id FROM {$this->backups_table} b
                INNER JOIN {$wpdb->posts} p ON p.ID = b.attachment_id
                WHERE b.attachment_id > %d
                  AND p.post_type = 'attachment'
                  AND p.post_status <> 'trash'
                GROUP BY b.attachment_id
                ORDER BY b.attachment_id ASC
                LIMIT %d",
                $after_id,
                $limit
            )
        );

        return array_map( 'absint', $ids );
    }

    /**
     * 统计仍有原图备份索引的附件数
     *
     * @return int
     */
    public function count_backup_attachments(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT( DISTINCT b.attachment_id ) FROM {$this->backups_table} b
            INNER JOIN {$wpdb->posts} p ON p.ID = b.attachment_id
            WHERE p.post_type = 'attachment'
              AND p.post_status <> 'trash'"
        );
    }

    /**
     * 按附件 ID 游标获取需要执行恢复流程的附件
     *
     * 包含仍有备份索引的附件，以及引用已还原但残留文件与记录尚未清理的附件。
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @param int $limit    本批数量
     * @return int[]
     */
    public function get_restore_attachment_ids_after( $after_id = 0, $limit = 20 ): array {
        global $wpdb;

        $after_id = absint( $after_id );
        $limit    = max( 1, min( 100, absint( $limit ) ) );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT attachment_id FROM (
                    SELECT DISTINCT b.attachment_id
                    FROM {$this->backups_table} b
                    INNER JOIN {$wpdb->posts} p ON p.ID = b.attachment_id
                    WHERE b.attachment_id > %d
                      AND p.post_type = 'attachment'
                      AND p.post_status <> 'trash'
                    UNION
                    SELECT DISTINCT m.post_id AS attachment_id
                    FROM {$wpdb->postmeta} m
                    INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
                    WHERE m.meta_key = %s
                      AND m.post_id > %d
                      AND p.post_type = 'attachment'
                      AND p.post_status <> 'trash'
                ) candidates
                ORDER BY attachment_id ASC
                LIMIT %d",
                $after_id,
                Local_Crush_Processor::RESTORE_STATE_META_KEY,
                $after_id,
                $limit
            )
        );

        return array_map( 'absint', $ids );
    }

    /**
     * 统计需要执行恢复流程的附件数
     *
     * @return int
     */
    public function count_restore_attachments(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM (
                    SELECT DISTINCT b.attachment_id
                    FROM {$this->backups_table} b
                    INNER JOIN {$wpdb->posts} p ON p.ID = b.attachment_id
                    WHERE p.post_type = 'attachment'
                      AND p.post_status <> 'trash'
                    UNION
                    SELECT DISTINCT m.post_id AS attachment_id
                    FROM {$wpdb->postmeta} m
                    INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
                    WHERE m.meta_key = %s
                      AND p.post_type = 'attachment'
                      AND p.post_status <> 'trash'
                ) candidates",
                Local_Crush_Processor::RESTORE_STATE_META_KEY
            )
        );
    }

    /**
     * 按附件 ID 游标获取已不存在的附件残留的处理痕迹
     *
     * 附件被永久删除时，删除钩子只做一次尽力而为的清理：拿不到锁就静默放弃，
     * 且没有任何重试入口。附件本身已经不在，原图备份、压缩记录和格式转换映射
     * 就成了永远无人认领的残留——备份文件还会继续占磁盘。
     * 定时任务据此兜底清理。
     *
     * @param int $after_id 上一批最后处理的附件 ID
     * @param int $limit    本批数量
     * @return int[]
     */
    public function get_orphan_history_attachment_ids_after( $after_id = 0, $limit = 20 ): array {
        global $wpdb;

        $after_id = absint( $after_id );
        $limit    = max( 1, min( 100, absint( $limit ) ) );

        // 回收站里的附件不算残留：移回回收站后还要能恢复原图。
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT attachment_id FROM (
                    SELECT DISTINCT r.attachment_id
                    FROM {$this->records_table} r
                    LEFT JOIN {$wpdb->posts} p ON p.ID = r.attachment_id
                    WHERE r.attachment_id > %d AND ( p.ID IS NULL OR p.post_type <> 'attachment' )
                    UNION
                    SELECT DISTINCT b.attachment_id
                    FROM {$this->backups_table} b
                    LEFT JOIN {$wpdb->posts} p ON p.ID = b.attachment_id
                    WHERE b.attachment_id > %d AND ( p.ID IS NULL OR p.post_type <> 'attachment' )
                    UNION
                    SELECT DISTINCT m.post_id AS attachment_id
                    FROM {$wpdb->postmeta} m
                    LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
                    WHERE m.meta_key = %s
                      AND m.post_id > %d
                      AND p.ID IS NULL
                ) orphan
                ORDER BY attachment_id ASC
                LIMIT %d",
                $after_id,
                $after_id,
                Local_Crush_Output::OUTPUT_META_KEY,
                $after_id,
                $limit
            )
        );

        return array_map( 'absint', $ids );
    }
}
