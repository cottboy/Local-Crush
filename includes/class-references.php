<?php
/**
 * 图片引用替换
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 扫描 WordPress 原生内容表，把指向旧图片文件的地址改写成新地址。
 *
 * 覆盖正文、摘要、自定义字段、主题设置、小工具、分类描述、用户资料、评论和友情链接。
 * 插件自己的记录、附件内部字段、临时缓存和登录会话不参与扫描。
 */
class Local_Crush_References {

    /**
     * 单页读取的行数
     *
     * @var int
     */
    const BATCH_SIZE = 200;

    /**
     * 反序列化嵌套的最大层数
     *
     * @var int
     */
    const MAX_SERIALIZE_DEPTH = 8;

    /**
     * 表是否存在（同一请求内缓存）
     *
     * @var array<string, bool>
     */
    private $table_exists = array();

    /**
     * 替换字符串中的图片地址，不访问数据库
     *
     * @param string $content      待替换的字符串
     * @param array  $replacements 每项包含 from 和 to 绝对路径
     * @return string
     */
    public function replace_content_references( string $content, array $replacements ): string {
        $pairs = $this->build_pairs( $replacements );

        if ( empty( $pairs ) ) {
            return $content;
        }

        return $this->replace_plain( $content, $pairs );
    }

    /**
     * 更新 WordPress 原生内容表中的图片引用
     *
     * @param array $replacements 每项包含 from 和 to 绝对路径
     * @param int   $replaced     输出参数：实际替换掉的引用数量
     * @return bool 是否全部更新成功
     */
    public function update_references( array $replacements, &$replaced = 0 ): bool {
        $replaced = 0;
        $pairs    = $this->build_pairs( $replacements );

        if ( empty( $pairs ) ) {
            return true;
        }

        $patterns = $this->like_patterns( $pairs );
        $success  = true;

        foreach ( $this->targets() as $target ) {
            if ( ! $this->table_exists( $target['table'] ) ) {
                continue;
            }

            if ( ! $this->update_target( $target, $pairs, $patterns, $replaced ) ) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * 需要扫描的表和字段
     *
     * 只扫 WordPress 自己存内容的地方。第三方插件自建的表格式未知，不在这里猜。
     *
     * @return array
     */
    private function targets(): array {
        global $wpdb;

        return array(
            array(
                'table'          => $wpdb->posts,
                'id'             => 'ID',
                'fields'         => array( 'post_content', 'post_excerpt', 'post_content_filtered' ),
                'keys'           => array(),
                'where'          => array(),
                'args'           => array(),
                'cache'          => 'post',
                'key_field'      => '',
                'key_deny_exact' => array(),
                'key_deny_prefix' => array(),
            ),
            array(
                'table'          => $wpdb->postmeta,
                'id'             => 'meta_id',
                'fields'         => array( 'meta_value' ),
                'keys'           => array( 'post_id' ),
                'where'          => array(
                    'meta_key NOT LIKE %s',
                    'meta_key NOT LIKE %s',
                    "meta_key <> '_wp_attached_file'",
                ),
                'args'           => array(
                    $wpdb->esc_like( '_local_crush_' ) . '%',
                    $wpdb->esc_like( '_wp_attachment_' ) . '%',
                ),
                'cache'          => 'post_meta',
                'key_field'      => 'meta_key',
                'key_deny_exact' => array( '_wp_attached_file' ),
                'key_deny_prefix' => array( '_local_crush_', '_wp_attachment_' ),
            ),
            array(
                'table'          => $wpdb->options,
                'id'             => 'option_id',
                'fields'         => array( 'option_value' ),
                'keys'           => array( 'option_name' ),
                'where'          => array(
                    'option_name NOT LIKE %s',
                    'option_name NOT LIKE %s',
                    'option_name NOT LIKE %s',
                ),
                'args'           => array(
                    $wpdb->esc_like( 'local_crush_' ) . '%',
                    $wpdb->esc_like( '_transient_' ) . '%',
                    $wpdb->esc_like( '_site_transient_' ) . '%',
                ),
                'cache'          => 'option',
                'key_field'      => 'option_name',
                'key_deny_exact' => array(),
                'key_deny_prefix' => array( 'local_crush_', '_transient_', '_site_transient_' ),
            ),
            array(
                'table'          => $wpdb->termmeta,
                'id'             => 'meta_id',
                'fields'         => array( 'meta_value' ),
                'keys'           => array( 'term_id' ),
                'where'          => array( 'meta_key NOT LIKE %s' ),
                'args'           => array( $wpdb->esc_like( '_local_crush_' ) . '%' ),
                'cache'          => 'term_meta',
                'key_field'      => 'meta_key',
                'key_deny_exact' => array(),
                'key_deny_prefix' => array( '_local_crush_' ),
            ),
            array(
                'table'          => $wpdb->usermeta,
                'id'             => 'umeta_id',
                'fields'         => array( 'meta_value' ),
                'keys'           => array( 'user_id' ),
                'where'          => array(
                    'meta_key NOT LIKE %s',
                    "meta_key <> 'session_tokens'",
                ),
                'args'           => array( $wpdb->esc_like( '_local_crush_' ) . '%' ),
                'cache'          => 'user_meta',
                'key_field'      => 'meta_key',
                'key_deny_exact' => array( 'session_tokens' ),
                'key_deny_prefix' => array( '_local_crush_' ),
            ),
            array(
                'table'          => $wpdb->comments,
                'id'             => 'comment_ID',
                'fields'         => array( 'comment_content', 'comment_author_url' ),
                'keys'           => array(),
                'where'          => array(),
                'args'           => array(),
                'cache'          => 'comment',
                'key_field'      => '',
                'key_deny_exact' => array(),
                'key_deny_prefix' => array(),
            ),
            array(
                'table'          => $wpdb->commentmeta,
                'id'             => 'meta_id',
                'fields'         => array( 'meta_value' ),
                'keys'           => array( 'comment_id' ),
                'where'          => array( 'meta_key NOT LIKE %s' ),
                'args'           => array( $wpdb->esc_like( '_local_crush_' ) . '%' ),
                'cache'          => 'comment_meta',
                'key_field'      => 'meta_key',
                'key_deny_exact' => array(),
                'key_deny_prefix' => array( '_local_crush_' ),
            ),
            array(
                'table'          => $wpdb->term_taxonomy,
                'id'             => 'term_taxonomy_id',
                'fields'         => array( 'description' ),
                'keys'           => array( 'term_id' ),
                'where'          => array(),
                'args'           => array(),
                'cache'          => 'term',
                'key_field'      => '',
                'key_deny_exact' => array(),
                'key_deny_prefix' => array(),
            ),
            array(
                'table'          => $wpdb->users,
                'id'             => 'ID',
                'fields'         => array( 'user_url' ),
                'keys'           => array(),
                'where'          => array(),
                'args'           => array(),
                'cache'          => 'user',
                'key_field'      => '',
                'key_deny_exact' => array(),
                'key_deny_prefix' => array(),
            ),
            array(
                'table'          => $wpdb->links,
                'id'             => 'link_id',
                'fields'         => array( 'link_url', 'link_image', 'link_description', 'link_notes' ),
                'keys'           => array(),
                'where'          => array(),
                'args'           => array(),
                'cache'          => 'bookmark',
                'key_field'      => '',
                'key_deny_exact' => array(),
                'key_deny_prefix' => array(),
            ),
        );
    }

    /**
     * 分页更新一张表
     *
     * @param array $target   表定义
     * @param array $pairs    替换对
     * @param array $patterns SQL 粗筛模式
     * @param int   $replaced 输出参数：替换数量
     * @return bool 是否全部写入成功
     */
    private function update_target( array $target, array $pairs, array $patterns, int &$replaced ): bool {
        global $wpdb;

        $after    = 0;
        $success  = true;
        $id_field = $target['id'];
        $select   = implode( ', ', array_unique( array_merge( array( $id_field ), $target['keys'], $target['fields'] ) ) );

        do {
            $conditions      = array( $id_field . ' > %d' );
            $like_conditions = array();
            $args            = array( $after );

            foreach ( $target['fields'] as $field ) {
                foreach ( $patterns as $pattern ) {
                    $like_conditions[] = $field . ' LIKE %s';
                    $args[]            = '%' . $wpdb->esc_like( $pattern ) . '%';
                }
            }

            // 字段之间必须是 OR，且整体要和游标、黑名单条件用括号分开，否则 AND 会先结合。
            $conditions[] = '(' . implode( ' OR ', $like_conditions ) . ')';

            foreach ( $target['where'] as $where ) {
                $conditions[] = $where;
            }

            $args   = array_merge( $args, $target['args'] );
            $args[] = self::BATCH_SIZE;

            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- 表名和字段名来自 wpdb 与固定的表定义，LIKE 模式全部经 esc_like 处理并由 prepare 绑定。
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT {$select} FROM {$target['table']}
                    WHERE " . implode( ' AND ', $conditions ) . "
                    ORDER BY {$id_field} ASC
                    LIMIT %d",
                    $args
                )
            );
            // phpcs:enable

            if ( ! is_array( $rows ) ) {
                return false;
            }

            foreach ( $rows as $row ) {
                $after = max( $after, (int) $row->{$id_field} );

                if ( ! $this->row_allowed( $target, $row ) ) {
                    continue;
                }

                if ( ! $this->update_row( $target, $row, $pairs, $replaced ) ) {
                    $success = false;
                }
            }

            $page_size = count( $rows );
        } while ( $page_size >= self::BATCH_SIZE );

        return $success;
    }

