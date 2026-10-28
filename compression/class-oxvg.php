<?php
/**
 * oxvg SVG 压缩渠道
 *
 * @package LocalCrush
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * oxvg SVG 压缩渠道
 *
 * 用 Rust 写的 oxvg 压缩 SVG：单文件可执行程序，不需要 Node.js。
 * 命令行与 svgo 完全不同——oxvg 用子命令，优化力度靠 -e 预设指定。
 */
class Local_Crush_Oxvg extends Local_Crush_Tool_Base {

    /**
     * 获取渠道名称
     *
     * @return string 渠道名称
     */
    public function get_name(): string {
        return 'oxvg';
    }

    /**
     * 获取支持的图片格式
     *
     * @return array 支持的格式列表
     */
    public function get_supported_formats(): array {
        return array( 'svg' );
    }

    /**
     * 获取可执行文件名
     *
     * @return string 可执行文件名
     */
    protected function get_executable_name(): string {
        return 'oxvg';
    }

    /**
     * 构建压缩命令
     *
     * @param string $file_path 文件路径
     * @param array  $options   压缩选项
     * @return array[] 命令链
     */
    protected function build_command_chain( string $file_path, array $options ): array {
        $executable  = $this->get_executable_path();
        $temp_output = $this->get_temp_output_path( $file_path );
        $preset      = Local_Crush_Settings::param_choice( 'svg_preset' );

        // default 是常规优化预设；safe 更保守，几乎不改视觉。
        return array(
            array(
                $executable,
                'optimise',
                '-e',
                $preset,
                '-o',
                $temp_output,
                $file_path,
            ),
        );
    }

    /**
     * 替换原文件前清掉 SVG 里的来源信息
     *
     * oxvg 的两种预设本身就会删掉 <metadata> 与注释，但预设的具体清单会随版本变化，
     * 这里在开启“删除图片元数据”时再按文本清一遍，确保作者、软件、RDF 版权信息
     * 与设计稿路径不会留在对外公开的文件里。只删 <metadata> 和注释，
     * <title>/<desc> 属于可访问性内容，原样保留。
     *
     * @param string $temp_output 临时输出路径
     * @param string $file_path   原文件路径
     * @return bool 是否清理成功
     */
    protected function finalize_temp_output( string $temp_output, string $file_path ): bool {
        if ( '' === $temp_output || ! Local_Crush_Settings::strips_metadata() ) {
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
        $content = file_get_contents( $temp_output );

        if ( false === $content ) {
            return false;
        }

        $cleaned = preg_replace(
            array(
                '#<!--.*?-->#s',
                '#<metadata\b[^>]*>.*?</metadata\s*>#is',
                '#<metadata\b[^>]*/>#is',
            ),
            '',
            $content
        );

        if ( null === $cleaned ) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        return false !== file_put_contents( $temp_output, $cleaned );
    }

    /**
     * 获取编码结果的临时文件路径
     *
     * @param string $file_path 文件路径
     * @return string
     */
    protected function get_temp_output_path( string $file_path ): string {
        return $file_path . '.tmp.svg';
    }

    /**
     * 获取官方下载链接
     *
     * @return string 下载链接
     */
    public function get_download_url(): string {
        return 'https://github.com/noahbald/oxvg';
    }

    /**
     * 获取安装指引
     *
     * @return array 各系统的安装命令
     */
    public function get_install_instructions(): array {
        return array(
            'ubuntu'  => 'cargo install oxvg',
            'debian'  => 'cargo install oxvg',
            'centos'  => 'cargo install oxvg',
            'fedora'  => 'cargo install oxvg',
            'macos'   => 'brew install oxvg',
            'windows' => __( '从发布页下载 oxvg.exe 放进工具目录（x64 请用 v0.0.6，之后的版本只发了 ARM64）', 'local-crush' ),
        );
    }
}
