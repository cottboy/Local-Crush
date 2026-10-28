<?php
/**
 * 防止直接访问
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JPEG XL 压缩渠道
 *
 * 使用 libjxl 的 cjxl 命令行工具。cjxl 能直接读 JPEG XL（包括动画），
 * 所以同格式压缩就是一条命令重编码，不需要拆帧。
 */
class Local_Crush_Cjxl extends Local_Crush_Tool_Base {

    /**
     * 只含方向的最小 EXIF 临时文件（JXL 要 TIFF 本体，不要 Exif\0\0 头）
     *
     * @var string
     */
    private $temp_exif = '';

    /**
     * 获取工具名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'cjxl';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'jxl' );
    }

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'cjxl';
    }

    /**
     * 构建 cjxl 命令
     *
     * 动画层与转换层共用这一个参数出口，避免同一个工具的参数在两处各写一遍。
     *
     * @param string $source           输入文件路径
     * @param string $output           输出文件路径
     * @param string $group            设置分组，固定为 jxl
     * @param string $orientation_exif 只含方向的 TIFF 临时文件；空字符串表示不写方向
     * @return array|false 参数数组，工具不可用时返回 false
     */
    public static function build_command( string $source, string $output, string $group = 'jxl', string $orientation_exif = '' ) {
        $cjxl = Local_Crush_Output::find_local_tool( 'cjxl' );

        if ( false === $cjxl ) {
            return false;
        }

        $lossless = 'lossless' === Local_Crush_Settings::param_mode( $group );

        $command = array(
            $cjxl,
            '-e',
            (string) Local_Crush_Settings::param_int( $group . '_effort' ),
        );

        if ( $lossless ) {
            // 距离 0 即无损
            $command[] = '-d';
            $command[] = '0';
        } else {
            $command[] = '-q';
            $command[] = (string) Local_Crush_Settings::param_int( $group . '_quality' );
        }

        // JPEG 输入时 cjxl 默认走"JPEG 无损重构"，与 -q/-d 冲突会直接失败，统一关掉
        $command[] = '--lossless_jpeg=0';

        // 隐私清理必须明确告诉 cjxl 删除容器里的 Exif、XMP 与 JUMBF。
        if ( Local_Crush_Settings::strips_metadata() ) {
            $command[] = '-x';
            $command[] = 'strip=exif';
            $command[] = '-x';
            $command[] = 'strip=xmp';
            $command[] = '-x';
            $command[] = 'strip=jumbf';

            // strip 之后再写入只含方向的最小 EXIF；JXL 要 TIFF 本体，不带 Exif\0\0 头。
            if ( '' !== $orientation_exif ) {
                $command[] = '-x';
                $command[] = 'exif=' . $orientation_exif;
            }
        }

        $command[] = $source;
        $command[] = $output;

        return $command;
    }

    /**
     * 构建压缩命令链
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return array[] 命令链
     */
    protected function build_command_chain( string $file_path, array $options ): array {
        $command = self::build_command( $file_path, $this->get_temp_output_path( $file_path ), 'jxl', $this->temp_exif );

        return false === $command ? array() : array( $command );
    }

    /**
     * 同格式压缩时创建方向临时文件
     *
     * @param string $file_path 文件路径
     * @return string[]
     */
    protected function get_extra_temp_paths( string $file_path ): array {
        if ( ! Local_Crush_Settings::strips_metadata() ) {
            return array();
        }

        $orientation = Local_Crush_Orientation::from_file( $file_path );

        if ( $orientation <= 1 ) {
            return array();
        }

        $this->temp_exif = Local_Crush_Orientation::write_exif_temp( $orientation, false );

        return '' === $this->temp_exif ? array() : array( $this->temp_exif );
    }

    /**
     * 获取编码结果的临时文件路径
     *
     * @param string $file_path 文件路径
     * @return string
     */
    protected function get_temp_output_path( string $file_path ): string {
        return $file_path . '.tmp.jxl';
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://github.com/libjxl/libjxl';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'sudo apt-get install libjxl-tools',
            'debian'  => 'sudo apt-get install libjxl-tools',
            'centos'  => __( '暂无官方包，建议从 GitHub Releases 下载', 'local-crush' ),
            'fedora'  => 'sudo dnf install libjxl-tools',
            'macos'   => 'brew install libjxl',
            'windows' => __( '从 libjxl GitHub Releases 下载 jxl-x64-windows.zip，把 bin 目录里的 cjxl.exe 与其依赖的 dll 一起放进 local-crush-bin', 'local-crush' ),
        );
    }
}