    /**
     * 处理一行数据
     *
     * @param array $target   表定义
     * @param object $row     数据行
     * @param array $pairs    替换对
     * @param int   $replaced 输出参数：替换数量
     * @return bool 是否写入成功
     */
    private function update_row( array $target, $row, array $pairs, int &$replaced ): bool {
        $changes = $this->replacement_for_row( $target, $row, $pairs );

        if ( empty( $changes ) ) {
            return true;
        }

        if ( $this->write_row( $target, $row, $changes ) ) {
            $replaced += $this->count_changes( $changes, $pairs );
            $this->clear_cache( $target, $row );

            return true;
        }

        // 写入失败通常是这一行被并发改过，重读最新值再替换一次。
        $fresh = $this->read_row( $target, (int) $row->{$target['id']} );

        if ( null === $fresh || ! $this->row_allowed( $target, $fresh ) ) {
            return true;
        }

        $changes = $this->replacement_for_row( $target, $fresh, $pairs );

        if ( empty( $changes ) ) {
            return true;
        }

        if ( ! $this->write_row( $target, $fresh, $changes ) ) {
            return false;
        }

        $replaced += $this->count_changes( $changes, $pairs );
        $this->clear_cache( $target, $fresh );

        return true;
    }

    /**
     * 计算一行里哪些字段需要改写
     *
     * @param array  $target 表定义
     * @param object $row    数据行
     * @param array  $pairs  替换对
     * @return array 字段名 => array( 旧值, 新值 )
     */
    private function replacement_for_row( array $target, $row, array $pairs ): array {
        $changes = array();

        foreach ( $target['fields'] as $field ) {
            $value = isset( $row->{$field} ) ? (string) $row->{$field} : '';

            if ( '' === $value ) {
                continue;
            }

            $updated = $this->replace_value( $value, $pairs );

            if ( $updated !== $value ) {
                $changes[ $field ] = array( $value, $updated );
            }
        }

        return $changes;
    }

