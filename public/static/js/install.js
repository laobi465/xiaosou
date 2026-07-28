/* ============================================================
   安装向导交互脚本 (原生 ES6, 无框架依赖)
   依赖：fetch / Promise (现代浏览器原生支持)
   ============================================================ */
(function () {
    'use strict';

    var currentStep = 1;
    var TOTAL_STEPS = 5;

    /* ---------- 通用 AJAX: fetch POST JSON ---------- */
    async function ajaxPost(url, data) {
        try {
            var resp = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify(data || {})
            });
            var json;
            try {
                json = await resp.json();
            } catch (e) {
                json = { code: 1, message: '响应解析失败', data: {} };
            }
            return json;
        } catch (e) {
            return { code: 1, message: '网络错误，请重试', data: {} };
        }
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /* ---------- 步骤切换 ---------- */
    function goToStep(n) {
        n = Math.max(1, Math.min(TOTAL_STEPS, n));
        currentStep = n;

        // 更新步骤指示器
        var items = document.querySelectorAll('.step-item');
        items.forEach(function (item) {
            var step = parseInt(item.getAttribute('data-step'), 10);
            item.classList.remove('active', 'done');
            if (step === n) {
                item.classList.add('active');
            } else if (step < n) {
                item.classList.add('done');
            }
        });

        // 更新步骤面板
        var panels = document.querySelectorAll('.step-panel');
        panels.forEach(function (panel) {
            panel.classList.remove('active');
        });
        var target = document.getElementById('step-' + n);
        if (target) {
            target.classList.add('active');
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /* ---------- 表单数据收集 ---------- */
    function getFormData(form) {
        var data = {};
        var inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(function (input) {
            if (input.name) {
                data[input.name] = input.value;
            }
        });
        return data;
    }

    /* ---------- 步骤 1：环境检测 ---------- */
    function envItemHtml(name, value, ok) {
        return '<div class="env-item">' +
            '<span class="env-status ' + (ok ? 'ok' : 'fail') + '" aria-hidden="true"></span>' +
            '<span class="env-item-name">' + escapeHtml(name) + '</span>' +
            '<span class="env-item-value">' + escapeHtml(value) + '</span>' +
            '</div>';
    }

    function showEnvLoading() {
        var box = document.getElementById('env-result');
        if (box) {
            box.innerHTML = '<div class="env-item">' +
                '<span class="env-status loading" aria-hidden="true"></span>' +
                '<span class="env-item-name">正在检测服务器环境...</span>' +
                '</div>';
        }
        var btnNext = document.getElementById('btn-next-1');
        if (btnNext) {
            btnNext.disabled = true;
        }
    }

    function renderEnvResult(data) {
        var box = document.getElementById('env-result');
        if (!box) return;

        var html = '';

        // PHP 版本
        var phpValue = data.php_version || '未知';
        if (!data.php_ok) {
            phpValue += '（需 >= 8.2.0）';
        }
        html += envItemHtml('PHP 版本', phpValue, data.php_ok);

        // MySQL 客户端
        html += envItemHtml(
            'MySQL 客户端',
            data.mysql_client ? '已安装' : '未安装',
            data.mysql_client
        );

        // PHP 扩展
        if (data.extensions && typeof data.extensions === 'object') {
            Object.keys(data.extensions).forEach(function (name) {
                html += envItemHtml(
                    'PHP 扩展 ' + name,
                    data.extensions[name] ? '已加载' : '未加载',
                    data.extensions[name]
                );
            });
        }

        // 目录可写性
        if (data.writable && typeof data.writable === 'object') {
            Object.keys(data.writable).forEach(function (dir) {
                html += envItemHtml(
                    '目录可写 ' + dir,
                    data.writable[dir] ? '可写' : '不可写',
                    data.writable[dir]
                );
            });
        }

        box.innerHTML = html;

        // 根据 all_ok 控制下一步按钮
        var btnNext = document.getElementById('btn-next-1');
        if (btnNext) {
            btnNext.disabled = !data.all_ok;
        }
    }

    async function checkEnv() {
        showEnvLoading();
        var res = await ajaxPost('/install/ajax/env', {});
        if (res.code === 0 && res.data) {
            renderEnvResult(res.data);
        } else {
            var box = document.getElementById('env-result');
            if (box) {
                box.innerHTML = '<div class="env-item">' +
                    '<span class="env-status fail" aria-hidden="true"></span>' +
                    '<span class="env-item-name">环境检测失败：' + escapeHtml(res.message || '未知错误') + '</span>' +
                    '</div>';
            }
            var btnNext = document.getElementById('btn-next-1');
            if (btnNext) {
                btnNext.disabled = true;
            }
        }
    }

    /* ---------- 按钮加载状态 ---------- */
    function setLoading(btn, loading) {
        if (!btn) return;
        if (loading) {
            if (btn.dataset.origText === undefined) {
                btn.dataset.origText = btn.textContent;
            }
            btn.classList.add('is-loading');
            btn.disabled = true;
            if (btn.classList.contains('btn-primary')) {
                btn.innerHTML = '<span class="btn-spinner"></span>处理中...';
            } else {
                btn.textContent = btn.dataset.origText + '...';
            }
        } else {
            if (btn.dataset.origText !== undefined) {
                if (btn.classList.contains('btn-primary')) {
                    btn.innerHTML = btn.dataset.origText;
                } else {
                    btn.textContent = btn.dataset.origText;
                }
                delete btn.dataset.origText;
            }
            btn.classList.remove('is-loading');
            btn.disabled = false;
        }
    }

    /* ---------- 步骤 5：安装进度控制 ---------- */
    var progressTimer = null;
    var PROGRESS_STAGES = ['env', 'writeenv', 'database', 'seed', 'admin', 'cache', 'check'];

    function markProgress(stage, state) {
        var el = document.querySelector('.progress-step[data-stage="' + stage + '"]');
        if (!el) return;
        el.classList.remove('active', 'done', 'error');
        if (state) {
            el.classList.add(state);
        }
    }

    function resetProgress() {
        document.querySelectorAll('.progress-step').forEach(function (el) {
            el.classList.remove('active', 'done', 'error');
        });
    }

    function markAllProgressDone() {
        document.querySelectorAll('.progress-step').forEach(function (el) {
            el.classList.remove('active', 'error');
            el.classList.add('done');
        });
    }

    // 模拟进度推进：每 800ms 将当前步骤标记为 done，下一项标记为 active
    function startSimulatedProgress() {
        var idx = 0;
        if (PROGRESS_STAGES[0]) {
            markProgress(PROGRESS_STAGES[0], 'active');
        }
        progressTimer = setInterval(function () {
            if (idx < PROGRESS_STAGES.length) {
                markProgress(PROGRESS_STAGES[idx], 'done');
                idx++;
                if (idx < PROGRESS_STAGES.length) {
                    markProgress(PROGRESS_STAGES[idx], 'active');
                }
            }
        }, 800);
    }

    function stopSimulatedProgress() {
        if (progressTimer) {
            clearInterval(progressTimer);
            progressTimer = null;
        }
    }

    /* ---------- 步骤 5：安装结果渲染 ---------- */
    function checkItemHtml(text, ok) {
        return '<div class="check-item">' +
            '<span class="env-status ' + (ok ? 'ok' : 'fail') + '" aria-hidden="true"></span>' +
            '<span>' + escapeHtml(text) + '</span>' +
            '</div>';
    }

    function renderInstallResult(data) {
        var box = document.getElementById('install-result');
        if (!box) return;

        var check = data.check || {};
        var tables = check.tables || {};
        var redisPing = check.redis_ping;
        var adminExists = check.admin_exists;
        var adminUser = check.admin_user || '';
        var warmup = check.warmup || {};

        var tablesOk = (tables.ok === true) ||
            (tables.actual !== undefined && tables.expected !== undefined &&
                tables.actual === tables.expected);
        var tablesText = '数据表 ' +
            (tables.actual !== undefined ? tables.actual : '?') + '/' +
            (tables.expected !== undefined ? tables.expected : '?');

        var redisSkipped = (warmup.skipped === true);
        var redisText, redisOk;
        if (redisSkipped) {
            redisText = 'Redis 连通（已跳过配置）';
            redisOk = true;
        } else {
            redisText = 'Redis 连通 (ping)';
            redisOk = (redisPing === true);
        }

        var adminText = '管理员账号 ' + adminUser;
        var adminOk = (adminExists === true);

        var html = '';

        html += '<h3>安装后自检报告</h3>';
        html += '<div class="check-list">';
        html += checkItemHtml(tablesText, tablesOk);
        html += checkItemHtml(redisText, redisOk);
        html += checkItemHtml(adminText, adminOk);
        // 缓存预热附加信息（非跳过时展示）
        if (!redisSkipped) {
            html += checkItemHtml(
                '缓存预热 网盘源 ' + (warmup.pan_sources !== undefined ? warmup.pan_sources : '?') + ' 条',
                warmup.pan_sources !== undefined ? warmup.pan_sources > 0 : false
            );
            html += checkItemHtml(
                '缓存预热 敏感词 ' + (warmup.sensitive_words !== undefined ? warmup.sensitive_words : '?') + ' 条',
                warmup.sensitive_words !== undefined ? warmup.sensitive_words > 0 : false
            );
        }
        html += '</div>';

        if (data.next_steps) {
            html += '<h3>后续步骤</h3>';
            html += '<pre class="next-steps">' + escapeHtml(data.next_steps) + '</pre>';
        }

        html += '<div class="result-actions">';
        html += '<a class="btn btn-primary" href="/">访问首页</a>';
        html += '<a class="btn" href="/admin/login">进入后台</a>';
        html += '</div>';

        box.innerHTML = html;
        box.style.display = 'block';
    }

    /* ---------- 事件绑定 ---------- */
    function bindEvents() {
        // 通用：上一步
        document.querySelectorAll('[data-prev]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                goToStep(currentStep - 1);
            });
        });

        // 步骤 1：重新检测
        var btnRecheck = document.getElementById('btn-recheck');
        if (btnRecheck) {
            btnRecheck.addEventListener('click', checkEnv);
        }

        // 步骤 2：测试数据库
        var btnTestDb = document.getElementById('btn-test-db');
        if (btnTestDb) {
            btnTestDb.addEventListener('click', async function () {
                var form = document.getElementById('form-database');
                if (form && !form.checkValidity()) {
                    form.reportValidity();
                    return;
                }
                setLoading(btnTestDb, true);
                var data = form ? getFormData(form) : {};
                var res = await ajaxPost('/install/ajax/database', data);
                setLoading(btnTestDb, false);
                if (res.code === 0) {
                    goToStep(3);
                } else {
                    alert(res.message || '数据库连接失败');
                }
            });
        }

        // 步骤 3：测试 Redis
        var btnTestRedis = document.getElementById('btn-test-redis');
        if (btnTestRedis) {
            btnTestRedis.addEventListener('click', async function () {
                var form = document.getElementById('form-redis');
                setLoading(btnTestRedis, true);
                var data = form ? getFormData(form) : {};
                var res = await ajaxPost('/install/ajax/redis', data);
                setLoading(btnTestRedis, false);
                if (res.code === 0) {
                    goToStep(4);
                } else {
                    alert(res.message || 'Redis 连接失败');
                }
            });
        }

        // 步骤 3：跳过 Redis
        var btnSkipRedis = document.getElementById('btn-skip-redis');
        if (btnSkipRedis) {
            btnSkipRedis.addEventListener('click', async function () {
                setLoading(btnSkipRedis, true);
                var res = await ajaxPost('/install/ajax/redis', { skip: true });
                setLoading(btnSkipRedis, false);
                if (res.code === 0) {
                    goToStep(4);
                } else {
                    alert(res.message || '操作失败');
                }
            });
        }

        // 步骤 4：保存配置
        var btnSaveConfig = document.getElementById('btn-save-config');
        if (btnSaveConfig) {
            btnSaveConfig.addEventListener('click', async function () {
                var form = document.getElementById('form-config');
                if (form && !form.checkValidity()) {
                    form.reportValidity();
                    return;
                }
                setLoading(btnSaveConfig, true);
                var data = form ? getFormData(form) : {};
                var res = await ajaxPost('/install/ajax/config', data);
                setLoading(btnSaveConfig, false);
                if (res.code === 0) {
                    goToStep(5);
                } else {
                    alert(res.message || '保存失败');
                }
            });
        }

        // 步骤 5：执行安装
        var btnRun = document.getElementById('btn-run-install');
        if (btnRun) {
            btnRun.addEventListener('click', async function () {
                // 重置进度与结果
                resetProgress();
                var resultBox = document.getElementById('install-result');
                if (resultBox) {
                    resultBox.style.display = 'none';
                    resultBox.innerHTML = '';
                }

                setLoading(btnRun, true);
                startSimulatedProgress();

                var res = await ajaxPost('/install/ajax/run', {});

                stopSimulatedProgress();

                if (res.code === 0 && res.data) {
                    markAllProgressDone();
                    renderInstallResult(res.data);
                    // 安装完成：禁用按钮，更新文案
                    btnRun.disabled = true;
                    btnRun.classList.remove('btn-has-arrow');
                    btnRun.textContent = '安装完成';
                } else {
                    // 标记当前激活步骤为错误
                    var activeEl = document.querySelector('.progress-step.active');
                    if (activeEl) {
                        activeEl.classList.remove('active');
                        activeEl.classList.add('error');
                    }
                    setLoading(btnRun, false);
                    alert(res.message || '安装失败');
                }
            });
        }
    }

    /* ---------- 初始化 ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        bindEvents();
        // 页面加载后自动开始环境检测
        checkEnv();
    });
})();
