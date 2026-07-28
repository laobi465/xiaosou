/* ============================================================
   网盘资源搜索 - 前台公共脚本 (原生 ES6, 无框架)
   ============================================================ */
(function () {
    'use strict';

    /* ---------- CSRF Token 读取(可选, 若页面存在 meta 则注入) ---------- */
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    /* ---------- 通用 AJAX 封装 (fetch POST JSON) ---------- */
    async function ajaxPost(url, data) {
        var headers = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        var token = getCsrfToken();
        if (token) { headers['X-CSRF-Token'] = token; }
        var resp = await fetch(url, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            body: JSON.stringify(data || {})
        });
        // 401 未登录 → 跳登录页
        if (resp.status === 401) {
            toast('请先登录', 'error');
            setTimeout(function () {
                location.href = '/auth/login?redirect=' + encodeURIComponent(location.pathname + location.search);
            }, 800);
            return { code: 1002, message: '请先登录', data: {} };
        }
        var json;
        try { json = await resp.json(); }
        catch (e) { json = { code: resp.ok ? 0 : 1, message: '响应解析失败', data: {} }; }
        return json;
    }

    async function ajaxGet(url) {
        var resp = await fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (resp.status === 401) {
            location.href = '/auth/login?redirect=' + encodeURIComponent(location.pathname + location.search);
            return { code: 1002, message: '请先登录', data: {} };
        }
        var json;
        try { json = await resp.json(); }
        catch (e) { json = { code: resp.ok ? 0 : 1, message: '响应解析失败', data: {} }; }
        return json;
    }

    /* ---------- Toast 提示 ---------- */
    function ensureToastWrap() {
        var wrap = document.querySelector('.toast-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'toast-wrap';
            document.body.appendChild(wrap);
        }
        return wrap;
    }

    function toast(message, type) {
        var wrap = ensureToastWrap();
        var el = document.createElement('div');
        el.className = 'toast' + (type ? ' toast-' + type : '');
        el.textContent = message;
        wrap.appendChild(el);
        // 触发显示动画
        requestAnimationFrame(function () { el.classList.add('show'); });
        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 250);
        }, 2600);
    }

    /* ---------- 验证码倒计时 ---------- */
    function startCountdown(btn, seconds) {
        seconds = seconds || 60;
        var left = seconds;
        var originalText = btn.getAttribute('data-original-text') || btn.textContent;
        btn.setAttribute('data-original-text', originalText);
        btn.disabled = true;
        btn.textContent = left + 's 后重发';
        var timer = setInterval(function () {
            left--;
            if (left <= 0) {
                clearInterval(timer);
                btn.disabled = false;
                btn.textContent = originalText;
            } else {
                btn.textContent = left + 's 后重发';
            }
        }, 1000);
        return timer;
    }

    /**
     * 绑定发送验证码按钮
     * @param {HTMLButtonElement} btn 发送按钮
     * @param {Function} getEmail 返回邮箱值的函数
     * @param {Number} type 验证码类型 1注册 2登录 3重置
     */
    function bindSendCode(btn, getEmail, type) {
        btn.addEventListener('click', async function () {
            var email = getEmail();
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                toast('请输入正确的邮箱', 'error');
                return;
            }
            btn.disabled = true;
            var oldText = btn.textContent;
            btn.textContent = '发送中...';
            try {
                var res = await ajaxPost('/ajax/auth/sendCode', { email: email, type: String(type) });
                if (res.code === 0) {
                    toast('验证码已发送', 'success');
                    startCountdown(btn, 60);
                } else {
                    toast(res.message || '发送失败', 'error');
                    btn.disabled = false;
                    btn.textContent = oldText;
                }
            } catch (e) {
                toast('网络异常,请重试', 'error');
                btn.disabled = false;
                btn.textContent = oldText;
            }
        });
    }

    /* ---------- 广告曝光上报 (fire-and-forget) ---------- */
    function trackAdImpression(id) {
        if (!id) { return; }
        ajaxPost('/ajax/adImpression/' + id, {}).catch(function () { /* 忽略 */ });
    }

    /* ---------- 自动初始化广告曝光 ---------- */
    function initAdImpressions() {
        var nodes = document.querySelectorAll('[data-ad-id]');
        nodes.forEach(function (node) {
            var id = node.getAttribute('data-ad-id');
            if (node.getAttribute('data-ad-tracked')) { return; }
            node.setAttribute('data-ad-tracked', '1');
            trackAdImpression(id);
        });
    }

    /* ---------- 暴露到全局 ---------- */
    window.App = {
        ajaxPost: ajaxPost,
        ajaxGet: ajaxGet,
        toast: toast,
        startCountdown: startCountdown,
        bindSendCode: bindSendCode,
        trackAdImpression: trackAdImpression,
        initAdImpressions: initAdImpressions
    };

    /* ---------- DOM Ready: 自动初始化 ---------- */
    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }
    ready(function () {
        initAdImpressions();
    });
})();