    /**
     * 比较旧值后写入一行
     *
     * @param array  $target  表定义
     * @param object $row     数据行
     * @param array  $changes 字段名 => array( 旧值, 新值 )
     * @return bool 是否写入成功
     */
    private function write_row( array $target, $row, array $changes ): bool {
        global $wpdb;

        $new          = array();
        $where        = array( $target['id'] => (int) $row->{$target['id']} );
        $where_format = array( '%d' );

        foreach ( $changes as $field => $change ) {
            $new[ $field ]   = $change[1];
            $where[ $field ] = $change[0];
            $where_format[]  = '%s';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $updated = $wpdb->update( $target['table'], $new, $where, array_fill( 0, count( $new ), '%s' ), $where_format );

        return 1 === (int) $updated;
    }

    /**
     * 按主键重读一行
     *
     * @param array $target 表定义
     * @param int   $id     主键
     * @return object|null
     */
    private function read_row( array $target, int $id ) {
        global $wpdb;

        $select = implode( ', ', array_unique( array_merge( array( $target['id'] ), $target['keys'], $target['fields'] ) ) );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 表名、主键和字段名都是固定的表定义，只有主键值经过 prepare 绑定。
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT {$select} FROM {$target['table']} WHERE {$target['id']} = %d", $id )
        );
        // phpcs:enable

        return is_object( $row ) ? $row : null;
    }

