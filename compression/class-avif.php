<?php
/**
 * Avifenc AVIF 压缩渠道
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Avifenc AVIF 压缩渠道
 *
 * 使用 libavif 的 avifenc 命令行工具压缩 AVIF 图片。
 * avifenc 不能直接读取 AVIF 文件，因此先用 avifdec 无损解码为
 * PNG 中间文件，再重新编码回 AVIF 并替换原文件。
 */
class Local_Crush_Avif extends Local_Crush_Tool_Base {

    /**
     * 缓存的解码器路径
     *
     * @var string|false|null
     */
    protected $decoder_path = null;

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'avifenc';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'avif' );
    }

    /**
     * 获取可执行文件名（编码器）
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'avifenc';
    }

    /**
     * 获取解码器（avifdec）路径
     *
     * 查找顺序与编码器一致，public 供设置页展示解码器的安装状态与路径。
     *
     * @return string|false 解码器路径或 false
     */
    public function get_decoder_path() {
        // 使用缓存
        if ( null !== $this->decoder_path ) {
            return $this->decoder_path;
        }

        $this->decoder_path = self::resolve_executable( 'avifdec' );

        return $this->decoder_path;
    }

    /**
     * 检查工具是否可用
     *
     * 编码器与解码器必须同时存在
     *
     * @return bool 是否可用
     */
    public function is_tool_available(): bool {
        if ( ! $this->has_encoder() ) {
            return false;
        }

        return false !== $this->get_decoder_path();
    }

    /**
     * 检查编码器是否可用
     *
     * 目标格式输出只需要 avifenc；同格式 AVIF 压缩还需要 avifdec。
     *
     * @return bool
     */
    public function has_encoder(): bool {
        return parent::is_tool_available();
    }

    /**
     * 构建压缩命令链
     *
     * 同格式 AVIF 压缩分两步：先无损解码成 PNG 中间文件，再用 avifenc 压回 AVIF。
     * 两条命令分开返回，由基类顺序执行，任一步失败即整体失败。
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return array[] 命令链
     */
    protected function build_command_chain( string $file_path, array $options ): array {
        $decoder = $this->get_decoder_path();

        // 中间 PNG 与编码结果都走确定性路径，由基类统一替换和兜底清理。
        $temp_png  = $file_path . '.lc-avif.png';
        $temp_avif = $this->get_temp_output_path( $file_path );

        $decode = array( $decoder, $file_path, $temp_png );
        $encode = array_merge(
            array( $this->get_executable_path() ),
            self::build_encode_args( 'avif', $temp_png, $temp_avif )
        );

        return array( $decode, $encode );
    }

    /**
     * 构建 avifenc 的参数
     *
     * 同格式压缩与格式转换共用这一份参数拼装，两份设置各自独立。
     *
     * @param string $group            设置分组，固定为 avif
     * @param string $source           源文件路径
     * @param string $output           输出文件路径
     * @param string $orientation_exif 只含方向的 EXIF 临时文件；空字符串表示不写方向
     * @return array 参数数组（不含可执行文件）
     */
    public static function build_encode_args( string $group, string $source, string $output, string $orientation_exif = '' ): array {
        $lossless = 'lossless' === Local_Crush_Settings::param_mode( $group );
        $quality  = Local_Crush_Settings::param_int( $group . '_quality' );

        $args = array(
            '-j',
            (string) Local_Crush_Settings::param_int( $group . '_jobs' ),
            '-s',
            (string) Local_Crush_Settings::param_int( $group . '_speed' ),
        );

        $depth = Local_Crush_Settings::param_choice( $group . '_depth' );

        if ( '8' !== $depth ) {
            $args[] = '-d';
            $args[] = $depth;
        }

        // avifenc 默认会把输入里的 EXIF/XMP 原样搬进 AVIF，而产物是对外公开访问的文件
        if ( Local_Crush_Settings::strips_metadata() ) {
            $args[] = '--ignore-exif';
            $args[] = '--ignore-xmp';

            // 只补回方向，其他 EXIF（GPS、设备信息）仍然不进入产物。
            if ( '' !== $orientation_exif ) {
                $args[] = '--exif';
                $args[] = $orientation_exif;
            }
        }

        if ( $lossless ) {
            $args[] = '--lossless';
        } else {
            $args[] = '-q';
            $args[] = (string) $quality;

            // 透明通道单独编码；不传时 avifenc 直接套用颜色质量，半透明边缘会被一起压。
            $args[] = '--qalpha';
            $args[] = (string) Local_Crush_Settings::param_int( $group . '_alpha_quality' );

            // 色度抽样只存在于有损 YUV：4:2:0 更小但边缘颜色会糊，无损模式下不传。
            $chroma = Local_Crush_Settings::param_choice( $group . '_chroma' );

            if ( 'auto' !== $chroma ) {
                $args[] = '-y';
                $args[] = $chroma;

                if ( '420' === $chroma ) {
                    $args[] = '--sharpyuv';
                }
            }
        }

        $args[] = $source;
        $args[] = $output;

        return $args;
    }

    /**
     * 获取编码结果的临时文件路径
     *
     * @param string $file_path 文件路径
     * @return string
     */
    protected function get_temp_output_path( string $file_path ): string {
        return $file_path . '.lc-avif.avif';
    }

    /**
     * 获取解码中间 PNG 的路径
     *
     * 中间文件由基类兜底清理，不再由命令里的 del/rm 处理。
     *
     * @param string $file_path 文件路径
     * @return string[]
     */
    protected function get_extra_temp_paths( string $file_path ): array {
        $temp_png = $file_path . '.lc-avif.png';

        // avifdec 拒绝覆盖已存在的输出：上次中断留下的中间 PNG 必须先删掉。
        if ( is_file( $temp_png ) && ! is_link( $temp_png ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            unlink( $temp_png );
        }

        return array( $temp_png );
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://github.com/AOMediaCodec/libavif';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'sudo apt-get install libavif-bin',
            'debian'  => 'sudo apt-get install libavif-bin',
            'centos'  => __( '暂无官方包，建议从 GitHub Releases 下载或自行编译', 'local-crush' ),
            'fedora'  => 'sudo dnf install libavif-tools',
            'macos'   => 'brew install libavif',
            'windows' => __( '从 libavif GitHub Releases 下载静态编译的 avifenc.exe 和 avifdec.exe', 'local-crush' ),
        );
    }
}
