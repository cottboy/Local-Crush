/**
 * Local Crush 后台 JavaScript
 *
 * @package LocalCrush
 */

(function($) {
    'use strict';

    // 确保数据对象存在
    if (typeof localCrushData === 'undefined') {
        return;
    }

    var LC = {
        ajaxUrl: localCrushData.ajaxUrl,
        nonce: localCrushData.nonce,
        i18n: localCrushData.i18n,
        taskState: localCrushData.taskState || { has_task: false },

        // 批量任务：面板、轮询定时器、连续取不到状态的次数
        $panel: null,
        taskTimer: null,
        taskErrors: 0,

        // 详情弹窗里是否删过文件：关弹窗时据此刷新媒体库，免得列表上的占用信息过期
        detailsDirty: false,

        /**
         * 批量按钮与后台任务类型的对应关系
         */
        batchButtons: {
            '#local-crush-bulk-compress': { type: 'compress', confirm: 'confirmBulkCompress' },
            '#local-crush-restore-all': { type: 'restore', confirm: 'confirmRestoreAll' },
            '#local-crush-delete-all-backups': { type: 'delete_backups', confirm: 'confirmDeleteAllBackups' },
            '#local-crush-delete-all-fallbacks': { type: 'delete_fallbacks', confirm: 'confirmDeleteAllFallbacks' },
            '#local-crush-delete-all-originals': { type: 'delete_originals', confirm: 'confirmDeleteAllOriginals' },
            '#local-crush-delete-thumbnails': { type: 'delete_thumbnails', confirm: 'confirmDeleteThumbnails' },
            '#local-crush-generate-thumbnails': { type: 'generate_thumbnails', confirm: 'confirmGenerateThumbnails' },
            '#local-crush-unlock-thumbnails': { type: 'unlock_thumbnails', confirm: 'confirmUnlockThumbnails' }
        },

        /**
         * 初始化
         */
        init: function() {
            this.bindEvents();
            this.initTaskPanel();
        },

        /**
         * 绑定事件
         */
        bindEvents: function() {
            var self = this;

            // 媒体库压缩/恢复按钮
            $(document).on('click', '.local-crush-btn', this.handleMediaLibraryAction.bind(this));

            // 详情弹窗：尺寸右侧的删除按钮、标题下的操作按钮、单图开关
            $(document).on('click', '.local-crush-size-delete', this.handleSizeDelete.bind(this));
            $(document).on('click', '.local-crush-details-op', this.handleDetailsOperation.bind(this));
            $(document).on('change', '.local-crush-switch input', this.handleNoThumbnailsSwitch.bind(this));

            // 批量按钮：只是把任务交给服务器，之后由面板显示进度
            $.each(this.batchButtons, function(selector, config) {
                $(document).on('click', selector, function(e) {
                    e.preventDefault();
                    self.startTask(config.type, config.confirm ? self.i18n[config.confirm] : '');
                });
            });

            // 任务面板上的按钮
            $(document).on('click', '#local-crush-task-stop', this.stopTask.bind(this));
            $(document).on('click', '#local-crush-task-resume', this.resumeTask.bind(this));
            $(document).on('click', '#local-crush-task-retry', this.retryTask.bind(this));
            $(document).on('click', '#local-crush-task-dismiss', this.dismissTask.bind(this));

            // 原图备份保留时长不接受 0，步进跨过 0 时按方向落到 1 或 -1
            $(document).on('input change', '#local-crush-retention-days', this.skipRetentionZero.bind(this));
        },

        /**
         * 归一化备份保留时长输入，跳过无效值 0
         */
        skipRetentionZero: function(e) {
            var $input = $(e.currentTarget);
            var days = parseInt($input.val(), 10);

            if (isNaN(days)) {
                return;
            }

            if (days !== 0) {
                $input.data('lastDays', days);
                return;
            }

            var last = $input.data('lastDays');
            days = (typeof last === 'number' && last > 0) ? -1 : 1;

            $input.val(days).data('lastDays', days);
        },

        /**
         * 处理媒体库操作
         */
        handleMediaLibraryAction: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var action = $btn.data('action');
            var attachmentId = $btn.data('attachment-id');

            if (!attachmentId) {
                return;
            }

            if (action === 'details') {
                this.showDetails(attachmentId);
                return;
            }

            $btn.data('original-label', $btn.text());
            $btn.prop('disabled', true).text(this.i18n.processing);

            if (action === 'compress') {
                this.compressSingle(attachmentId, $btn);
            } else if (action === 'restore') {
                this.restoreSingle(attachmentId, $btn);
            }
        },

        /**
         * 压缩单张图片
         */
        compressSingle: function(attachmentId, $btn) {
            var self = this;

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'local_crush_compress_single',
                    nonce: this.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (!response.success) {
                        alert(response.data.message || self.i18n.error);
                        $btn.prop('disabled', false).text($btn.data('original-label'));
                        return;
                    }

                    // 跳过和失败都要把原因显示出来，避免用户点了没反应
                    if (response.data.status === 'skipped' || response.data.status === 'failed') {
                        alert(response.data.message || self.i18n.error);
                        $btn.prop('disabled', false).text($btn.data('original-label'));
                        location.reload();
                        return;
                    }

                    // 刷新页面以显示新状态
                    location.reload();
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false).text($btn.data('original-label'));
                }
            });
        },

        /**
         * 显示单张图片的空间详情。
         */
        showDetails: function(attachmentId) {
            this.detailsDirty = false;
            this.refreshDetails(attachmentId);
        },

        /**
         * 重新拉取并画出详情弹窗
         *
         * 详情页里的删除、补生成都改动了磁盘文件，操作完必须按最新情况重画一遍。
         */
        refreshDetails: function(attachmentId) {
            var self = this;

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'local_crush_get_details',
                    nonce: this.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (!response.success || !response.data) {
                        alert((response.data && response.data.message) || self.i18n.error);
                        return;
                    }

                    self.renderDetailsModal(response.data);
                },
                error: function() {
                    alert(self.i18n.error);
                }
            });
        },

        /**
         * 安全转义详情弹窗里的文本。
         */
        escapeHtml: function(value) {
            return $('<div>').text(value == null ? '' : String(value)).html();
        },

        /**
         * 转义要放进 HTML 属性的值（引号也要转，否则会撑破属性）
         */
        escapeAttr: function(value) {
            return this.escapeHtml(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        },

        /**
         * 渲染详情弹窗。
         */
        renderDetailsModal: function(details) {
            var self = this;
            var i18n = this.i18n;
            var rows = details.items || [];
            var attachmentId = parseInt(details.attachment_id, 10) || 0;
            var html = '';

            html += '<div class="local-crush-details-modal" role="dialog" aria-modal="true">';
            html += '<div class="local-crush-details-dialog">';
            html += '<div class="local-crush-details-head">';
            html += '<h2>' + this.escapeHtml(i18n.detailsTitle) + '</h2>';
            html += '<button type="button" class="button-link local-crush-details-close">' + this.escapeHtml(i18n.detailsClose) + '</button>';
            html += '</div>';

            // 标题下的整图操作：作用范围是整张图片，逐行的删除按钮只管对应的那一份文件
            html += '<div class="local-crush-details-toolbar">';
            html += '<button type="button" class="button button-small local-crush-details-op" data-operation="generate-missing" data-attachment-id="' + attachmentId + '">' + this.escapeHtml(i18n.btnGenerateMissing) + '</button>';
            html += '<button type="button" class="button button-small local-crush-details-op" data-operation="delete-disabled" data-attachment-id="' + attachmentId + '">' + this.escapeHtml(i18n.btnDeleteDisabled) + '</button>';
            html += '<button type="button" class="button button-small local-crush-details-op" data-operation="delete-fallback" data-attachment-id="' + attachmentId + '">' + this.escapeHtml(i18n.deleteFallback) + '</button>';
            html += '<button type="button" class="button button-small local-crush-details-op" data-operation="delete-all" data-attachment-id="' + attachmentId + '">' + this.escapeHtml(i18n.btnDeleteAllSizes) + '</button>';
            html += '<label class="local-crush-switch">';
            html += '<input type="checkbox" data-attachment-id="' + attachmentId + '"' + (details.thumbnails_disabled ? ' checked' : '') + '>';
            html += '<span class="local-crush-switch-track"><span class="local-crush-switch-thumb"></span></span>';
            html += '<span class="local-crush-switch-text">' + this.escapeHtml(i18n.toggleNoThumbnails) + '</span>';
            html += '</label>';
            html += '</div>';

            html += '<table class="local-crush-details-table"><thead><tr>';
            html += '<th>' + this.escapeHtml(i18n.detailsGroup) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsItem) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsFormat) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsStatus) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsOriginalFileSize) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsCurrentSize) + '</th>';
            html += '<th>' + this.escapeHtml(i18n.detailsSavedSize) + '</th>';
            html += '<th class="local-crush-details-actions">' + this.escapeHtml(i18n.detailsActions) + '</th>';
            html += '</tr></thead><tbody>';

            if (!rows.length) {
                html += '<tr><td colspan="8">' + this.escapeHtml(i18n.detailsEmpty) + '</td></tr>';
            } else {
                $.each(rows, function(index, row) {
                    // 普通主图不能删；主图是 -scaled 缩放图时可以删，删了主图退回未缩放原图。
                    // 原图备份、缩略图尺寸、未缩放原图、旧兼容格式各行都能单独删；
                    // 旧兼容格式只删自己那一份，新格式留着。
                    var canDelete = row.is_fallback || row.scaled_main || row.size_type !== 'full';
                    var rowOperation = 'delete-size';

                    if (row.size_type === 'backup') {
                        rowOperation = 'delete-backup';
                    } else if (row.is_fallback) {
                        rowOperation = 'delete-fallback-size';
                    } else if (row.size_type === 'original_image') {
                        rowOperation = 'delete-original';
                    } else if (row.scaled_main) {
                        rowOperation = 'delete-scaled-main';
                    }

                    html += '<tr>';
                    html += '<td>' + self.escapeHtml(row.group) + '</td>';
                    html += '<td>' + self.escapeHtml(row.name);
                    if (row.file_name) {
                        html += '<br><small>' + self.escapeHtml(row.file_name) + '</small>';
                    }
                    html += '</td>';
                    html += '<td>' + self.escapeHtml(row.format) + '</td>';
                    html += '<td>' + self.escapeHtml(row.status) + '</td>';
                    html += '<td>' + self.escapeHtml(self.formatBytes(row.original_size)) + '</td>';
                    html += '<td>' + self.escapeHtml(self.formatBytes(row.current_size)) + '</td>';
                    html += '<td>' + self.escapeHtml(self.formatBytes(row.saved_size)) + '</td>';
                    html += '<td class="local-crush-details-actions">';

                    if (canDelete) {
                        html += '<button type="button" class="button-link local-crush-size-delete"'
                            + ' data-attachment-id="' + attachmentId + '"'
                            + ' data-operation="' + rowOperation + '"'
                            + ' data-size="' + self.escapeAttr(row.size_type) + '"'
                            + ' data-label="' + self.escapeAttr(row.name) + '">'
                            + self.escapeHtml(i18n.detailsDelete)
                            + '</button>';
                    }

                    html += '</td>';
                    html += '</tr>';
                });
            }

            html += '</tbody></table></div></div>';

            $('.local-crush-details-modal').remove();
            $('body').append(html);

            var $modal = $('.local-crush-details-modal').last();

            function closeModal() {
                $modal.remove();
                $(document).off('keydown.localCrushDetails');

                // 删过文件就刷新列表，让“占用”和压缩状态跟上磁盘的实际情况
                if (self.detailsDirty) {
                    self.detailsDirty = false;
                    location.reload();
                }
            }

            $modal.on('click', function(e) {
                if (e.target === this || $(e.target).hasClass('local-crush-details-close')) {
                    closeModal();
                }
            });
            $(document).off('keydown.localCrushDetails').on('keydown.localCrushDetails', function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                }
            });
        },

        /**
         * 删除某一行的图片
         *
         * 当前格式的缩略图那行删整个尺寸（旧格式一起没了）；未缩放原图那行删这份原图；
         * 旧格式那行只删自己那一份。
         */
        handleSizeDelete: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var attachmentId = parseInt($btn.attr('data-attachment-id'), 10) || 0;
            var sizeName = $btn.attr('data-size');
            var label = $btn.attr('data-label');
            var operation = $btn.attr('data-operation') || 'delete-size';

            if (!attachmentId || !sizeName) {
                return;
            }

            var confirmText = this.i18n.confirmDeleteSize.replace('%s', label || sizeName);

            if (operation === 'delete-fallback-size') {
                confirmText = this.i18n.confirmDeleteFallbackSize.replace('%s', label || sizeName);
            } else if (operation === 'delete-original') {
                confirmText = this.i18n.confirmDeleteOriginalSingle;
            } else if (operation === 'delete-backup') {
                confirmText = this.i18n.confirmDeleteBackup;
            } else if (operation === 'delete-scaled-main') {
                confirmText = this.i18n.confirmDeleteScaledMain;
            }

            this.runDetailsAction(attachmentId, { operation: operation, size: sizeName }, confirmText, $btn);
        },

        /**
         * 详情弹窗标题下的整图操作
         */
        handleDetailsOperation: function(e) {
            e.preventDefault();

            var $btn = $(e.currentTarget);
            var attachmentId = parseInt($btn.attr('data-attachment-id'), 10) || 0;
            var operation = $btn.attr('data-operation');

            if (!attachmentId) {
                return;
            }

            // 补生成不是删除，不问；删除未勾选尺寸、删除全部尺寸和删除兼容格式回退都动磁盘文件，先让用户确认
            var confirmText = '';

            if (operation === 'delete-disabled') {
                confirmText = this.i18n.confirmDeleteDisabledSingle;
            } else if (operation === 'delete-all') {
                confirmText = this.i18n.confirmDeleteAllSizes;
            } else if (operation === 'delete-fallback') {
                confirmText = this.i18n.confirmDeleteFallback;
            }

            this.runDetailsAction(attachmentId, { operation: operation }, confirmText, $btn);
        },

        /**
         * 单图开关：这张图片以后还生不生成缩略图
         */
        handleNoThumbnailsSwitch: function(e) {
            var self = this;
            var $input = $(e.currentTarget);
            var attachmentId = parseInt($input.attr('data-attachment-id'), 10) || 0;
            var disabled = $input.is(':checked');

            if (!attachmentId) {
                return;
            }

            $input.prop('disabled', true);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'local_crush_attachment_action',
                    nonce: this.nonce,
                    attachment_id: attachmentId,
                    operation: 'set-no-thumbnails',
                    disabled: disabled ? 1 : 0
                },
                success: function(response) {
                    if (!response.success) {
                        alert((response.data && response.data.message) || self.i18n.error);
                        // 没保存成功就把开关拨回原位，别让界面显示错的状态
                        $input.prop('checked', !disabled);
                    }

                    $input.prop('disabled', false);
                },
                error: function() {
                    alert(self.i18n.error);
                    $input.prop('checked', !disabled).prop('disabled', false);
                }
            });
        },

        /**
         * 详情弹窗里的单图操作统一走这里
         *
         * 服务端只认操作名和附件 ID，这里只负责确认、发请求、重画弹窗。
         */
        runDetailsAction: function(attachmentId, data, confirmText, $btn) {
            var self = this;
            var request = $.extend(
                {
                    action: 'local_crush_attachment_action',
                    nonce: this.nonce,
                    attachment_id: attachmentId
                },
                data
            );

            if (confirmText && !confirm(confirmText)) {
                return;
            }

            if ($btn && $btn.length) {
                $btn.prop('disabled', true);
            }

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                timeout: 120000,
                data: request,
                success: function(response) {
                    if (!response.success) {
                        alert((response.data && response.data.message) || self.i18n.error);

                        if ($btn && $btn.length) {
                            $btn.prop('disabled', false);
                        }
                        return;
                    }

                    self.detailsDirty = true;
                    self.refreshDetails(attachmentId);
                },
                error: function() {
                    alert(self.i18n.error);

                    if ($btn && $btn.length) {
                        $btn.prop('disabled', false);
                    }
                }
            });
        },

        /**
         * 格式化详情中的文件大小。
         */
        formatBytes: function(bytes) {
            var value = Math.max(0, parseInt(bytes, 10) || 0);
            var units = ['B', 'KB', 'MB', 'GB', 'TB'];
            var index = 0;

            while (value >= 1024 && index < units.length - 1) {
                value = value / 1024;
                index++;
            }

            if (index === 0) {
                return value + units[index];
            }

            return parseFloat(value.toFixed(2)) + units[index];
        },

        /**
         * 恢复单张图片
         */
        restoreSingle: function(attachmentId, $btn) {
            var self = this;

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'local_crush_restore',
                    nonce: this.nonce,
                    attachment_id: attachmentId
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message || self.i18n.error);
                        $btn.prop('disabled', false).text($btn.data('original-label'));
                    }
                },
                error: function() {
                    alert(self.i18n.error);
                    $btn.prop('disabled', false).text($btn.data('original-label'));
                }
            });
        },

        // ===== 批量任务 =====
        // 真正干活的是服务器（见 includes/class-task.php），页面只负责把进度画出来。
        // 所以关掉浏览器任务照样继续，重新打开这个页面还能接着看进度。

        /**
         * 页面载入时按服务端给的状态恢复面板
         */
        initTaskPanel: function() {
            this.$panel = $('#local-crush-task');

            if (!this.$panel.length) {
                this.$panel = null;
                return;
            }

            if (this.taskState && this.taskState.has_task) {
                this.renderTask(this.taskState);

                if (this.taskState.status === 'running') {
                    this.startPolling();
                }
            } else {
                this.$panel.hide();
            }
        },

        /**
         * 开始一个批量任务
         */
        startTask: function(type, confirmText) {
            if (confirmText && !confirm(confirmText)) {
                return;
            }

            var self = this;

            this.lockBatchButtons(true);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                timeout: 60000,
                data: {
                    action: 'local_crush_task_start',
                    nonce: this.nonce,
                    type: type
                },
                success: function(response) {
                    self.lockBatchButtons(false);

                    if (!response.success) {
                        alert((response.data && response.data.message) || self.i18n.error);
                        return;
                    }

                    self.renderTask(response.data);
                    self.startPolling();
                    self.scrollToTaskPanel();
                },
                error: function() {
                    self.lockBatchButtons(false);
                    alert(self.i18n.error);
                }
            });
        },

        /**
         * 面板一亮相就滚到页面最底下，省得每次都手动往下滑
         */
        scrollToTaskPanel: function() {
            if (!this.$panel || !this.$panel.length) {
                return;
            }

            window.requestAnimationFrame(function() {
                window.scrollTo({
                    top: document.documentElement.scrollHeight,
                    behavior: 'smooth'
                });
            });
        },

        /**
         * 停止任务
         */
        stopTask: function(e) {
            if (e) {
                e.preventDefault();
            }

            if (!confirm(this.i18n.taskStopConfirm)) {
                return;
            }

            this.taskAction('local_crush_task_stop');
        },

        /**
         * 继续执行：页面轮询本来就会补一棒，这里手动催一下并立刻刷新状态
         */
        resumeTask: function(e) {
            if (e) {
                e.preventDefault();
            }

            this.taskAction('local_crush_task_status');
        },

        /**
         * 只重跑失败的项目
         */
        retryTask: function(e) {
            if (e) {
                e.preventDefault();
            }

            this.taskAction('local_crush_task_retry');
        },

        /**
         * 关掉结果面板（服务端直接删掉任务工单，刷新页面也不会再冒出来）
         */
        dismissTask: function(e) {
            if (e) {
                e.preventDefault();
            }

            var self = this;

            this.stopPolling();

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                timeout: 30000,
                data: {
                    action: 'local_crush_task_dismiss',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (!response.success) {
                        alert((response.data && response.data.message) || self.i18n.error);
                        return;
                    }

                    self.taskState = { has_task: false };

                    if (self.$panel) {
                        self.$panel.hide();
                    }

                    self.lockBatchButtons(false);
                },
                error: function() {
                    alert(self.i18n.error);
                }
            });
        },

        /**
         * 发送一个任务操作请求
         */
        taskAction: function(action) {
            var self = this;

            this.stopPolling();

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                timeout: 60000,
                data: {
                    action: action,
                    nonce: this.nonce
                },
                success: function(response) {
                    if (!response.success) {
                        alert((response.data && response.data.message) || self.i18n.error);

                        if (self.taskState && self.taskState.status === 'running') {
                            self.startPolling();
                        }
                        return;
                    }

                    self.renderTask(response.data);

                    if (response.data && response.data.status === 'running') {
                        self.startPolling();
                    }
                },
                error: function() {
                    alert(self.i18n.error);

                    if (self.taskState && self.taskState.status === 'running') {
                        self.startPolling();
                    }
                }
            });
        },

        /**
         * 开始轮询任务状态
         */
        startPolling: function() {
            var self = this;

            this.stopPolling();

            this.taskTimer = setTimeout(function() {
                self.pollStatus();
            }, 2000);
        },

        /**
         * 停止轮询
         */
        stopPolling: function() {
            if (this.taskTimer) {
                clearTimeout(this.taskTimer);
                this.taskTimer = null;
            }
        },

        /**
         * 取一次任务状态
         */
        pollStatus: function() {
            var self = this;

            this.taskTimer = null;

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                timeout: 60000,
                data: {
                    action: 'local_crush_task_status',
                    nonce: this.nonce
                },
                success: function(response) {
                    if (!response.success) {
                        self.pollFailed();
                        return;
                    }

                    self.taskErrors = 0;
                    self.renderTask(response.data);

                    if (response.data && response.data.status === 'running') {
                        self.startPolling();
                    }
                },
                error: function() {
                    self.pollFailed();
                }
            });
        },

        /**
         * 一次状态查询失败：连着失败太多次就别再空转，免得一直打服务器
         */
        pollFailed: function() {
            this.taskErrors++;

            if (this.taskErrors < 5) {
                this.startPolling();
                return;
            }

            this.showPanelNote(this.i18n.error);
        },

        /**
         * 画出任务面板
         */
        renderTask: function(state) {
            if (!this.$panel || !state || !state.has_task) {
                if (this.$panel) {
                    this.$panel.hide();
                }
                // 任务被另一个标签页关掉后，这里的批量按钮也要恢复可用。
                this.lockBatchButtons(false);
                return;
            }

            this.taskState = state;
            this.$panel.show();
            this.$panel.toggleClass('is-done', state.status === 'done');
            this.$panel.toggleClass('is-stopped', state.status === 'stopped');

            var percent = Math.max(0, Math.min(100, parseInt(state.percent, 10) || 0));
            var progress = (state.scanned ? this.i18n.taskScanned : this.i18n.taskProgress)
                .replace('%1$d', state.progress)
                .replace('%2$d', state.total);
            var counts = this.i18n.taskCounts
                .replace('%1$d', state.success)
                .replace('%2$d', state.skipped)
                .replace('%3$d', state.failed);
            var elapsed = this.i18n.taskElapsed.replace('%s', this.formatDuration(state.elapsed));
            var current = state.current ? this.i18n.taskCurrent.replace('%s', state.current) : '';

            if (state.stalled && state.status === 'running') {
                current = this.i18n.taskStalled;
            }

            this.$panel.find('.task-label').text(state.label || '');
            this.$panel.find('.task-status').text(this.statusText(state));
            this.$panel.find('.task-bar-fill').css('width', percent + '%');
            this.$panel.find('.task-bar-text').text(percent + '%');
            this.$panel.find('.task-progress').text(progress + '　' + elapsed);
            this.$panel.find('.task-counts').text(counts);
            this.$panel.find('.task-current').text(current);
            this.$panel.find('.task-summary').text(state.summary || '');

            var running = state.status === 'running';

            this.$panel.find('#local-crush-task-stop').toggle(running);
            this.$panel.find('#local-crush-task-resume').toggle(running && !!state.stalled);
            this.$panel.find('#local-crush-task-dismiss').toggle(!running);

            var retryCount = parseInt(state.retry_count, 10) || 0;
            var $retry = this.$panel.find('#local-crush-task-retry');

            $retry.toggle(!running && retryCount > 0);

            if (retryCount > 0) {
                $retry.text(this.i18n.taskRetry.replace('%d', retryCount));
            }

            this.lockBatchButtons(running);
        },

        /**
         * 面板上的一行提示（轮询彻底失败时用）
         */
        showPanelNote: function(text) {
            if (!this.$panel) {
                return;
            }

            this.$panel.show();
            this.$panel.find('.task-current').text(text);
        },

        /**
         * 任务状态文字
         */
        statusText: function(state) {
            if (state.status === 'done') {
                return this.i18n.taskDone;
            }

            if (state.status === 'stopped') {
                return this.i18n.taskStopped;
            }

            return state.stalled ? this.i18n.taskStalled : this.i18n.taskRunning;
        },

        /**
         * 任务跑着的时候禁掉所有批量按钮，避免重复启动
         */
        lockBatchButtons: function(disabled) {
            $.each(this.batchButtons, function(selector) {
                $(selector).prop('disabled', !!disabled);
            });
        },

        /**
         * 秒数转“几分几秒”
         */
        formatDuration: function(seconds) {
            var value = Math.max(0, parseInt(seconds, 10) || 0);

            if (value < 60) {
                return this.i18n.taskSeconds.replace('%d', value);
            }

            return this.i18n.taskMinutes
                .replace('%1$d', Math.floor(value / 60))
                .replace('%2$d', value % 60);
        }
    };

    // DOM 加载完成后初始化
    $(document).ready(function() {
        LC.init();
    });

})(jQuery);