    /**
     * 检查重读后的行是否仍不在黑名单里
     *
     * @param array  $target 表定义
     * @param object $row    数据行
     * @return bool
     */
    private function row_allowed( array $target, $row ): bool {
        $key_field = isset( $target['key_field'] ) ? (string) $target['key_field'] : '';

        if ( '' === $key_field || ! isset( $row->{$key_field} ) ) {
            return true;
        }

        $key = (string) $row->{$key_field};

        if ( in_array( $key, $target['key_deny_exact'], true ) ) {
            return false;
        }

        foreach ( $target['key_deny_prefix'] as $prefix ) {
            if ( 0 === strpos( $key, $prefix ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * 清理被改写行的对象缓存
     *
     * @param array  $target 表定义
     * @param object $row    数据行
     */
    private function clear_cache( array $target, $row ): void {
        $id = isset( $row->{$target['id']} ) ? (int) $row->{$target['id']} : 0;

        switch ( $target['cache'] ) {
            case 'post':
                clean_post_cache( $id );
                break;
            case 'post_meta':
                wp_cache_delete( (int) $row->post_id, 'post_meta' );
                break;
            case 'option':
                $name = (string) $row->option_name;
                wp_cache_delete( $name, 'options' );
                wp_cache_delete( 'alloptions', 'options' );
                break;
            case 'term_meta':
                wp_cache_delete( (int) $row->term_id, 'term_meta' );
                break;
            case 'user_meta':
                wp_cache_delete( (int) $row->user_id, 'user_meta' );
                break;
            case 'comment':
                clean_comment_cache( $id );
                break;
            case 'comment_meta':
                wp_cache_delete( (int) $row->comment_id, 'comment_meta' );
                break;
            case 'term':
                $term_id = (int) $row->term_id;
                clean_term_cache( $term_id );
                wp_cache_delete( $term_id, 'term_meta' );
                break;
            case 'user':
                clean_user_cache( $id );
                wp_cache_delete( $id, 'user_meta' );
                break;
            case 'bookmark':
                clean_bookmark_cache( $id );
                break;
        }
    }

    /**
     * 统计一次改写新增了多少处新地址
     *
     * @param array $changes 字段名 => array( 旧值, 新值 )
     * @param array $pairs   替换对
     * @return int
     */
    private function count_changes( array $changes, array $pairs ): int {
        $count = 0;

        foreach ( $changes as $change ) {
            foreach ( $pairs as $pair ) {
                foreach ( $pair['count_needles'] as $needle ) {
                    $count += max( 0, substr_count( $change[1], $needle ) - substr_count( $change[0], $needle ) );
                }
            }
        }

        return $count;
    }

    /**
     * 判断表是否存在
     *
     * @param string $table 表名
     * @return bool
     */
    private function table_exists( string $table ): bool {
        global $wpdb;

        if ( '' === $table ) {
            return false;
        }

        if ( isset( $this->table_exists[ $table ] ) ) {
            return $this->table_exists[ $table ];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $found = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
        );

        $this->table_exists[ $table ] = is_string( $found ) && 0 === strcasecmp( $found, $table );

        return $this->table_exists[ $table ];
    }

    /**
     * 把绝对路径替换对转换成 URL 路径
     *
     * @param array $replacements 每项包含 from 和 to 绝对路径
     * @return array
     */
    private function build_pairs( array $replacements ): array {
        $pairs = array();
        $keys  = array();

        foreach ( $replacements as $replacement ) {
            if ( ! is_array( $replacement ) || empty( $replacement['from'] ) || empty( $replacement['to'] ) ) {
                continue;
            }

            $from_path = $this->content_path_of( (string) $replacement['from'] );
            $to_path   = $this->content_path_of( (string) $replacement['to'] );

            if ( '' === $from_path || '' === $to_path ) {
                continue;
            }

            $new_name = basename( $to_path );

            if ( '' === $new_name ) {
                continue;
            }

            $raw_count     = array( $to_path );
            $encoded_to    = $this->encode_url_path( $to_path );
            $encoded_count = array( $encoded_to );

            $this->add_pair( $pairs, $keys, $from_path, $new_name, $raw_count );

            // 编辑器可能把中文等非 ASCII 文件名存成百分号编码，两种写法都要处理。
            $encoded_from = $this->encode_url_path( $from_path );

            if ( $encoded_from !== $from_path ) {
                $this->add_pair( $pairs, $keys, $encoded_from, rawurlencode( $new_name ), $encoded_count );
            }
        }

        return $pairs;
    }

    /**
     * 添加一个替换对并去重
     *
     * @param array  $pairs         替换对集合
     * @param array  $keys          已存在的键
     * @param string $needle        旧地址
     * @param string $new_name      新文件名
     * @param array  $count_needles 计数用的新地址
     */
    private function add_pair( array &$pairs, array &$keys, string $needle, string $new_name, array $count_needles ): void {
        $key = $needle . '|' . $new_name;

        if ( isset( $keys[ $key ] ) ) {
            return;
        }

        $keys[ $key ] = true;
        $pairs[]      = array(
            'needle'        => $needle,
            'new_name'      => $new_name,
            'count_needles' => array_values( array_unique( $count_needles ) ),
        );
    }

    /**
     * 生成 SQL 粗筛模式，兼容区块 JSON 里的 \/ 转义
     *
     * @param array $pairs 替换对
     * @return array
     */
    private function like_patterns( array $pairs ): array {
        $patterns = array();

        foreach ( $pairs as $pair ) {
            $patterns[ $pair['needle'] ] = true;
            $patterns[ str_replace( '/', '\\/', $pair['needle'] ) ] = true;
        }

        return array_keys( $patterns );
    }

    /**
     * 替换普通字符串
     *
     * @param string $content 字符串
     * @param array  $pairs   替换对
     * @return string
     */
    private function replace_plain( string $content, array $pairs ): string {
        if ( '' === $content ) {
            return $content;
        }

        foreach ( $pairs as $pair ) {
            $content = $this->replace_needle( $content, $pair['needle'], $pair['new_name'] );
        }

        return $content;
    }

    /**
     * 替换值中的图片地址，序列化数据按结构处理
     *
     * @param string $value 原始值
     * @param array  $pairs 替换对
     * @return string
     */
    private function replace_value( string $value, array $pairs ): string {
        if ( '' === $value ) {
            return $value;
        }

        if ( is_serialized( $value ) ) {
            return $this->replace_serialized( $value, $pairs, 0 );
        }

        return $this->replace_plain( $value, $pairs );
    }

    /**
     * 替换序列化数据里的图片地址
     *
     * 递归反序列化后重新序列化，字符串长度前缀由 serialize() 重新计算。
     * 遇到对象直接放弃整个值，不实例化任何类，也不碰带魔术方法的对象。
     *
     * @param string $value 序列化字符串
     * @param array  $pairs 替换对
     * @param int    $depth 当前嵌套层数
     * @return string
     */
    private function replace_serialized( string $value, array $pairs, int $depth ): string {
        if ( $depth > self::MAX_SERIALIZE_DEPTH ) {
            return $value;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $data = @unserialize( $value, array( 'allowed_classes' => false ) );

        // b:0; 的合法结果就是 false，不能把 false 当成解析失败。
        if ( false === $data && 'b:0;' !== trim( $value ) ) {
            return $value;
        }

        $supported = true;
        $changed   = false;
        $data      = $this->replace_structure( $data, $pairs, $depth, $changed, $supported );

        if ( ! $supported || ! $changed ) {
            return $value;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        $serialized = @serialize( $data );

        return is_string( $serialized ) ? $serialized : $value;
    }

    /**
     * 递归处理反序列化后的数组和字符串
     *
     * @param mixed $data      反序列化后的值
     * @param array $pairs     替换对
     * @param int   $depth     当前嵌套层数
     * @param bool  $changed   输出参数：是否发生改动
     * @param bool  $supported 输出参数：是否包含不支持的对象
     * @return mixed
     */
    private function replace_structure( $data, array $pairs, int $depth, bool &$changed, bool &$supported ) {
        if ( is_string( $data ) ) {
            if ( '' === $data ) {
                return $data;
            }

            if ( is_serialized( $data ) ) {
                $nested = $this->replace_serialized( $data, $pairs, $depth + 1 );

                if ( $nested !== $data ) {
                    $changed = true;
                }

                return $nested;
            }

            $updated = $this->replace_plain( $data, $pairs );

            if ( $updated !== $data ) {
                $changed = true;
            }

            return $updated;
        }

        if ( is_array( $data ) ) {
            $result = array();

            foreach ( $data as $key => $item ) {
                $new_key = $key;

                if ( is_string( $key ) ) {
                    $new_key = $this->replace_plain( $key, $pairs );

                    if ( $new_key !== $key ) {
                        $changed = true;
                    }
                }

                $result[ $new_key ] = $this->replace_structure( $item, $pairs, $depth, $changed, $supported );
            }

            return $result;
        }

        if ( is_object( $data ) ) {
            $supported = false;
        }

        return $data;
    }

    /**
     * 替换内容中指向某个 uploads 文件的地址
     *
     * 只替换文件名部分，保留协议、域名、查询参数、锚点和 srcset 的候选结构；
     * 同时兼容区块 JSON 里被转义成 \/ 的路径，外站地址与同名前缀文件不动。
     *
     * @param string $content  内容
     * @param string $needle   uploads 内的 URL 路径
     * @param string $new_name 新文件名（已按 needle 的编码方式处理）
     * @return string
     */
    private function replace_needle( string $content, string $needle, string $new_name ): string {
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
     * @return string 不在上传目录内时返回空字符串
     */
    private function content_path_of( string $file_path ): string {
        $upload    = wp_get_upload_dir();
        $base_path = isset( $upload['baseurl'] ) ? wp_parse_url( $upload['baseurl'], PHP_URL_PATH ) : '';
        $base_dir  = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';

        if ( ! is_string( $base_path ) || '' === $base_path || '' === $base_dir ) {
            return '';
        }

        if ( false !== strpbrk( $file_path, "\0" ) ) {
            return '';
        }

        $base_dir = untrailingslashit( wp_normalize_path( $base_dir ) );
        $path     = wp_normalize_path( $file_path );

        foreach ( explode( '/', $path ) as $segment ) {
            if ( '..' === $segment ) {
                return '';
            }
        }

        if ( 0 !== strpos( $this->normalized_path( $path ), $this->normalized_path( $base_dir ) . '/' ) ) {
            return '';
        }

        $relative = ltrim( substr( $path, strlen( $base_dir ) ), '/' );

        return untrailingslashit( $base_path ) . '/' . $relative;
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

        $upload = wp_get_upload_dir();
        $base   = isset( $upload['baseurl'] ) ? wp_parse_url( $upload['baseurl'] ) : false;
        $site   = wp_parse_url( $token );

        if ( ! is_array( $base ) || empty( $base['host'] ) || ! is_array( $site ) || empty( $site['host'] ) ) {
            return false;
        }

        $base_scheme = isset( $base['scheme'] ) ? strtolower( (string) $base['scheme'] ) : '';
        $site_scheme = isset( $site['scheme'] ) ? strtolower( (string) $site['scheme'] ) : '';

        // 协议相对地址（//host/...）继承站点协议，用它来判断默认端口。
        if ( '' === $site_scheme ) {
            $site_scheme = $base_scheme;
        }

        $base_port = isset( $base['port'] ) ? (int) $base['port'] : null;
        $site_port = isset( $site['port'] ) ? (int) $site['port'] : null;

        if ( ( 'http' === $base_scheme && 80 === $base_port ) || ( 'https' === $base_scheme && 443 === $base_port ) ) {
            $base_port = null;
        }

        if ( ( 'http' === $site_scheme && 80 === $site_port ) || ( 'https' === $site_scheme && 443 === $site_port ) ) {
            $site_port = null;
        }

        return strtolower( $site['host'] ) === strtolower( $base['host'] ) && $site_port === $base_port;
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
}
