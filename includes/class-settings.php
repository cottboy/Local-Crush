<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Local_Crush_Settings {

    /**
     * 原图备份永久保留的取值
     */
    const RETENTION_PERMANENT = -1;

    /**
     * 大图缩放阈值：与 WordPress 默认值保持一致
     */
    const IMAGE_SIZE_THRESHOLD_DEFAULT = 2560;

    /**
     * 大图缩放阈值上限，防止误填夸张数值
     */
    const IMAGE_SIZE_THRESHOLD_MAX = 20000;

    private $current_tab = 'general';

    /* ---------------------------------------------------------------------
     * 参数总表
     *
     * 设置页渲染、保存校验、工具拼命令全部只认这一份表：
     * 一处改范围，界面、校验、实际传参同时生效，不会再出现界面写 0-100、
     * 工具只认 0-80 这种两边不一致的情况。
     * ------------------------------------------------------------------- */

    /**
     * 压缩模式参数（各路径共用同一套文案）
     *
     * @return array
     */
    private static function mode_param(): array {
        return array(
            'type'    => 'mode',
            'label'   => __( '压缩模式', 'local-crush' ),
            'flag'    => '',
            'default' => 'lossy',
            'desc'    => __( '有损：丢掉一部分画面细节，换更小的文件，压完能明显看出文件变小；无损：只重新整理压缩数据，画面一个像素都不变，但文件也小得有限。拿不准就选有损。', 'local-crush' ),
        );
    }

    /* ---------------------------------------------------------------------
     * 各工具的参数字典
     *
     * 同一种工具的参数只在这里定义一次，各压缩路径按自己的分组前缀取用，
     * 键名一律是「分组前缀 + 参数名」，因此两条路径永远不会共用同一个设置值。
     * ------------------------------------------------------------------- */

    /**
     * cwebp 参数（WebP 静图同格式压缩、PNG/JPG 转 WebP）
     *
     * @param string $group 分组前缀
     * @return array
     */
    private static function cwebp_params( string $group ): array {
        return array(
            $group . '_mode'           => self::mode_param(),
            $group . '_quality'        => array(
                'type'    => 'int',
                'label'   => __( '有损质量', 'local-crush' ),
                'flag'    => 'cwebp -q',
                'min'     => 0,
                'max'     => 100,
                'default' => 75,
                'desc'    => __( '数越大画质越好、文件越大；数越小画质越差、文件越小。0 是最糊的档，100 是最好的档。默认 75 是 cwebp 自己的默认值，日常够用；想更清楚可以调到 80 左右（cwebp 帮助里也把 80 称为典型值），再高基本只是文件变大。只有选“有损压缩”时才看这个数。', 'local-crush' ),
            ),
            $group . '_method'         => array(
                'type'    => 'int',
                'label'   => __( '有损压缩方法', 'local-crush' ),
                'flag'    => 'cwebp -m',
                'min'     => 0,
                'max'     => 6,
                'default' => 4,
                'desc'    => __( '数越大压缩越慢、文件越小；数越小压缩越快、文件越大。这个数只影响速度和体积，不影响画质。默认 4 是 cwebp 自己的默认值。只有选“有损压缩”时才看这个数。', 'local-crush' ),
            ),
            $group . '_lossless_level' => array(
                'type'    => 'int',
                'label'   => __( '无损级别', 'local-crush' ),
                'flag'    => 'cwebp -z',
                'min'     => 0,
                'max'     => 9,
                'default' => 6,
                'desc'    => __( '数越大压缩越慢、文件越小；数越小压缩越快、文件越大。只影响速度和体积，不影响画质。默认 6 是 cwebp 自己的默认值。只有选“无损压缩”时才看这个数。', 'local-crush' ),
            ),
            $group . '_alpha_quality'  => array(
                'type'    => 'int',
                'label'   => __( '透明通道质量', 'local-crush' ),
                'flag'    => 'cwebp -alpha_q',
                'min'     => 0,
                'max'     => 100,
                'default' => 100,
                'desc'    => __( '只管图片里半透明部分的清晰程度：数越大透明边缘越保真、文件越大；数越小透明边缘越毛、文件越小。默认 100 表示透明部分完全无损，也是 cwebp 自己的默认值；没有半透明边缘的图调它没有意义。', 'local-crush' ),
            ),
            $group . '_preset'         => array(
                'type'    => 'choice',
                'label'   => __( '内容预设', 'local-crush' ),
                'flag'    => 'cwebp -preset',
                'choices' => array(
                    'default' => __( '默认', 'local-crush' ),
                    'photo'   => __( '照片', 'local-crush' ),
                    'picture' => __( '室内人像', 'local-crush' ),
                    'drawing' => __( '绘图 / 线稿', 'local-crush' ),
                    'icon'    => __( '图标 / 小彩图', 'local-crush' ),
                    'text'    => __( '文字截图', 'local-crush' ),
                ),
                'default' => 'default',
                'desc'    => __( '按图片内容替你预调好一套内部参数，省得自己一个个试：“照片”适合风景人像，“室内人像”适合室内合影，“绘图 / 线稿”适合卡通和截图，“图标 / 小彩图”适合 logo 和小图，“文字截图”适合界面截图。预设会重置质量和压缩方法，所以排在命令行最前面，你下面显式填的值仍然生效。拿不准就选默认。', 'local-crush' ),
            ),
            $group . '_exact'          => array(
                'type'    => 'bool',
                'label'   => __( '保留透明区域颜色', 'local-crush' ),
                'flag'    => 'cwebp -exact',
                'default' => false,
                'desc'    => __( '默认会把全透明像素的底色抹掉，文件更小；勾选后原样保留这些像素的底色，文件大约大 16%。只有还要保留透明区域颜色信息（比如图还要再拿去加工）才需要勾。', 'local-crush' ),
            ),
        );
    }

    /**
     * avifenc 参数（AVIF 静图同格式压缩、PNG/JPG 转 AVIF、静态 GIF 转 AVIF）
     *
     * @param string $group 分组前缀
     * @return array
     */
    private static function avifenc_params( string $group ): array {
        return array(
            $group . '_mode'          => self::mode_param(),
            $group . '_quality'       => array(
                'type'    => 'int',
                'label'   => __( '有损质量', 'local-crush' ),
                'flag'    => 'avifenc -q',
                'min'     => 0,
                'max'     => 100,
                'default' => 60,
                'desc'    => __( '数越大画质越好、文件越大；数越小画质越差、文件越小。0 是最糊的档，100 是无损。默认 60 是 avifenc 自己的默认值（官方称典型区间是 60-80），AVIF 本身压缩效率高，60 已经很清楚；嫌画质不够可以往 80 调。只有选“有损压缩”时才看这个数。', 'local-crush' ),
            ),
            $group . '_alpha_quality' => array(
                'type'    => 'int',
                'label'   => __( '透明通道质量', 'local-crush' ),
                'flag'    => 'avifenc --qalpha',
                'min'     => 0,
                'max'     => 100,
                'default' => 60,
                'desc'    => __( '只管图片里半透明部分的清晰程度：数越大透明边缘越保真、文件越大；数越小透明边缘越毛、文件越小。默认 60 与上面的颜色质量保持一致，这也是 avifenc 不单独指定时的行为；半透明边缘特别多的图建议调高。', 'local-crush' ),
            ),
            $group . '_speed'         => array(
                'type'    => 'int',
                'label'   => __( '编码速度', 'local-crush' ),
                'flag'    => 'avifenc -s',
                'min'     => 0,
                'max'     => 10,
                'default' => 6,
                'desc'    => __( '数越大编码越快、文件越大；数越小编码越慢、文件越小。默认 6 是 avifenc 自己的默认值。有损和无损都看这个数。', 'local-crush' ),
            ),
            $group . '_chroma'        => array(
                'type'    => 'choice',
                'label'   => __( '色彩抽样', 'local-crush' ),
                'flag'    => 'avifenc -y',
                'choices' => array(
                    'auto' => __( '自动（按输入决定）', 'local-crush' ),
                    '444'  => __( '4:4:4（色彩最准）', 'local-crush' ),
                    '422'  => __( '4:2:2（横向减半）', 'local-crush' ),
                    '420'  => __( '4:2:0 + sharpyuv（最小，边缘略糊）', 'local-crush' ),
                ),
                'default' => 'auto',
                'desc'    => __( '决定颜色信息记得有多细：4:4:4 色彩最准、文件最大；4:2:2 横向减半；4:2:0 最小，但彩色边缘会略糊。自动表示按原图情况让 avifenc 自己决定，日常推荐。只有选“有损压缩”时才看这个选项。', 'local-crush' ),
            ),
            $group . '_depth'         => array(
                'type'    => 'choice',
                'label'   => __( '位深', 'local-crush' ),
                'flag'    => 'avifenc -d',
                'choices' => array(
                    '8'  => __( '8 位（默认，浏览器都能显示）', 'local-crush' ),
                    '10' => __( '10 位（渐变更平滑，体积略大）', 'local-crush' ),
                    '12' => __( '12 位（几乎用不到）', 'local-crush' ),
                ),
                'default' => '8',
                'desc'    => __( '位深越高，渐变过渡越平滑，但文件更大、部分老设备可能不支持。8 位是所有浏览器都能显示的标准档；只有对渐变质量极其敏感时才值得选 10 / 12 位。', 'local-crush' ),
            ),
            $group . '_jobs'          => array(
                'type'    => 'int',
                'label'   => __( '并行线程数', 'local-crush' ),
                'flag'    => 'avifenc -j',
                'min'     => 1,
                'max'     => 256,
                'default' => 4,
                'desc'    => __( '同时用几个 CPU 核心来编码：数越大编码越快，但会和网站前台抢资源，服务器上别贪多。avifenc 自己默认用满所有核心，插件默认 4 是稳妥值。', 'local-crush' ),
            ),
        );
    }

    /**
     * FFmpeg 的 libaom-av1 参数（动画 AVIF 的同格式压缩与全部动画转 AVIF）
     *
     * @param string $group 分组前缀
     * @return array
     */
    private static function ffmpeg_avif_params( string $group ): array {
        return array(
            $group . '_mode'    => self::mode_param(),
            $group . '_crf'     => array(
                'type'    => 'int',
                'label'   => __( 'CRF（质量）', 'local-crush' ),
                'flag'    => 'FFmpeg -crf',
                'min'     => 0,
                'max'     => 63,
                'default' => 20,
                'desc'    => __( '注意这个数和别处相反：数越小画质越好、文件越大，数越大画质越差、文件越小。0 接近无损，63 最糊。默认 20 是插件推荐的高画质档；觉得文件太大可以往 25-30 调。只有选“有损压缩”时才看这个数。', 'local-crush' ),
            ),
            $group . '_speed'   => array(
                'type'    => 'int',
                'label'   => __( '编码速度', 'local-crush' ),
                'flag'    => 'FFmpeg -cpu-used',
                'min'     => 0,
                'max'     => 8,
                'default' => 6,
                'desc'    => __( '数越大编码越快、文件越大；数越小编码越慢、文件越小。FFmpeg 自己的默认值是 1，服务器上跑太慢；插件默认 6，与 avifenc 的默认速度一致，是速度与画质的折中。', 'local-crush' ),
            ),
            $group . '_threads' => array(
                'type'    => 'int',
                'label'   => __( '编码线程数', 'local-crush' ),
                'flag'    => 'FFmpeg -threads',
                'min'     => 1,
                'max'     => 256,
                'default' => 4,
                'desc'    => __( '同时用几个 CPU 核心来编码：数越大编码越快，但会和网站前台抢资源。FFmpeg 自己默认用满所有核心，插件默认 4 是稳妥值。', 'local-crush' ),
            ),
            $group . '_chroma'  => array(
                'type'    => 'choice',
                'label'   => __( '色彩抽样', 'local-crush' ),
                'flag'    => 'FFmpeg -pix_fmt',
                'choices' => array(
                    'auto' => __( '自动（编码器自己挑）', 'local-crush' ),
                    '444'  => __( '4:4:4（yuv444p，色彩最准）', 'local-crush' ),
                    '422'  => __( '4:2:2（yuv422p，横向减半）', 'local-crush' ),
                    '420'  => __( '4:2:0（yuv420p，最小，边缘略糊）', 'local-crush' ),
                ),
                'default' => 'auto',
                'desc'    => __( '决定颜色信息记得有多细：4:4:4 色彩最准、文件最大；4:2:2 横向减半；4:2:0 最小，但彩色边缘会略糊。自动表示交给 FFmpeg 按原图决定，日常推荐。只有选“有损压缩”时才看这个选项。', 'local-crush' ),
            ),
        );
    }

    /**
     * FFmpeg 的 libwebp_anim 参数（动画 WebP 同格式压缩、APNG 转 WebP）
     *
     * @param string $group 分组前缀
     * @return array
     */
    private static function ffmpeg_webp_params( string $group ): array {
        return array(
            $group . '_mode'    => self::mode_param(),
            $group . '_quality' => array(
                'type'    => 'int',
                'label'   => __( '有损质量', 'local-crush' ),
                'flag'    => 'FFmpeg -quality',
                'min'     => 0,
                'max'     => 100,
                'default' => 75,
                'desc'    => __( '数越大画质越好、文件越大；数越小画质越差、文件越小。0 是最糊的档，100 是最好的档。默认 75 是 FFmpeg 自己的默认值；想更清楚可以调到 80 左右。只有选“有损压缩”时才看这个数。', 'local-crush' ),
            ),
            $group . '_method'  => array(
                'type'    => 'int',
                'label'   => __( '压缩方法', 'local-crush' ),
                'flag'    => 'FFmpeg -compression_level',
                'min'     => 0,
                'max'     => 6,
                'default' => 4,
                'desc'    => __( '数越大压缩越慢、文件越小；数越小压缩越快、文件越大。只影响速度和体积，不影响画质。默认 4 与 libwebp 的默认方法一致，有损和无损都生效。', 'local-crush' ),
            ),
        );
    }

    /**
     * cjxl 参数（JPEG XL 同格式压缩与全部转 JPEG XL 的路径）
     *
     * @param string $group 分组前缀
     * @return array
     */
    private static function cjxl_params( string $group ): array {
        return array(
            $group . '_mode'    => self::mode_param(),
            $group . '_quality' => array(
                'type'    => 'int',
                'label'   => __( '有损质量', 'local-crush' ),
                'flag'    => 'cjxl -q',
                'min'     => 0,
                'max'     => 100,
                'default' => 80,
                'desc'    => __( '数越大画质越好、文件越大；数越小画质越差、文件越小。100 是数学上的无损，90 起肉眼基本看不出差别。默认 80 是插件推荐值，落在官方建议的 68-96 区间内。只有选“有损压缩”时才看这个数。', 'local-crush' ),
            ),
            $group . '_effort'  => array(
                'type'    => 'int',
                'label'   => __( '压缩级别', 'local-crush' ),
                'flag'    => 'cjxl -e',
                'min'     => 1,
                'max'     => 10,
                'default' => 7,
                'desc'    => __( '数越大压缩越慢、同样画质下文件越小；数越小压缩越快、文件越大。只影响速度和体积，不影响画质。默认 7 是 cjxl 自己的默认值。', 'local-crush' ),
            ),
        );
    }

    /**
     * gif2webp 参数（静态 GIF 转 WebP、动画 GIF 转 WebP）
     *
     * gif2webp 默认就是无损重排，-lossy 是一个独立开关而不是“模式”。
     *
     * @param string $group 分组前缀
     * @return array
     */
    private static function gif2webp_params( string $group ): array {
        return array(
            $group . '_lossy'   => array(
                'type'    => 'bool',
                'label'   => __( '启用有损', 'local-crush' ),
                'flag'    => 'gif2webp -lossy',
                'default' => true,
                'desc'    => __( '勾选：按下面的有损质量重新量化，文件明显更小；取消：只重排数据，画面一个像素都不变，但体积也降得有限。', 'local-crush' ),
            ),
            $group . '_quality' => array(
                'type'    => 'int',
                'label'   => __( '有损质量', 'local-crush' ),
                'flag'    => 'gif2webp -q',
                'min'     => 0,
                'max'     => 100,
                'default' => 80,
                'desc'    => __( '数越大画质越好、文件越大；数越小画质越差、文件越小。默认 80 是插件推荐值。只有勾上“启用有损”时才看这个数。', 'local-crush' ),
            ),
            $group . '_method'  => array(
                'type'    => 'int',
                'label'   => __( '压缩方法', 'local-crush' ),
                'flag'    => 'gif2webp -m',
                'min'     => 0,
                'max'     => 6,
                'default' => 4,
                'desc'    => __( '数越大压缩越慢、文件越小；数越小压缩越快、文件越大。只影响速度和体积，不影响画质。默认 4 是 gif2webp 自己的默认值。', 'local-crush' ),
            ),
        );
    }

    /**
     * oxipng 参数（PNG 无损压缩与 APNG 动图压缩共用）
     *
     * @param string $prefix 键名前缀
     * @return array
     */
    private static function oxipng_params( string $prefix ): array {
        return array(
            $prefix . '_oxipng_level'  => array(
                'type'    => 'int',
                'label'   => __( 'oxipng 无损级别', 'local-crush' ),
                'flag'    => 'oxipng -o',
                'min'     => 0,
                'max'     => 6,
                'default' => 2,
                'desc'    => __( '数越大压得越狠、文件越小，但耗时越长（6 非常慢）；数越小越快、文件越大。只影响速度和体积，不影响画质。默认 2 是 oxipng 自己的默认值，日常够用。', 'local-crush' ),
            ),
            $prefix . '_oxipng_alpha'  => array(
                'type'    => 'bool',
                'label'   => __( '优化透明通道', 'local-crush' ),
                'flag'    => 'oxipng --alpha',
                'default' => false,
                'desc'    => __( '把完全透明的像素改写成更容易压缩的值，带透明区域的大图能再小一截，看起来没有任何变化。纯不透明的图勾了也没有效果。', 'local-crush' ),
            ),
            $prefix . '_oxipng_zopfli' => array(
                'type'    => 'bool',
                'label'   => __( 'Zopfli 极限压缩', 'local-crush' ),
                'flag'    => 'oxipng --zopfli',
                'default' => false,
                'desc'    => __( '换一种更狠的无损压缩算法，通常还能再小几个百分点，但耗时明显更长；大图或帧特别多的动图容易跑到超时，所以默认关闭。', 'local-crush' ),
            ),
        );
    }

    /**
     * 全部压缩路径的参数定义
     *
     * 只有整条工具链完全一致的路径才合并成一组，共 15 组（10 组同格式压缩 + 5 组格式转换）。
     * 同一个编码器但前后多了解码或播放参数回写的路径各算一条，参数各自独立。
     * 参数键名一律带分组前缀，不同分组之间不会共用同一个设置值。
     *
     * 每个参数都写清楚：界面上叫什么、实际传给工具的哪个参数、取值范围、默认值。
     * type 取值：
     * - int    整数，必须有 min / max
     * - choice 枚举，必须有 choices
     * - bool   开关
     * - mode   有损 / 无损二选一
     *
     * 每条路径依赖哪些工具由设置页的“路径支持状态”表单独维护，参数表不掺这件事。
     *
     * @return array
     */
    public static function param_groups(): array {
        static $groups = null;

        if ( null !== $groups ) {
            return $groups;
        }

        // 只有整条工具链完全一致的路径才合并成一组，共 15 组：
        // 同一个工具但前后还多了解码（avifdec、GD）或播放参数回写（webpmux、ffprobe）
        // 的路径各算一条，参数各自独立。前 10 组是同格式压缩，后 5 组是格式转换。
        $groups = array(

            'jpeg'                => array(
                'title'  => __( 'JPEG 压缩（jpegoptim）', 'local-crush' ),
                'note'   => __( '所有 JPEG 都走这里。有损模式传 --max 设定质量上限，无损模式不传质量参数，只重排压缩数据。', 'local-crush' ),
                'params' => array(
                    'jpeg_mode'        => self::mode_param(),
                    'jpeg_quality'     => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量', 'local-crush' ),
                        'flag'    => 'jpegoptim --max',
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '数越大画质越好、文件越大；数越小画质越差、文件越小。jpegoptim 不设上限时只做无损整理，这个数是插件推荐的质量上限，80 是照片类图片的常用平衡点。只有选“有损压缩”时才看这个数。', 'local-crush' ),
                    ),
                    'jpeg_progressive' => array(
                        'type'    => 'choice',
                        'label'   => __( '渐进式编码', 'local-crush' ),
                        'flag'    => 'jpegoptim --all-progressive / --all-normal / --auto-mode',
                        'choices' => array(
                            'auto'        => __( '自动（--auto-mode，让 jpegoptim 挑更小的）', 'local-crush' ),
                            'normal'      => __( '顺序式（--all-normal，整张出现）', 'local-crush' ),
                            'progressive' => __( '渐进式（--all-progressive，先糊后清）', 'local-crush' ),
                        ),
                        'default' => 'progressive',
                        'desc'    => __( '渐进式：图片由糊到清逐段加载，观感更好、文件通常也更小，推荐；顺序式：整张图一次性显示，只有很老的软件不认渐进式时才需要选；自动：让 jpegoptim 自己挑更小的那种。', 'local-crush' ),
                    ),
                ),
            ),
            'png_lossy'           => array(
                'title'  => __( 'PNG 有损压缩（pngquant）', 'local-crush' ),
                'note'   => __( '这一节是 pngquant 的全部参数。本节最上面的“PNG 走哪条路径”选无损时，本节参数不生效，改由下一节 oxipng 负责。APNG 动图永远不会走 pngquant。', 'local-crush' ),
                'params' => array(
                    'png_mode'             => array(
                        'type'    => 'mode',
                        'label'   => __( 'PNG 走哪条路径', 'local-crush' ),
                        'flag'    => '',
                        'default' => 'lossy',
                        'desc'    => __( '有损：走本节 pngquant 的参数，文件小得多但颜色会精简；无损：走下一节 oxipng 的参数，画面一个像素都不变。两条路径只能二选一，不会同时执行。', 'local-crush' ),
                    ),
                    'png_lossy_quality_min' => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量下限', 'local-crush' ),
                        'flag'    => __( 'pngquant --quality 下限', 'local-crush' ),
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 60,
                        'desc'    => __( '压完的质量低于这个数就不保存、直接保留原图，防止压得太糊。数越小，越多图会被压小（哪怕画质变差）；数越大越保守。0 表示不设下限。', 'local-crush' ),
                    ),
                    'png_lossy_quality_max' => array(
                        'type'    => 'int',
                        'label'   => __( '有损质量上限', 'local-crush' ),
                        'flag'    => __( 'pngquant --quality 上限', 'local-crush' ),
                        'min'     => 0,
                        'max'     => 100,
                        'default' => 80,
                        'desc'    => __( '追求的最高质量：数越大画质越好、文件越大；数越小文件越小、画质越差。凑不到这个质量时会自动减少颜色数。默认 60-80 是常用推荐区间；下限填得比上限大时会自动对调。', 'local-crush' ),
                    ),
                    'png_lossy_speed'      => array(
                        'type'    => 'int',
                        'label'   => __( 'pngquant 速度', 'local-crush' ),
                        'flag'    => 'pngquant --speed',
                        'min'     => 1,
                        'max'     => 11,
                        'default' => 4,
                        'desc'    => __( '数越大压缩越快、越省 CPU，但画质越粗糙；数越小越慢越细。默认 4 是 pngquant 自己的默认值。', 'local-crush' ),
                    ),
                    'png_lossy_dither'     => array(
                        'type'    => 'bool',
                        'label'   => __( '抖动', 'local-crush' ),
                        'flag'    => __( 'pngquant（取消勾选时加 --nofs）', 'local-crush' ),
                        'default' => true,
                        'desc'    => __( '勾选：减少颜色后用抖动把过渡补自然，照片类渐变更耐看；取消：色块更干净、文件更小，适合扁平图标和纯色图。pngquant 默认就是开启抖动的。', 'local-crush' ),
                    ),
                ),
            ),
            'png_lossless'        => array(
                'title'  => __( 'PNG / APNG 无损压缩（oxipng）', 'local-crush' ),
                'note'   => __( '静态 PNG 走无损模式时用这一节，APNG 动图也整只交给 oxipng 用这一节——两者的工具链完全一样，所以共用一组参数。oxipng 只重排压缩数据，画面一个像素都不变，也能保住 APNG 的帧结构与每帧时长。', 'local-crush' ),
                'params' => array_merge(
                    array(
                        'apng_enabled' => array(
                            'type'    => 'bool',
                            'label'   => __( 'APNG 自动无损压缩', 'local-crush' ),
                            'flag'    => __( 'APNG 走 oxipng', 'local-crush' ),
                            'default' => true,
                            'desc'    => __( 'PNG 走有损模式时，勾上这一项会让 APNG 绕开 pngquant、单独用 oxipng 整只无损压缩；不勾则 PNG 有损时不压 APNG。PNG 本身选的就是无损时，APNG 总是走无损压缩。它不控制 APNG 转格式，那个在基本设置里单独勾。', 'local-crush' ),
                        ),
                    ),
                    self::oxipng_params( 'png_lossless' )
                ),
            ),
            'gif'                 => array(
                'title'  => __( 'GIF 压缩（gifsicle）', 'local-crush' ),
                'note'   => __( '整只 GIF（含动画）交给 gifsicle 原地重压，插件不拆帧、不动透明像素。', 'local-crush' ),
                'params' => array(
                    'gif_mode'       => self::mode_param(),
                    'gif_lossy' => array(
                        'type'    => 'int',
                        'label'   => __( '有损强度', 'local-crush' ),
                        'flag'    => 'gifsicle --lossy',
                        'min'     => 0,
                        'max'     => 1000,
                        'default' => 20,
                        'desc'    => __( '数越大文件越小、噪点和色块越多；数越小越保真、文件越大。0 等于不加有损。默认 20 是 gifsicle 自己的默认值；录屏和照片类动图常用 30-200，纯色线稿建议保持小值或改选“无损压缩”。只有选“有损压缩”时才看这个数。', 'local-crush' ),
                    ),
                    'gif_colors'     => array(
                        'type'    => 'int',
                        'label'   => __( '调色板颜色数', 'local-crush' ),
                        'flag'    => 'gifsicle --colors',
                        'min'     => 2,
                        'max'     => 256,
                        'default' => 256,
                        'desc'    => __( '数越大保留的颜色越多、画质越好、文件越大；数越小文件越小、掉色越明显。GIF 最多 256 色，256 就是不缩颜色。图标类动图用 64-128 往往够用。', 'local-crush' ),
                    ),
                    'gif_level' => array(
                        'type'    => 'int',
                        'label'   => __( '优化级别', 'local-crush' ),
                        'flag'    => 'gifsicle -O',
                        'min'     => 1,
                        'max'     => 3,
                        'default' => 2,
                        'desc'    => __( '1 只存帧间变化的部分；2 额外利用透明区域，体积与速度兼顾，日常推荐；3 会多试几种方法挑最小的，更慢但通常再小一点。注意 gifsicle 不加 -O 时完全不做优化，插件默认传 2。', 'local-crush' ),
                    ),
                ),
            ),
            'svg'                 => array(
                'title'  => __( 'SVG 压缩（oxvg）', 'local-crush' ),
                'note'   => __( '由 oxvg 处理，两种预设都会删掉 <metadata> 和注释；开启“删除图片元数据”时插件还会再清一遍 <metadata> 与注释，确保不留作者、软件等来源信息。', 'local-crush' ),
                'params' => array(
                    'svg_preset' => array(
                        'type'    => 'choice',
                        'label'   => __( '优化预设', 'local-crush' ),
                        'flag'    => 'oxvg optimise -e',
                        'choices' => array(
                            'default' => __( '常规（default）', 'local-crush' ),
                            'safe'    => __( '保守（safe，几乎不改视觉）', 'local-crush' ),
                        ),
                        'default' => 'default',
                        'desc'    => __( '常规：合并路径、精简属性，瘦身最明显；保守：只做绝对安全的重写，几乎不影响视觉，适合结构复杂或带脚本的 SVG。两种预设都会删掉制作软件留下的说明和元数据。', 'local-crush' ),
                    ),
                ),
            ),
            'webp'                => array(
                'title'  => __( 'WebP 编码（cwebp）', 'local-crush' ),
                'note'   => __( 'WebP 静图同格式压缩与 PNG / JPG 转 WebP 都是 cwebp 一条命令，工具链完全一样，所以共用这一组参数。动画 WebP 不在这一条路径上。', 'local-crush' ),
                'params' => self::cwebp_params( 'webp' ),
            ),
            'webp_anim'           => array(
                'title'  => __( '动画 WebP 编码（FFmpeg + webpmux）', 'local-crush' ),
                'note'   => __( '动画 WebP 同格式压缩与 APNG 转 WebP 都走 FFmpeg 的 libwebp_anim，再用 webpmux 回写播放参数，工具链完全一样。参数直接对应 FFmpeg 的 -quality 与 -compression_level，不做任何换算。', 'local-crush' ),
                'params' => self::ffmpeg_webp_params( 'webp_anim' ),
            ),
            'avif'                => array(
                'title'  => __( 'AVIF 同格式压缩（avifdec + avifenc）', 'local-crush' ),
                'note'   => __( 'AVIF 静图要先由 avifdec 解成 PNG 再交给 avifenc 重压，比其它 AVIF 路径多一个解码步骤，所以单独一组。', 'local-crush' ),
                'params' => self::avifenc_params( 'avif' ),
            ),
            'avif_anim'           => array(
                'title'  => __( '动画 AVIF 同格式压缩（avifdec + ffmpeg + ffprobe）', 'local-crush' ),
                'note'   => __( '动画 AVIF 先由 ffprobe 挑出完整动画轨道、avifdec 读原图信息，再交给 FFmpeg 的 libaom-av1 重压，比转 AVIF 多两步，所以单独一组。', 'local-crush' ),
                'params' => self::ffmpeg_avif_params( 'avif_anim' ),
            ),
            'jxl'                 => array(
                'title'  => __( 'JPEG XL 编码（cjxl）', 'local-crush' ),
                'note'   => __( 'JXL 同格式压缩（静图与动图）与 PNG / JPG / GIF / APNG 转 JXL 全部只调用 cjxl，工具链完全一样，所以共用这一组参数。cjxl 能直接读写动画 JXL，一条命令重编码，不需要拆帧。', 'local-crush' ),
                'params' => self::cjxl_params( 'jxl' ),
            ),
            'gif_to_webp'         => array(
                'title'  => __( '静态 GIF 转 WebP（gif2webp）', 'local-crush' ),
                'note'   => __( '只有 gif2webp 一条命令，不需要回写播放参数。参数与动画 GIF 转 WebP 分开设置。', 'local-crush' ),
                'params' => self::gif2webp_params( 'gif_to_webp' ),
            ),
            'gif_to_webp_anim'    => array(
                'title'  => __( '动画 GIF 转 WebP（gif2webp + webpmux）', 'local-crush' ),
                'note'   => __( '整只动画 GIF 交给 gif2webp，多帧出来仍是动画；写完后还要用 webpmux 把末帧时长与背景色改回原值，比静态 GIF 多一个工具，所以单独一组。', 'local-crush' ),
                'params' => self::gif2webp_params( 'gif_to_webp_anim' ),
            ),
            'avif_convert'        => array(
                'title'  => __( 'PNG / JPG 转 AVIF（avifenc）', 'local-crush' ),
                'note'   => __( 'avifenc 直读 PNG / JPG，不需要额外解码工具，所以只调用 avifenc。参数与 AVIF 同格式压缩、静态 GIF 转 AVIF 分开设置。', 'local-crush' ),
                'params' => self::avifenc_params( 'avif_convert' ),
            ),
            'avif_convert_gif'    => array(
                'title'  => __( '静态 GIF 转 AVIF（GD + avifenc）', 'local-crush' ),
                'note'   => __( 'avifenc 不认 GIF，必须先用 GD 扩展解成 PNG 再交给 avifenc，多一个 GD 步骤，所以单独一组。', 'local-crush' ),
                'params' => self::avifenc_params( 'avif_convert_gif' ),
            ),
            'avif_convert_anim'   => array(
                'title'  => __( '动画 GIF / APNG 转 AVIF（ffmpeg）', 'local-crush' ),
                'note'   => __( '整只动画交给 FFmpeg 的 libaom-av1，一条命令读写全部帧，不需要 ffprobe 或 avifdec，所以单独一组。-crf / -cpu-used / -pix_fmt 都是 FFmpeg 的原始参数。', 'local-crush' ),
                'params' => self::ffmpeg_avif_params( 'avif_convert_anim' ),
            ),
        );

        return $groups;
    }

    /**
     * 把分组表拍平成 键名 => 参数定义
     *
     * @return array<string,array>
     */
    public static function param_map(): array {
        static $map = null;

        if ( null !== $map ) {
            return $map;
        }

        $map = array();

        foreach ( self::param_groups() as $group_id => $group ) {
            foreach ( $group['params'] as $key => $param ) {
                $param['group'] = $group_id;
                $map[ $key ]    = $param;
            }
        }

        return $map;
    }

    /**
     * 全部参数的默认值
     *
     * @return array
     */
    public static function defaults(): array {
        $defaults = array();

        foreach ( self::param_map() as $key => $param ) {
            $defaults[ $key ] = $param['default'];
        }

        return $defaults;
    }

    /**
     * 按定义把单个值夹到合法范围
     *
     * 非法输入（数组、对象、非数字、空值）一律回落到默认值，绝不把可疑数据带进命令行。
     *
     * @param array $param 参数定义
     * @param mixed $value 原始值
     * @return mixed
     */
    public static function normalize_param( array $param, $value ) {
        switch ( $param['type'] ) {
            case 'int':
                if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ! preg_match( '/^-?\d+$/D', (string) $value ) ) {
                    return (int) $param['default'];
                }

                return (int) max( (int) $param['min'], min( (int) $param['max'], (int) $value ) );

            case 'choice':
                // 选项键名是 444、10 这类数字时，PHP 会把它们存成整数键，
                // 这里统一按字符串比对，免得整数形式的合法取值被当成非法值打回默认。
                $value = is_int( $value ) ? (string) $value : $value;

                return ( is_string( $value ) && array_key_exists( $value, $param['choices'] ) ) ? $value : $param['default'];

            case 'bool':
                // 数组、对象等非标量输入一律回落到默认值，与函数注释的约定保持一致
                return is_scalar( $value ) ? ! empty( $value ) : (bool) $param['default'];

            case 'mode':
                return ( 'lossless' === $value ) ? 'lossless' : 'lossy';
        }

        return $param['default'];
    }

    /**
     * 整表归一化
     *
     * @param array $input 表单或选项原始值
     * @return array
     */
    public static function normalize_all( array $input ): array {
        $normalized = array();

        foreach ( self::param_map() as $key => $param ) {
            $normalized[ $key ] = self::normalize_param( $param, $input[ $key ] ?? null );
        }

        // pngquant 的质量区间必须下限不大于上限，填反了直接对调，别让它悄悄压成最差质量。
        if ( $normalized['png_lossy_quality_min'] > $normalized['png_lossy_quality_max'] ) {
            $swap                          = $normalized['png_lossy_quality_min'];
            $normalized['png_lossy_quality_min'] = $normalized['png_lossy_quality_max'];
            $normalized['png_lossy_quality_max'] = $swap;
        }

        return $normalized;
    }

    /**
     * 读取单个已归一化的参数
     *
     * @param string $key 参数键名
     * @return mixed
     */
    public static function param( string $key ) {
        $map = self::param_map();

        if ( ! isset( $map[ $key ] ) ) {
            return null;
        }

        $settings = get_option( 'local_crush_tools', array() );
        $settings = is_array( $settings ) ? $settings : array();

        return self::normalize_param( $map[ $key ], $settings[ $key ] ?? null );
    }

    /**
     * 读取整数参数
     *
     * @param string $key 参数键名
     * @return int
     */
    public static function param_int( string $key ): int {
        return (int) self::param( $key );
    }

    /**
     * 读取枚举参数
     *
     * @param string $key 参数键名
     * @return string
     */
    public static function param_choice( string $key ): string {
        $value = self::param( $key );

        return is_string( $value ) ? $value : '';
    }

    /**
     * 读取开关参数
     *
     * @param string $key 参数键名
     * @return bool
     */
    public static function param_flag( string $key ): bool {
        return (bool) self::param( $key );
    }

    /**
     * 读取某条压缩路径的压缩模式
     *
     * @param string $group 分组键名，例如 jpeg、webp、gif_to_webp
     * @return string lossy|lossless
     */
    public static function param_mode( string $group ): string {
        $value = self::param( $group . '_mode' );

        return 'lossless' === $value ? 'lossless' : 'lossy';
    }

    /**
     * PNG 是否走无损路径
     *
     * 这个开关决定 PNG 交给 pngquant 还是 oxipng：两条路径各有独立的一组参数，
     * 开关本身存在 png_lossy 分组里，键名是 png_mode。
     *
     * @return bool
     */
    public static function png_is_lossless(): bool {
        return 'lossless' === self::param( 'png_mode' );
    }

    /* ---------------------------------------------------------------------
     * 基本设置
     * ------------------------------------------------------------------- */

    /**
     * 归一化原图备份保留时长
     *
     * -1 为永久保留；0 不是合法值，按步进方向落到 1；其他负数和非数字一律按永久处理；
     * 正整数不设上限。
     *
     * @param mixed $value 待归一化的原始值
     * @return int
     */
    public static function normalize_retention_days( $value ): int {
        if ( ! is_numeric( $value ) ) {
            return self::RETENTION_PERMANENT;
        }

        $days = (int) $value;

        if ( 0 === $days ) {
            return 1;
        }

        return $days < 0 ? self::RETENTION_PERMANENT : $days;
    }

    /**
     * 归一化大图缩放阈值
     *
     * 对应 WordPress 的 big_image_size_threshold：上传图片的宽或高超过此值时会生成
     * -scaled 缩放图并接管原文件位置。阈值不再承担“关闭”语义，关闭自动缩放由独立的
     * 勾选框负责，因此非数字或小于 1 的值一律回落到默认阈值。
     *
     * @param mixed $value 待归一化的原始值
     * @return int
     */
    public static function normalize_image_size_threshold( $value ): int {
        // 阈值不再用 0 表示关闭：关闭自动缩放由独立的勾选框负责，
        // 因此非数字或小于 1 的值一律回落到默认阈值。
        if ( ! is_numeric( $value ) || (int) $value < 1 ) {
            return self::IMAGE_SIZE_THRESHOLD_DEFAULT;
        }

        return (int) max( 1, min( self::IMAGE_SIZE_THRESHOLD_MAX, (int) $value ) );
    }

    /**
     * 是否开启自动缩放主图
     *
     * 对应缩略图尺寸区域里带勾选框的缩放小项：勾选才按阈值生成 -scaled 缩放图，
     * 不勾选就是关闭自动缩放。选项缺失时按开启处理，与 WordPress 默认缩放一致。
     *
     * @return bool
     */
    public static function image_scale_enabled(): bool {
        $general = get_option( 'local_crush_general', array() );

        return ! isset( $general['image_scale_enabled'] ) || ! empty( $general['image_scale_enabled'] );
    }

    /**
     * 压缩后是否删除未缩放的原始大图
     *
     * 原图超过阈值时 WordPress 会另存一张 -scaled 缩放图并让它在媒体库里充当全尺寸主图，
     * 未缩放的那份原始大图只留在上传目录里，没有任何界面入口能用到它。勾选后，压缩
     * （手动或自动）结束时会把它删掉，只保留 -scaled 图，省下一份原始大图的磁盘占用。
     *
     * 关闭自动缩放时这个开关没有意义（不会生成 -scaled 图），调用方需自行判断。
     * 选项缺失时按开启处理：默认帮用户省磁盘。
     *
     * @return bool
     */
    public static function delete_unscaled_original(): bool {
        $general = get_option( 'local_crush_general', array() );

        return ! isset( $general['delete_unscaled_original'] ) || ! empty( $general['delete_unscaled_original'] );
    }

    /**
     * 大图缩放阈值
     *
     * @return int 像素值，仅在开启自动缩放时生效
     */
    public static function image_size_threshold(): int {
        $general = get_option( 'local_crush_general', array() );

        return self::normalize_image_size_threshold( $general['image_size_threshold'] ?? null );
    }

    /**
     * 压缩与格式转换时是否删除图片元数据
     *
     * 选项缺失时按删除处理：拍摄地点、设备型号这类信息留在对外展示的图片上属于隐私泄露，
     * 默认不删不如默认删。
     *
     * @return bool
     */
    public static function strips_metadata(): bool {
        $general = get_option( 'local_crush_general', array() );

        return ! isset( $general['strip_metadata'] ) || (bool) $general['strip_metadata'];
    }

    /**
     * 设置页填写的工具目录
     *
     * @return string[]
     */
    public static function tool_dirs(): array {
        return Local_Crush_Tool_Base::configured_tool_dirs();
    }

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_settings_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_settings_menu() {
        add_options_page(
            __( 'Local Crush', 'local-crush' ),
            __( 'Local Crush', 'local-crush' ),
            'manage_options',
            'local-crush',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'local_crush_general_group',
            'local_crush_general',
            array( $this, 'sanitize_general_settings' )
        );

        register_setting(
            'local_crush_tools_group',
            'local_crush_tools',
            array( $this, 'sanitize_tools_settings' )
        );

        register_setting(
            'local_crush_tool_dirs_group',
            'local_crush_tool_dirs',
            array( $this, 'sanitize_tool_dirs' )
        );
    }

    /**
     * 保存工具目录
     *
     * 一行一个目录，只接受绝对路径；不是绝对路径的条目直接丢掉并提示，
     * 免得相对路径让工具查找跟着 PHP 的工作目录乱跑。目录是否存在不拦，
     * 页面会单独列出不存在的条目，方便先填后装。
     *
     * @param mixed $input 表单提交值
     * @return string[]
     */
    public function sanitize_tool_dirs( $input ) {
        $lines = is_string( $input ) ? preg_split( '/\R/', $input ) : array();
        $dirs  = array();
        $bad   = array();

        foreach ( (array) $lines as $line ) {
            $line = trim( (string) $line );

            if ( '' === $line ) {
                continue;
            }

            if ( ! Local_Crush_Tool_Base::is_absolute_path( $line ) ) {
                $bad[] = $line;
                continue;
            }

            if ( ! in_array( $line, $dirs, true ) ) {
                $dirs[] = $line;
            }
        }

        if ( ! empty( $bad ) ) {
            add_settings_error(
                'local_crush_tool_dirs',
                'local_crush_tool_dirs_invalid',
                sprintf(
                    /* translators: %s: 被忽略的条目，多个用顿号分隔 */
                    __( '以下条目不是绝对路径，已忽略：%s', 'local-crush' ),
                    implode( '、', $bad )
                )
            );
        }

        return $dirs;
    }

    public function sanitize_general_settings( $input ) {
        $input = is_array( $input ) ? $input : array();
        $sanitized = array();

        $current_general = get_option( 'local_crush_general', array() );
        $sanitized['auto_compress']      = ! empty( $input['auto_compress'] );
        $sanitized['backup_enabled']     = ! empty( $input['backup_enabled'] );
        $sanitized['backup_retention_days'] = self::normalize_retention_days( isset( $input['backup_retention_days'] ) ? $input['backup_retention_days'] : null );
        $sanitized['image_scale_enabled']      = ! empty( $input['image_scale_enabled'] );
        $sanitized['image_size_threshold']   = self::normalize_image_size_threshold( isset( $input['image_size_threshold'] ) ? $input['image_size_threshold'] : null );
        $sanitized['delete_unscaled_original'] = ! empty( $input['delete_unscaled_original'] );
        $sanitized['strip_metadata']        = ! empty( $input['strip_metadata'] );

        // 缩略图尺寸：每个尺寸都有隐藏域 0 和复选框 1 两个同名输入，后提交的复选框生效，
        // 所以未勾选时也会带上 0；表单里没有这组字段时保留原状态。
        if ( array_key_exists( 'thumbnail_state', $input ) ) {
            $state    = (array) $input['thumbnail_state'];
            $disabled = array();

            foreach ( array_keys( wp_get_registered_image_subsizes() ) as $size_name ) {
                if ( ! isset( $state[ $size_name ] ) || '1' !== (string) $state[ $size_name ] ) {
                    $disabled[] = $size_name;
                }
            }

            $sanitized['disabled_thumbnail_sizes'] = $disabled;
        } else {
            $stored                                = isset( $current_general['disabled_thumbnail_sizes'] ) ? (array) $current_general['disabled_thumbnail_sizes'] : array();
            $sanitized['disabled_thumbnail_sizes'] = array_values( array_filter( array_map( 'sanitize_key', $stored ) ) );
        }

        // 压缩输出设置：勾选的源格式输出为目标格式，未勾选格式执行同格式压缩。
        $sanitized['output_png'] = ! empty( $input['output_png'] );
        $sanitized['output_jpg'] = ! empty( $input['output_jpg'] );
        $sanitized['output_gif'] = ! empty( $input['output_gif'] );
        $sanitized['output_apng'] = ! empty( $input['output_apng'] );
        $sanitized['output_format'] = ( isset( $input['output_format'] ) && in_array( $input['output_format'], array( 'webp', 'avif', 'jxl' ), true ) ) ? $input['output_format'] : 'avif';

        // 兼容格式回退开关
        $sanitized['fallback_enabled'] = ! empty( $input['fallback_enabled'] );

        // SVG 上传开关
        $sanitized['allow_svg_upload'] = ! empty( $input['allow_svg_upload'] );

        return $sanitized;
    }

    /**
     * 保存压缩参数
     *
     * 表单一共提交哪些字段就校验哪些字段，全部按参数总表夹取；
     * 既不做缺省补全以外的手工拼装，也不给任何字段留“漏了就归零”的口子。
     *
     * @param mixed $input 表单提交值
     * @return array
     */
    public function sanitize_tools_settings( $input ) {
        return self::normalize_all( is_array( $input ) ? $input : array() );
    }

    public function render_settings_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读取用于切换设置页标签，已做白名单校验，不修改任何数据。
        $this->current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

        $valid_tabs = array( 'general', 'tools' );
        if ( ! in_array( $this->current_tab, $valid_tabs, true ) ) {
            $this->current_tab = 'general';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Local Crush', 'local-crush' ); ?></h1>

            <?php settings_errors(); ?>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=local-crush&tab=general' ) ); ?>" class="nav-tab <?php echo 'general' === $this->current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '基本设置', 'local-crush' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=local-crush&tab=tools' ) ); ?>" class="nav-tab <?php echo 'tools' === $this->current_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( '压缩工具', 'local-crush' ); ?>
                </a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                switch ( $this->current_tab ) {
                    case 'tools':
                        $this->render_tools_tab();
                        break;
                    default:
                        $this->render_general_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_general_tab() {
        $options = get_option( 'local_crush_general', array() );
        $retention_days = Local_Crush_Settings::normalize_retention_days( isset( $options['backup_retention_days'] ) ? $options['backup_retention_days'] : null );
        $registered_sizes = wp_get_registered_image_subsizes();
        $disabled_sizes   = Local_Crush_Thumbnail_Manager::disabled_sizes();
        ?>
        <style>
            .local-crush-seg { position: relative; display: inline-flex; background: #dcdcde; border-radius: 8px; padding: 3px; vertical-align: middle; }
            .local-crush-seg input { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }
            .local-crush-seg label { position: relative; z-index: 2; flex: 1; min-width: 64px; padding: 5px 16px; text-align: center; color: #50575e; font-weight: 500; cursor: pointer; border-radius: 6px; transition: color .15s; }
            .local-crush-seg .seg-thumb { position: absolute; z-index: 1; top: 3px; bottom: 3px; left: 3px; width: calc((100% - 6px) / 3); background: #fff; border-radius: 6px; box-shadow: 0 1px 3px rgba(0, 0, 0, .15); transition: transform .2s ease; }
            #local-crush-seg-avif:checked ~ .seg-thumb { transform: translateX(100%); }
            #local-crush-seg-jxl:checked ~ .seg-thumb { transform: translateX(200%); }
            #local-crush-seg-webp:checked ~ label[for="local-crush-seg-webp"],
            #local-crush-seg-avif:checked ~ label[for="local-crush-seg-avif"],
            #local-crush-seg-jxl:checked ~ label[for="local-crush-seg-jxl"] { color: #1d2327; font-weight: 600; }
        </style>
        <form method="post" action="options.php">
            <?php settings_fields( 'local_crush_general_group' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( '自动压缩', 'local-crush' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="local_crush_general[auto_compress]" value="1" <?php checked( ! empty( $options['auto_compress'] ) ); ?>>
                            <?php esc_html_e( '上传图片时自动压缩', 'local-crush' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '备份原图', 'local-crush' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="local_crush_general[backup_enabled]" value="1" <?php checked( $options['backup_enabled'] ?? true ); ?>>
                            <?php esc_html_e( '压缩前备份原图', 'local-crush' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="local-crush-retention-days"><?php esc_html_e( '原图备份保留时长', 'local-crush' ); ?></label></th>
                    <td>
                        <input type="number" id="local-crush-retention-days" name="local_crush_general[backup_retention_days]" value="<?php echo esc_attr( $retention_days ); ?>" min="-1" step="1" class="small-text">
                        <?php esc_html_e( '天', 'local-crush' ); ?>
                        <p class="description"><?php esc_html_e( '填 -1 表示永久保留；填天数则备份到期后自动删除，届时这张图将无法再恢复原图。0 无效，步进时会自动跳过 0。', 'local-crush' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '图片元数据', 'local-crush' ); ?></th>
                    <td>
                        <label style="display:block;">
                            <input type="checkbox" name="local_crush_general[strip_metadata]" value="1" <?php checked( $options['strip_metadata'] ?? true ); ?>>
                            <?php esc_html_e( '压缩时删除图片元数据', 'local-crush' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '删除 EXIF、GPS 拍摄位置、设备型号、作者与软件信息等隐私数据，对同格式压缩与格式转换的所有路径都生效。为了不让手机竖拍照片变横，会单独保留照片方向；ICC 色彩配置会尽量保留以免偏色；取消勾选则尽量保留元数据；经过解码再编码的路径（如 AVIF、动画 WebP）仍可能留不住全部元数据。', 'local-crush' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'SVG 上传', 'local-crush' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="local_crush_general[allow_svg_upload]" value="1" <?php checked( ! empty( $options['allow_svg_upload'] ) ); ?>>
                            <?php esc_html_e( '允许上传 SVG 文件', 'local-crush' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '上传时自动清理脚本等危险内容；SVG 可能被用于 XSS 攻击，仅在信任上传者时开启。', 'local-crush' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '格式转换', 'local-crush' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="local_crush_general[output_png]" value="1" <?php checked( ! empty( $options['output_png'] ) ); ?>>
                            <?php esc_html_e( 'PNG', 'local-crush' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="local_crush_general[output_jpg]" value="1" <?php checked( ! empty( $options['output_jpg'] ) ); ?>>
                            <?php esc_html_e( 'JPG', 'local-crush' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="local_crush_general[output_gif]" value="1" <?php checked( ! empty( $options['output_gif'] ) ); ?>>
                            <?php esc_html_e( 'GIF', 'local-crush' ); ?>
                        </label>
                        &nbsp;&nbsp;
                        <label>
                            <input type="checkbox" name="local_crush_general[output_apng]" value="1" <?php checked( ! empty( $options['output_apng'] ) ); ?>>
                            <?php esc_html_e( 'APNG', 'local-crush' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '勾选的源格式执行压缩时输出为目标格式；未勾选的格式只执行同格式压缩。所有成功结果都记为已压缩。只有 PNG、JPG、GIF、APNG 能转换格式：APNG 由自己的勾选单独控制，与 PNG 无关，不勾就只做无损压缩。WebP、AVIF、JPEG XL 无论静图动图都只做同格式压缩，不会再来一遍转换。动图整张交给支持动画和透明背景的工具处理，插件不拆帧、不修改透明像素。', 'local-crush' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '兼容格式回退', 'local-crush' ); ?></th>
                    <td>
                        <label style="display:block;">
                            <input type="checkbox" name="local_crush_general[fallback_enabled]" value="1" <?php checked( $options['fallback_enabled'] ?? false ); ?>>
                            <?php esc_html_e( '为转换后的图片提供旧格式回退', 'local-crush' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( '开启后，格式转换会把旧格式文件原地留下并同样压缩，新的 WebP/AVIF 以“原文件名.webp”“原文件名.avif”的双扩展名形式生成，前台用 <picture> 输出：支持新格式的浏览器加载新格式，不支持的浏览器加载同样压缩过的旧格式。关闭后转换不再保留旧格式文件，新格式直接替换源文件，前台也只输出新格式。此前已转换的图片仍留着旧格式文件，可用下方的“删除所有兼容格式回退”清理。', 'local-crush' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '目标格式', 'local-crush' ); ?></th>
                    <td>
                        <span class="local-crush-seg">
                            <input type="radio" name="local_crush_general[output_format]" value="webp" id="local-crush-seg-webp" <?php checked( ( $options['output_format'] ?? 'avif' ), 'webp' ); ?>>
                            <label for="local-crush-seg-webp"><?php esc_html_e( 'WebP', 'local-crush' ); ?></label>
                            <input type="radio" name="local_crush_general[output_format]" value="avif" id="local-crush-seg-avif" <?php checked( ( $options['output_format'] ?? 'avif' ), 'avif' ); ?>>
                            <label for="local-crush-seg-avif"><?php esc_html_e( 'AVIF', 'local-crush' ); ?></label>
                            <input type="radio" name="local_crush_general[output_format]" value="jxl" id="local-crush-seg-jxl" <?php checked( ( $options['output_format'] ?? 'avif' ), 'jxl' ); ?>>
                            <label for="local-crush-seg-jxl"><?php esc_html_e( 'JPEG XL', 'local-crush' ); ?></label>
                            <span class="seg-thumb"></span>
                        </span>
                        <p class="description"><?php esc_html_e( '选择目标格式：AVIF 压缩率更高但压缩更慢，WebP 兼容性最好，JPEG XL 同画质体积最小但浏览器支持仍不完整（动画 JXL 更是只有少数浏览器能播），选 JPEG XL 时建议同时开启上面的“兼容格式回退”。', 'local-crush' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( '缩略图尺寸', 'local-crush' ); ?></th>
                    <td>
                        <label style="display:block; margin-bottom:10px;">
                            <input type="checkbox" name="local_crush_general[image_scale_enabled]" value="1" <?php checked( Local_Crush_Settings::image_scale_enabled() ); ?>>
                            <?php esc_html_e( '自动缩放超尺寸的主图，阈值', 'local-crush' ); ?>
                            <input type="number" name="local_crush_general[image_size_threshold]" value="<?php echo esc_attr( Local_Crush_Settings::image_size_threshold() ); ?>" min="1" max="20000" step="1" class="small-text">
                            <?php esc_html_e( '像素', 'local-crush' ); ?>
                        </label>
                        <p class="description" style="margin-top:0; margin-bottom:12px;"><?php esc_html_e( '勾选后，上传或补生成缩略图时，宽或高超过此阈值的主图会另存为 -scaled 缩放图并接管主文件，未缩放的源文件仍留在磁盘上；不勾选则关闭自动缩放，原图直接投入使用。压缩已有图片时不会改动缩放，改动缩放请用下方的“删除未勾选尺寸的缩略图”和“补生成缺失尺寸的缩略图”两个按钮。默认 2560，与 WordPress 默认一致。', 'local-crush' ); ?></p>
                        <label style="display:block; margin-bottom:10px;">
                            <input type="checkbox" name="local_crush_general[delete_unscaled_original]" value="1" <?php checked( Local_Crush_Settings::delete_unscaled_original() ); ?>>
                            <?php esc_html_e( '压缩后删除未缩放的原始大图', 'local-crush' ); ?>
                        </label>
                        <p class="description" style="margin-top:0;"><?php esc_html_e( '原图超过阈值时 WordPress 会另存一张 -scaled 缩放图当媒体库的全尺寸主图，未缩放的那份原始大图只躺在上传目录里，没有任何按钮能调用到。勾选后，手动或自动压缩结束时会把它删掉，只保留 -scaled 图，省下一份原始大图的磁盘占用；上传生图时不会删，删除只发生在压缩的时候。媒体库和文章里的图片都走 -scaled 图，不受影响，只有自己手动填过未缩放原图地址的地方会失效。开了“备份原图”的随时能恢复，没开备份的删掉就找不回来了；恢复原图时会按当前缩放设置重新生成 -scaled 图，并保留恢复出来的原图。本选项只对之后压缩的图片生效，之前已经压缩完的图片不会被清理。取消上面的“自动缩放超尺寸的主图”后本选项不起作用。', 'local-crush' ); ?></p>
                        <div style="margin-top:14px;">
                            <?php if ( empty( $registered_sizes ) ) : ?>
                                <p class="description"><?php esc_html_e( '当前没有注册额外的缩略图尺寸。', 'local-crush' ); ?></p>
                            <?php else : ?>
                                <?php foreach ( $registered_sizes as $size_name => $size_config ) : ?>
                                    <?php
                                    $width     = (int) $size_config['width'];
                                    $height    = (int) $size_config['height'];
                                    // 宽或高为 0 表示该方向不限，由 WordPress 按原图比例自动推算。
                                    $dimension = sprintf(
                                        '%1$s×%2$s',
                                        $width > 0 ? $width : __( '自动', 'local-crush' ),
                                        $height > 0 ? $height : __( '自动', 'local-crush' )
                                    );
                                    $crop      = empty( $size_config['crop'] ) ? __( '等比', 'local-crush' ) : __( '裁剪', 'local-crush' );
                                    ?>
                                    <label style="display:block;">
                                        <input type="hidden" name="local_crush_general[thumbnail_state][<?php echo esc_attr( $size_name ); ?>]" value="0">
                                        <input type="checkbox" name="local_crush_general[thumbnail_state][<?php echo esc_attr( $size_name ); ?>]" value="1" <?php checked( ! in_array( $size_name, $disabled_sizes, true ) ); ?>>
                                        <?php
                                        printf(
                                            /* translators: 1: 尺寸名, 2: 目标像素, 3: 裁剪方式 */
                                            esc_html__( '%1$s（%2$s，%3$s）', 'local-crush' ),
                                            esc_html( $size_name ),
                                            esc_html( $dimension ),
                                            esc_html( $crop )
                                        );
                                        ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description"><?php esc_html_e( '取消勾选的尺寸在上传图片时不再生成；已经存在的文件用下方“删除未勾选尺寸的缩略图”清理。以后新注册的尺寸默认勾选。', 'local-crush' ); ?></p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <hr>

        <table class="form-table">
            <tr>
                <td style="width: 200px; padding: 10px 0;">
                    <button type="button" class="button" id="local-crush-bulk-compress">
                        <?php esc_html_e( '批量压缩未压缩的图片', 'local-crush' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '按当前设置压缩媒体库中所有未压缩的图片', 'local-crush' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="local-crush-restore-all">
                        <?php esc_html_e( '恢复所有原图备份', 'local-crush' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '将所有已压缩的图片恢复为原图', 'local-crush' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="local-crush-delete-all-backups">
                        <?php esc_html_e( '删除所有原图备份', 'local-crush' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除所有备份文件，释放磁盘空间', 'local-crush' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="local-crush-delete-all-fallbacks">
                        <?php esc_html_e( '删除所有兼容格式回退', 'local-crush' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除媒体库中转换后保留的旧格式回退文件，并把文章里指向它们的图片链接改到新格式；删除后不支持新格式的浏览器将直接加载新格式图片', 'local-crush' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="local-crush-delete-all-originals">
                        <?php esc_html_e( '删除所有未缩放的原始大图', 'local-crush' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除所有生成过 -scaled 缩放图的图片留在磁盘上的未缩放原始大图：格式转换后的那份和它的旧格式回退文件一并删除，媒体库主图仍是 -scaled 图；不涉及备份，没备份的删掉就找不回来了', 'local-crush' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="local-crush-delete-thumbnails">
                        <?php esc_html_e( '删除未勾选尺寸的缩略图', 'local-crush' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '删除未勾选尺寸已存在的缩略图，文章里指向它们的图片链接会改到面积最接近的现有尺寸；若关闭了自动缩放，或现有 -scaled 缩放图与阈值不符，也会删除缩放图让主图退回原图', 'local-crush' ); ?></span>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px 0;">
                    <button type="button" class="button" id="local-crush-generate-thumbnails">
                        <?php esc_html_e( '补生成缺失尺寸的缩略图', 'local-crush' ); ?>
                    </button>
                </td>
                <td style="padding: 10px 0;">
                    <span class="description"><?php esc_html_e( '为已勾选但缺失的尺寸补生成缩略图；若开启了自动缩放且主图超过阈值，还会生成或修正 -scaled 缩放图。不会重建已存在的缩略图，纯缩略图缺失也不改动文章链接', 'local-crush' ); ?></span>
                </td>
            </tr>
        </table>

        <?php
        // 批量操作由服务器自己在后台一批批地跑（见 Local_Crush_Task），
        // 这里的面板只负责把进度摆出来给用户看，初始状态由脚本数据带过去。
        ?>
        <style>
            .local-crush-task { margin-top: 12px; padding: 12px 14px; max-width: 900px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; border-radius: 3px; }
            .local-crush-task.is-done { border-left-color: #00a32a; }
            .local-crush-task.is-stopped { border-left-color: #dba617; }
            .local-crush-task-head { display: flex; align-items: baseline; gap: 8px; margin-bottom: 8px; }
            .local-crush-task-head .task-label { font-size: 14px; }
            .local-crush-task-head .task-status { color: #646970; font-size: 12px; }
            .local-crush-task .task-bar { position: relative; height: 22px; background: #f0f0f1; border-radius: 3px; overflow: hidden; }
            .local-crush-task .task-bar-fill { width: 0; height: 100%; background: #2271b1; border-radius: 3px; transition: width .3s ease; }
            .local-crush-task.is-done .task-bar-fill { background: #00a32a; }
            .local-crush-task.is-stopped .task-bar-fill { background: #dba617; }
            .local-crush-task .task-bar-text { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #1d2327; }
            .local-crush-task .task-line { margin: 8px 0 0; color: #50575e; font-size: 13px; }
            .local-crush-task .task-current { word-break: break-all; }
            .local-crush-task .task-summary { margin: 10px 0 0; white-space: pre-line; font-size: 13px; color: #1d2327; }
            .local-crush-task .task-actions { margin: 10px 0 0; display: flex; flex-wrap: wrap; gap: 8px; }
        </style>
        <div id="local-crush-task" class="local-crush-task" style="display: none;">
            <div class="local-crush-task-head">
                <strong class="task-label"></strong>
                <span class="task-status"></span>
            </div>
            <div class="task-bar">
                <div class="task-bar-fill"></div>
                <span class="task-bar-text"></span>
            </div>
            <p class="task-line task-progress"></p>
            <p class="task-line task-counts"></p>
            <p class="task-line task-current"></p>
            <p class="task-summary"></p>
            <p class="task-actions">
                <button type="button" class="button" id="local-crush-task-stop"><?php esc_html_e( '停止任务', 'local-crush' ); ?></button>
                <button type="button" class="button" id="local-crush-task-resume"><?php esc_html_e( '继续执行', 'local-crush' ); ?></button>
                <button type="button" class="button" id="local-crush-task-retry"><?php esc_html_e( '重试失败的项目', 'local-crush' ); ?></button>
                <button type="button" class="button" id="local-crush-task-dismiss"><?php esc_html_e( '完成', 'local-crush' ); ?></button>
            </p>
        </div>
        <?php
    }

    private function render_tools_tab() {
        $compressor       = local_crush()->compressor;
        $output_processor = local_crush()->output_processor;
        $tools            = $compressor->get_tools();

        $proc_open_available = Local_Crush_Tool_Base::is_command_execution_available();

        // 全部压缩工具，按格式相邻排序：同格式的排在一起，编码/解码工具也挨着放
        $all_tools = array(
            array( 'name' => 'jpegoptim', 'tool' => null, 'path' => $output_processor->find_local_tool( 'jpegoptim' ), 'url' => 'https://github.com/tjko/jpegoptim' ),
            array( 'name' => 'pngquant', 'tool' => $tools['pngquant'] ),
            array( 'name' => 'oxipng', 'tool' => $tools['oxipng'] ),
            array( 'name' => 'gifsicle', 'tool' => $tools['gifsicle'] ),
            array( 'name' => 'gif2webp', 'tool' => null, 'path' => $output_processor->find_local_tool( 'gif2webp' ), 'url' => 'https://developers.google.com/speed/webp/download' ),
            array( 'name' => 'cwebp', 'tool' => $tools['cwebp'] ),
            array( 'name' => 'webpmux', 'tool' => null, 'path' => $output_processor->find_local_tool( 'webpmux' ), 'url' => 'https://developers.google.com/speed/webp/download' ),
            array( 'name' => 'avifenc', 'tool' => null, 'path' => $output_processor->find_local_tool( 'avifenc' ), 'url' => 'https://github.com/AOMediaCodec/libavif' ),
            array( 'name' => 'avifdec', 'tool' => null, 'path' => $output_processor->find_local_tool( 'avifdec' ), 'url' => 'https://github.com/AOMediaCodec/libavif' ),
            array( 'name' => 'cjxl', 'tool' => isset( $tools['cjxl'] ) ? $tools['cjxl'] : null, 'path' => $output_processor->find_local_tool( 'cjxl' ), 'url' => 'https://github.com/libjxl/libjxl' ),
            array( 'name' => 'oxvg', 'tool' => $tools['oxvg'] ),
            array( 'name' => 'ffprobe', 'tool' => null, 'path' => $output_processor->find_local_tool( 'ffprobe' ), 'url' => 'https://ffmpeg.org/download.html' ),
            array( 'name' => 'ffmpeg', 'tool' => null, 'path' => $output_processor->find_local_tool( 'ffmpeg' ), 'url' => 'https://ffmpeg.org/download.html' ),
        );

        // 统一为渲染字段：available(bool)、path、url
        foreach ( $all_tools as $index => $row ) {
            if ( null !== $row['tool'] ) {
                $all_tools[ $index ]['available'] = $row['tool']->is_tool_available();
                $all_tools[ $index ]['path']      = $row['tool']->get_tool_binary_path();
                $all_tools[ $index ]['url']       = $row['tool']->get_download_url();
            } else {
                $all_tools[ $index ]['available'] = false !== $row['path'];
            }
        }
        ?>
        <h2><?php esc_html_e( '系统状态', 'local-crush' ); ?></h2>
        <table class="widefat" style="max-width: 900px; table-layout: fixed;">
            <colgroup>
                <col style="width: 130px;">
                <col style="width: 90px;">
                <col>
                <col style="width: 50px;">
            </colgroup>
            <tr>
                <td><strong>proc_open()</strong></td>
                <td>
                    <?php if ( $proc_open_available ) : ?>
                        <span style="color: #00a32a;">✓ <?php esc_html_e( '可用', 'local-crush' ); ?></span>
                    <?php else : ?>
                        <span><?php esc_html_e( '不可用', 'local-crush' ); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( ! $proc_open_available ) : ?>
                        <span class="description"><?php esc_html_e( '插件的所有压缩都靠 proc_open() 直接启动命令行工具，请联系主机商启用', 'local-crush' ); ?></span>
                    <?php endif; ?>
                </td>
                <td></td>
            </tr>
        </table>

        <h2><?php esc_html_e( '工具状态', 'local-crush' ); ?></h2>
        <table class="widefat" style="max-width: 900px; table-layout: fixed;">
            <colgroup>
                <col style="width: 130px;">
                <col style="width: 90px;">
                <col>
                <col style="width: 50px;">
            </colgroup>
            <thead>
                <tr>
                    <th><?php esc_html_e( '工具', 'local-crush' ); ?></th>
                    <th><?php esc_html_e( '状态', 'local-crush' ); ?></th>
                    <th><?php esc_html_e( '安装路径', 'local-crush' ); ?></th>
                    <th><?php esc_html_e( '链接', 'local-crush' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $all_tools as $row ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
                        <td>
                            <?php if ( $row['available'] ) : ?>
                                <span style="color: #00a32a;">✓ <?php esc_html_e( '已安装', 'local-crush' ); ?></span>
                            <?php else : ?>
                                <span><?php esc_html_e( '未安装', 'local-crush' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( false !== $row['path'] ) : ?>
                                <code style="word-break: break-all; font-size: 11px;"><?php echo esc_html( $row['path'] ); ?></code>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( '跳转', 'local-crush' ); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description" style="max-width: 900px; margin-top: 10px;">
            <?php esc_html_e( '安装方式：① 把可执行文件直接放进 /wp-content/local-crush-bin 目录；② 把工具所在文件夹填到下面的“工具目录”；③ 用包管理器安装（Linux 上 apt 会装到 /usr/bin、/usr/local/bin，插件会自动找）；④ Windows 上改系统 Path 后必须重启 PHP / Apache 才生效，Linux 上在 shell 里改 PATH 对 PHP 无效，建议用“工具目录”。', 'local-crush' ); ?>
        </p>

        <?php
        $configured_dirs = Local_Crush_Settings::tool_dirs();
        $missing_dirs    = array();

        foreach ( $configured_dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                $missing_dirs[] = $dir;
            }
        }
        ?>
        <form method="post" action="options.php" style="max-width: 900px; margin-top: 18px;">
            <?php settings_fields( 'local_crush_tool_dirs_group' ); ?>
            <h2 style="margin: 0 0 6px;"><?php esc_html_e( '工具目录', 'local-crush' ); ?></h2>
            <div style="display: flex; gap: 8px; align-items: stretch;">
                <textarea id="local-crush-tool-dirs" name="local_crush_tool_dirs" rows="1" class="large-text code" spellcheck="false" style="flex: 1 1 auto; min-width: 0; width: auto; min-height: 40px; margin: 0; font-size: 14px; line-height: 20px;"><?php echo esc_textarea( implode( "\n", $configured_dirs ) ); ?></textarea>
                <?php submit_button( __( '保存工具目录', 'local-crush' ), 'secondary', 'submit', false, array( 'style' => 'flex: 0 0 auto; align-self: flex-start; margin: 0;' ) ); ?>
            </div>
            <p class="description" style="margin: 4px 0 0;">
                <?php esc_html_e( '一行一个绝对路径。工具解压在别的文件夹时填这里；用包管理器装到 /usr/bin、/usr/local/bin 的不用填。', 'local-crush' ); ?>
            </p>
            <?php if ( ! empty( $missing_dirs ) ) : ?>
                <p class="description" style="margin: 4px 0 0; color: #b32d2e;">
                    <?php
                    printf(
                        /* translators: %s: 不存在的目录，多个用顿号分隔 */
                        esc_html__( '这些目录不存在，暂时不会参与查找：%s', 'local-crush' ),
                        esc_html( implode( '、', $missing_dirs ) )
                    );
                    ?>
                </p>
            <?php endif; ?>
        </form>

        <h2 style="margin-top: 30px;"><?php esc_html_e( '路径支持状态', 'local-crush' ); ?></h2>
        <table class="widefat" style="max-width: 900px;">
            <thead>
                <tr>
                    <th style="width: 220px;"><?php esc_html_e( '路径', 'local-crush' ); ?></th>
                    <th><?php esc_html_e( '依赖', 'local-crush' ); ?></th>
                    <th style="width: 280px;"><?php esc_html_e( '状态', 'local-crush' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $this->get_path_support_rows() as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['label'] ); ?></td>
                        <td><?php echo wp_kses_post( $row['deps_display'] ); ?></td>
                        <td>
                            <?php if ( $row['supported'] ) : ?>
                                <span style="color: #00a32a;">✓ <?php esc_html_e( '支持', 'local-crush' ); ?></span>
                            <?php else : ?>
                                <span><?php esc_html_e( '不支持', 'local-crush' ); ?></span>
                                <?php if ( ! empty( $row['missing'] ) ) : ?>
                                    <span class="description">— <?php echo esc_html( sprintf( __( '缺少：%s', 'local-crush' ), implode( '、', $row['missing'] ) ) ); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <hr>

        <h2><?php esc_html_e( '压缩参数', 'local-crush' ); ?></h2>
        <p class="description" style="max-width: 900px;">
            <?php esc_html_e( '参数按压缩路径分组：只有整条工具链完全一致的路径才共用一节，其余各自独立，改了不会连带改到别的路径。输入框后面标的就是它实际传给哪个工具的哪个参数，取值也就是工具本身的取值范围，插件不做换算、不折算档位，你填多少就传多少；只有超出范围时保存时会收到该参数的边界值，免得把非法数字塞给工具。没列出来的参数一律不传，由工具用自己内部的默认值。每个参数的默认值都优先取压缩工具自己的默认值，工具没有默认值的（比如各编码器的质量）才用插件推荐的平衡值，每项说明里都写明了来头；拿不准全部保持默认就行。改动只影响之后的压缩，已经压好的图片不会自动重压。', 'local-crush' ); ?>
        </p>
        <form method="post" action="options.php">
            <?php settings_fields( 'local_crush_tools_group' ); ?>

            <?php
            // 一个工具一个分组：同一个工具的静图、动图、同格式压缩与格式转换共用一套参数。
            foreach ( self::param_groups() as $group_id => $group ) :
                ?>
                <h4 style="margin: 26px 0 4px; font-size: 15px;"><?php echo esc_html( $group['title'] ); ?></h4>
                <?php if ( ! empty( $group['note'] ) ) : ?>
                    <p class="description" style="max-width: 900px;"><?php echo esc_html( $group['note'] ); ?></p>
                <?php endif; ?>
                <table class="form-table">
                    <?php
                    foreach ( $group['params'] as $key => $param ) {
                        $this->render_param_row( $key, $param );
                    }
                    ?>
                </table>
            <?php endforeach; ?>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * 按参数定义渲染一行设置
     *
     * @param string $key   参数键名
     * @param array  $param 参数定义
     */
    private function render_param_row( string $key, array $param ): void {
        $value = self::param( $key );
        $id    = 'local-crush-' . $key;
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $param['label'] ); ?></label></th>
            <td>
                <?php if ( 'mode' === $param['type'] ) : ?>
                    <label>
                        <input type="radio" name="local_crush_tools[<?php echo esc_attr( $key ); ?>]" value="lossy" <?php checked( $value, 'lossy' ); ?>>
                        <?php esc_html_e( '有损压缩', 'local-crush' ); ?>
                    </label>
                    &nbsp;&nbsp;
                    <label>
                        <input type="radio" name="local_crush_tools[<?php echo esc_attr( $key ); ?>]" value="lossless" <?php checked( $value, 'lossless' ); ?>>
                        <?php esc_html_e( '无损压缩', 'local-crush' ); ?>
                    </label>
                <?php elseif ( 'choice' === $param['type'] ) : ?>
                    <select id="<?php echo esc_attr( $id ); ?>" name="local_crush_tools[<?php echo esc_attr( $key ); ?>]">
                        <?php foreach ( $param['choices'] as $choice => $text ) : ?>
                            <option value="<?php echo esc_attr( $choice ); ?>" <?php selected( (string) $value, (string) $choice ); ?>><?php echo esc_html( $text ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ( 'bool' === $param['type'] ) : ?>
                    <input type="hidden" name="local_crush_tools[<?php echo esc_attr( $key ); ?>]" value="0">
                    <input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="local_crush_tools[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( (bool) $value ); ?>>
                <?php else : ?>
                    <input type="number" id="<?php echo esc_attr( $id ); ?>" name="local_crush_tools[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $param['min'] ); ?>" max="<?php echo esc_attr( $param['max'] ); ?>" step="1" class="small-text">
                <?php endif; ?>

                <?php if ( ! empty( $param['flag'] ) ) : ?>
                    <code style="margin-left: 6px; font-size: 11px;"><?php echo esc_html( $param['flag'] ); ?></code>
                <?php endif; ?>

                <p class="description">
                    <?php
                    if ( 'int' === $param['type'] ) {
                        printf(
                            /* translators: 1: 最小取值，2: 最大取值，3: 默认值 */
                            esc_html__( '范围 %1$d-%2$d，默认 %3$d。', 'local-crush' ),
                            (int) $param['min'],
                            (int) $param['max'],
                            (int) $param['default']
                        );
                        echo ' ';
                    } elseif ( 'choice' === $param['type'] ) {
                        printf(
                            /* translators: %s: 默认选项的显示文字 */
                            esc_html__( '默认：%s。', 'local-crush' ),
                            esc_html( $param['choices'][ $param['default'] ] ?? '' )
                        );
                        echo ' ';
                    }

                    echo esc_html( $param['desc'] );
                    ?>
                </p>
            </td>
        </tr>
        <?php
    }

    /**
     * 构建压缩路径支持状态行
     *
     * groups 为"或"关系：任一组内依赖全部可用即视为支持该路径，
     * 组内为"与"关系，包含该路径实际调用的全部工具
     *
     * @return array[] 每行包含 label、deps_display、supported、missing
     */
    private function get_path_support_rows(): array {
        $tools            = local_crush()->compressor->get_tools();
        $output_processor = local_crush()->output_processor;

        $available = array();
        foreach ( $tools as $name => $tool ) {
            $available[ $name ] = $tool->is_tool_available();
        }

        // avifenc/avifdec 按可执行文件独立判定（同格式 AVIF 需要两者，目标输出只需要 avifenc）
        $available['jpegoptim'] = false !== $output_processor->find_local_tool( 'jpegoptim' );
        $available['avifenc']   = false !== $output_processor->find_local_tool( 'avifenc' );
        $available['avifdec']   = false !== $output_processor->find_local_tool( 'avifdec' );
        $available['gif2webp']  = false !== $output_processor->find_local_tool( 'gif2webp' );
        $available['ffmpeg']    = false !== $output_processor->find_local_tool( 'ffmpeg' );

        // 动图处理用到的 libwebp 与 libavif 附带工具按可执行文件独立判定
        foreach ( array( 'ffprobe', 'webpmux' ) as $helper ) {
            $available[ $helper ] = false !== $output_processor->find_local_tool( $helper );
        }

        // 静态 GIF 转 AVIF 要先经 GD 解成 PNG
        $available['gd'] = function_exists( 'imagecreatefromgif' ) && function_exists( 'imagepng' );

        $dep_labels = array(
            'jpegoptim' => 'jpegoptim',
            'pngquant'  => 'pngquant',
            'oxipng'    => 'oxipng',
            'cwebp'     => 'cwebp',
            'avifenc'   => 'avifenc',
            'avifdec'   => 'avifdec',
            'cjxl'      => 'cjxl',
            'gifsicle'  => 'gifsicle',
            'oxvg'      => 'oxvg',
            'gif2webp'  => 'gif2webp',
            'ffprobe'   => 'ffprobe',
            'webpmux'   => 'webpmux',
            'ffmpeg'    => 'ffmpeg',
            'gd'        => __( 'GD 扩展', 'local-crush' ),
        );

        // 一行一条路径，和参数区一一对应：deps 就是这条命令真实调用的全部工具，一个不多一个不少。
        $rows_definition = array(
            array( 'label' => __( 'JPEG 压缩', 'local-crush' ), 'deps' => array( 'jpegoptim' ) ),
            array( 'label' => __( 'PNG 有损压缩', 'local-crush' ), 'deps' => array( 'pngquant' ) ),
            array( 'label' => __( 'PNG / APNG 无损压缩', 'local-crush' ), 'deps' => array( 'oxipng' ) ),
            array( 'label' => __( 'GIF 压缩', 'local-crush' ), 'deps' => array( 'gifsicle' ) ),
            array( 'label' => __( 'SVG 压缩', 'local-crush' ), 'deps' => array( 'oxvg' ) ),
            array( 'label' => __( 'WebP 压缩 / PNG·JPG 转 WebP', 'local-crush' ), 'deps' => array( 'cwebp' ) ),
            array( 'label' => __( '动画 WebP 压缩 / APNG 转 WebP', 'local-crush' ), 'deps' => Local_Crush_Animation::required_tool_groups( 'webp', '' ) ),
            array( 'label' => __( 'AVIF 同格式压缩', 'local-crush' ), 'deps' => array( 'avifdec', 'avifenc' ) ),
            array( 'label' => __( '动画 AVIF 同格式压缩', 'local-crush' ), 'deps' => Local_Crush_Animation::required_tool_groups( 'avif', '' ) ),
            array( 'label' => __( 'JPEG XL 压缩 / 全部转 JPEG XL', 'local-crush' ), 'deps' => array( 'cjxl' ) ),
            array( 'label' => __( '静态 GIF 转 WebP', 'local-crush' ), 'deps' => array( 'gif2webp' ) ),
            array( 'label' => __( '动画 GIF 转 WebP', 'local-crush' ), 'deps' => Local_Crush_Animation::required_tool_groups( 'gif', 'webp' ) ),
            array( 'label' => __( 'PNG / JPG 转 AVIF', 'local-crush' ), 'deps' => array( 'avifenc' ) ),
            array( 'label' => __( '静态 GIF 转 AVIF', 'local-crush' ), 'deps' => array( 'gd', 'avifenc' ) ),
            array( 'label' => __( '动画 GIF / APNG 转 AVIF', 'local-crush' ), 'deps' => Local_Crush_Animation::required_tool_groups( 'gif', 'avif' ) ),
        );

        $rows = array();

        foreach ( $rows_definition as $definition ) {
            $deps         = $definition['deps'];
            $missing      = array();
            $deps_display = array();

            // required_tool_groups() 返回的是"或"的多组，扁平列表就是一组"与"
            if ( ! empty( $deps ) && ! is_array( reset( $deps ) ) ) {
                $deps = array( $deps );
            }

            foreach ( $deps as $group ) {
                $group_labels = array();

                foreach ( $group as $dep ) {
                    $label = isset( $dep_labels[ $dep ] ) ? $dep_labels[ $dep ] : $dep;

                    $group_labels[] = '<code>' . esc_html( $label ) . '</code>';

                    if ( empty( $available[ $dep ] ) ) {
                        $missing[] = $label;
                    }
                }

                $deps_display[] = implode( ' + ', $group_labels );
            }

            $rows[] = array(
                'label'        => $definition['label'],
                'deps_display' => implode( ' ' . __( '或', 'local-crush' ) . ' ', $deps_display ),
                'supported'    => empty( $missing ),
                'missing'      => array_values( array_unique( $missing ) ),
            );
        }

        return $rows;
    }

}
