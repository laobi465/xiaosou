/* ============================================
   后台管理公共脚本
   - AJAX 封装 (fetch)
   - CRUD 操作 / 确认弹窗 / Toast
   - 表单 AJAX 提交拦截
   - 自动绑定 data-action 元素
   美学方向：新拟物柔和 - 微交互增强
   ============================================ */
(function () {
    'use strict';

    var Admin = {
        /* ---- Toast 通知 (增强：入场动画 + CSS 图标) ---- */
        toast: function (message, type) {
            type = type || 'info';
            var wrap = document.querySelector('.toast-wrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.className = 'toast-wrap';
                document.body.appendChild(wrap);
            }
            var el = document.createElement('div');
            el.className = 'toast toast-' + type;
            var iconHtml = '';
            if (type === 'success') {
                iconHtml = '<span class="toast-icon toast-icon-success" aria-hidden="true"></span>';
            } else if (type === 'error') {
                iconHtml = '<span class="toast-icon toast-icon-error" aria-hidden="true"></span>';
            } else {
                iconHtml = '<span class="toast-icon toast-icon-info" aria-hidden="true"></span>';
            }
            el.innerHTML = iconHtml + '<span class="toast-text">' + escapeHtml(message) + '</span>';
            wrap.appendChild(el);
            requestAnimationFrame(function () { el.classList.add('show'); });
            setTimeout(function () {
                el.classList.remove('show');
                el.classList.add('hide');
                setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 250);
            }, 2500);
        },

        /* ---- 确认弹窗 (Promise, 增强模态框动画) ---- */
        confirm: function (message) {
            return new Promise(function (resolve) {
                var mask = document.createElement('div');
                mask.className = 'modal-mask';
                mask.innerHTML =
                    '<div class="modal-box modal-confirm">' +
                    '<div class="modal-header">' + escapeHtml(message || '确认执行此操作？') + '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn modal-cancel">取消</button>' +
                    '<button type="button" class="btn btn-primary modal-ok">确定</button>' +
                    '</div>' +
                    '</div>';
                document.body.appendChild(mask);
                requestAnimationFrame(function () { mask.classList.add('show'); });

                function close(val) {
                    mask.classList.remove('show');
                    mask.classList.add('hide');
                    setTimeout(function () { if (mask.parentNode) mask.parentNode.removeChild(mask); }, 200);
                    resolve(val);
                }
                mask.querySelector('.modal-cancel').addEventListener('click', function () { close(false); });
                mask.querySelector('.modal-ok').addEventListener('click', function () { close(true); });
                mask.addEventListener('click', function (e) {
                    if (e.target === mask) close(false);
                });
                // 自动聚焦确定按钮
                setTimeout(function () { mask.querySelector('.modal-ok').focus(); }, 50);
            });
        },

        /* ---- 输入弹窗 (Promise, 返回输入值或 null) ---- */
        prompt: function (title, placeholder, opts) {
            opts = opts || {};
            return new Promise(function (resolve) {
                var mask = document.createElement('div');
                mask.className = 'modal-mask';
                mask.innerHTML =
                    '<div class="modal-box">' +
                    '<div class="modal-header">' + escapeHtml(title || '请输入') + '</div>' +
                    '<div class="modal-body">' +
                    '<textarea class="modal-textarea" placeholder="' + escapeHtml(placeholder || '') + '" rows="4"></textarea>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn modal-cancel">取消</button>' +
                    '<button type="button" class="btn btn-primary modal-ok">确定</button>' +
                    '</div>' +
                    '</div>';
                document.body.appendChild(mask);
                requestAnimationFrame(function () { mask.classList.add('show'); });

                var textarea = mask.querySelector('.modal-textarea');
                if (opts.required === false) { /* 允许空 */ }
                setTimeout(function () { textarea.focus(); }, 50);

                function close(val) {
                    mask.classList.remove('show');
                    mask.classList.add('hide');
                    setTimeout(function () { if (mask.parentNode) mask.parentNode.removeChild(mask); }, 200);
                    resolve(val);
                }
                mask.querySelector('.modal-cancel').addEventListener('click', function () { close(null); });
                mask.querySelector('.modal-ok').addEventListener('click', function () {
                    var val = textarea.value.trim();
                    if (opts.required !== false && val === '') {
                        Admin.toast(opts.emptyTip || '内容不能为空', 'error');
                        return;
                    }
                    close(val);
                });
                // Ctrl+Enter 提交
                textarea.addEventListener('keydown', function (e) {
                    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                        mask.querySelector('.modal-ok').click();
                    }
                });
                mask.addEventListener('click', function (e) {
                    if (e.target === mask) close(null);
                });
            });
        },

        /* ---- AJAX 请求封装 ---- */
        request: function (url, options) {
            options = options || {};
            var method = (options.method || 'POST').toUpperCase();
            var headers = options.headers || {};
            headers['X-Requested-With'] = 'XMLHttpRequest';

            var fetchOpts = { method: method, headers: headers, credentials: 'same-origin' };

            if (method !== 'GET' && method !== 'HEAD') {
                if (options.data instanceof FormData) {
                    fetchOpts.body = options.data;
                } else if (options.data) {
                    headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
                    fetchOpts.body = buildQuery(options.data);
                }
            }

            return fetch(url, fetchOpts).then(function (res) {
                return res.json().catch(function () {
                    return { code: 1, message: '响应解析失败', data: {} };
                });
            });
        },

        /* ---- 处理 JSON 响应 ---- */
        handleResponse: function (res, redirectUrl) {
            if (res && res.code === 0) {
                Admin.toast(res.message || '操作成功', 'success');
                var target = (res.data && res.data.url) || redirectUrl || '';
                if (target) {
                    setTimeout(function () { window.location.href = target; }, 700);
                } else {
                    setTimeout(function () { window.location.reload(); }, 700);
                }
                return true;
            }
            Admin.toast((res && res.message) || '操作失败', 'error');
            return false;
        },

        /* ---- 简单 AJAX POST (带确认) ---- */
        post: function (url, data, redirectUrl, confirmMsg) {
            var run = function () {
                Admin.request(url, { method: 'POST', data: data }).then(function (res) {
                    Admin.handleResponse(res, redirectUrl);
                }).catch(function () { Admin.toast('网络错误，请重试', 'error'); });
            };
            if (confirmMsg) {
                Admin.confirm(confirmMsg).then(function (ok) { if (ok) run(); });
            } else {
                run();
            }
        }
    };

    /* ---- 工具函数 ---- */
    function escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function buildQuery(data) {
        if (typeof data === 'string') return data;
        var parts = [];
        for (var key in data) {
            if (!Object.prototype.hasOwnProperty.call(data, key)) continue;
            var val = data[key];
            if (val == null) continue;
            if (Array.isArray(val)) {
                val.forEach(function (v) { parts.push(encodeURIComponent(key) + '[]=' + encodeURIComponent(v)); });
            } else {
                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(val));
            }
        }
        return parts.join('&');
    }

    window.Admin = Admin;

    /* ---- DOM 就绪后自动绑定 ---- */
    document.addEventListener('DOMContentLoaded', function () {
        highlightSidebar();
        bindConfirmAjax();
        bindAjaxForm();
        bindAjaxPost();
        bindPromptAjax();
        initTableHover();
        initSidebarToggle();
        initSubmitButtonLoading();
        initStaggeredReveal();
    });

    /* 侧边栏高亮当前菜单 */
    function highlightSidebar() {
        var path = window.location.pathname;
        var links = document.querySelectorAll('.sidebar-menu a');
        links.forEach(function (a) {
            var href = a.getAttribute('href') || '';
            if (href === '/admin' || href === '/admin/') {
                if (path === '/admin' || path === '/admin/') a.classList.add('active');
            } else if (path.indexOf(href) === 0 && href !== '/admin') {
                a.classList.add('active');
            }
        });
    }

    /* [data-action="confirm-ajax"] 确认后 AJAX POST */
    function bindConfirmAjax() {
        document.querySelectorAll('[data-action="confirm-ajax"]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                var url = el.getAttribute('data-url') || el.getAttribute('href');
                var method = el.getAttribute('data-method') || 'POST';
                var msg = el.getAttribute('data-confirm') || '确认执行此操作？';
                var redirect = el.getAttribute('data-redirect') || '';
                var params = el.getAttribute('data-params');

                Admin.confirm(msg).then(function (ok) {
                    if (!ok) return;
                    var data = params ? parseParams(params) : null;
                    Admin.request(url, { method: method, data: data }).then(function (res) {
                        Admin.handleResponse(res, redirect);
                    }).catch(function () { Admin.toast('网络错误', 'error'); });
                });
            });
        });
    }

    /* [data-action="ajax-post"] 直接 AJAX POST (无确认) */
    function bindAjaxPost() {
        document.querySelectorAll('[data-action="ajax-post"]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                var url = el.getAttribute('data-url') || el.getAttribute('href');
                var redirect = el.getAttribute('data-redirect') || '';
                var params = el.getAttribute('data-params');
                var data = params ? parseParams(params) : null;

                Admin.request(url, { method: 'POST', data: data }).then(function (res) {
                    Admin.handleResponse(res, redirect);
                }).catch(function () { Admin.toast('网络错误', 'error'); });
            });
        });
    }

    /* [data-action="ajax-form"] 表单 AJAX 提交 */
    function bindAjaxForm() {
        document.querySelectorAll('[data-action="ajax-form"]').forEach(function (form) {
            if (form.tagName !== 'FORM') return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var url = form.getAttribute('action') || window.location.href;
                var redirect = form.getAttribute('data-redirect') || '';
                var formData = new FormData(form);

                var btn = form.querySelector('[type="submit"]');
                var originalText = btn ? btn.innerHTML : '';
                if (btn) { btn.disabled = true; btn.classList.add('is-loading'); btn.textContent = '提交中...'; }

                Admin.request(url, { method: 'POST', data: formData }).then(function (res) {
                    if (!Admin.handleResponse(res, redirect) && btn) {
                        btn.disabled = false; btn.classList.remove('is-loading'); btn.innerHTML = originalText;
                    }
                }).catch(function () {
                    Admin.toast('网络错误', 'error');
                    if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); btn.innerHTML = originalText; }
                });
            });
        });
    }

    /* [data-action="prompt-ajax"] 弹出输入框 → AJAX POST (用于驳回/退款等需填写原因) */
    function bindPromptAjax() {
        document.querySelectorAll('[data-action="prompt-ajax"]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                var url = el.getAttribute('data-url') || el.getAttribute('href');
                var title = el.getAttribute('data-prompt-title') || '请输入';
                var placeholder = el.getAttribute('data-prompt-placeholder') || '';
                var paramName = el.getAttribute('data-param-name') || 'reason';
                var redirect = el.getAttribute('data-redirect') || '';
                var confirmMsg = el.getAttribute('data-confirm') || '';

                Admin.prompt(title, placeholder, { required: true }).then(function (val) {
                    if (val === null) return;
                    var data = {};
                    data[paramName] = val;

                    var run = function () {
                        Admin.request(url, { method: 'POST', data: data }).then(function (res) {
                            Admin.handleResponse(res, redirect);
                        }).catch(function () { Admin.toast('网络错误', 'error'); });
                    };

                    if (confirmMsg) {
                        Admin.confirm(confirmMsg).then(function (ok) { if (ok) run(); });
                    } else {
                        run();
                    }
                });
            });
        });
    }

    function parseParams(str) {
        var obj = {};
        str.split('&').forEach(function (pair) {
            var idx = pair.indexOf('=');
            if (idx > -1) {
                obj[decodeURIComponent(pair.slice(0, idx))] = decodeURIComponent(pair.slice(idx + 1));
            }
        });
        return obj;
    }

    /* ---- 微交互增强：表格行 hover ---- */
    function initTableHover() {
        var rows = document.querySelectorAll('.table tbody tr, .data-table tbody tr');
        rows.forEach(function (row) {
            row.addEventListener('mouseenter', function () { row.classList.add('is-hover'); });
            row.addEventListener('mouseleave', function () { row.classList.remove('is-hover'); });
        });
    }

    /* ---- 微交互增强：移动端侧边栏抽屉切换 ---- */
    function initSidebarToggle() {
        var toggle = document.querySelector('.sidebar-toggle');
        var sidebar = document.querySelector('.admin-sidebar');
        var overlay = document.querySelector('.sidebar-overlay');
        if (!toggle || !sidebar) return;

        function openSidebar() {
            sidebar.classList.add('is-open');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
            }
            overlay.classList.add('show');
            overlay.addEventListener('click', closeSidebar, { once: true });
        }
        function closeSidebar() {
            sidebar.classList.remove('is-open');
            if (overlay) overlay.classList.remove('show');
        }
        toggle.addEventListener('click', function () {
            if (sidebar.classList.contains('is-open')) closeSidebar();
            else openSidebar();
        });
    }

    /* ---- 微交互增强：表单提交按钮加载状态 ---- */
    function initSubmitButtonLoading() {
        var forms = document.querySelectorAll('form[data-action="ajax-form"], form.form-panel');
        forms.forEach(function (form) {
            var btn = form.querySelector('[type="submit"]');
            if (!btn || btn.hasAttribute('data-loading-bound')) return;
            btn.setAttribute('data-loading-bound', '1');
            form.addEventListener('submit', function () {
                if (btn.disabled) return;
                btn.classList.add('is-loading');
                btn.disabled = true;
                var original = btn.innerHTML;
                btn.setAttribute('data-original-html', original);
                btn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span>提交中...</span>';
            });
        });
    }

    /* ---- 微交互增强：staggered 入场动画 ---- */
    function initStaggeredReveal() {
        var items = document.querySelectorAll('[data-reveal]');
        items.forEach(function (item, idx) {
            item.style.animationDelay = (idx * 50) + 'ms';
            item.classList.add('reveal-item');
            requestAnimationFrame(function () { item.classList.add('reveal-in'); });
        });
    }
})();
