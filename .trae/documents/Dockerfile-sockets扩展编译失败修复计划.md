# Dockerfile sockets 扩展编译失败修复计划

> 计划类型：Bug 修复（单行删除）
> 创建时间：2026-07-28
> 触发：Docker 构建 `RUN docker-php-ext-install pcntl sockets` 失败，`make: *** [Makefile:209: sockets.lo] Error 1`

---

## 一、总结

删除 Dockerfile 中 `sockets` 扩展的安装。该扩展非项目依赖，编译失败阻塞了整个 Docker 构建。

---

## 二、当前状态分析

### 2.1 失败点

[Dockerfile](file:///workspace/Dockerfile#L57) 第 57 行：

```dockerfile
# 进程 + 信号
RUN docker-php-ext-install -j"$(nproc)" pcntl sockets
```

构建报错：`make: *** [Makefile:209: sockets.lo] Error 1`，`pcntl` 已编译完成（"Waiting for unfinished jobs" 指等待 sockets），仅 `sockets` 失败。

### 2.2 依赖核查结果

| 扩展 | composer.json 要求 | 项目代码引用 | 第三方依赖（vendor） | 结论 |
|------|-------------------|-------------|---------------------|------|
| `sockets` | ❌ 未要求 | ❌ 零引用（`grep sockets_` 无结果） | ❌ 无 | **删除** |
| `pcntl` | ❌ 未要求 | ❌ 项目代码未直接用 | ✅ think-queue `Worker.php` 用 `pcntl_signal`/`pcntl_async_signals` | **保留** |

**`sockets` 为何存在**：早期 Dockerfile 预留了 `swoole` 扩展（已在前次修复中移除），`sockets` 是 swoole 的依赖，swoole 移除后 `sockets` 成为孤儿，应一并清理。

### 2.3 pcntl 编译可行性

`pcntl` 在 `php:8.2-fpm-alpine` 上编译正常（本次失败仅 sockets），无需改动。

---

## 三、修复方案

### 修复：删除 Dockerfile 中的 sockets 扩展

**文件**：[Dockerfile](file:///workspace/Dockerfile)

**变更**：第 56-57 行

```dockerfile
# 修复前
# 进程 + 信号
RUN docker-php-ext-install -j"$(nproc)" pcntl sockets

# 修复后
# 进程信号（think-queue Worker 优雅退出需要 pcntl）
RUN docker-php-ext-install -j"$(nproc)" pcntl
```

**理由**：
1. `sockets` 非项目依赖，编译失败阻塞构建
2. `pcntl` 是 think-queue 必需依赖，保留
3. 顺便修正注释，说明 pcntl 的用途

---

## 四、假设与决策

| 项 | 决策 | 理由 |
|---|---|---|
| 是否尝试修复 sockets 编译 | 否 | sockets 非必需，删除比修编译错误更简洁可靠 |
| 是否保留 pcntl 与 sockets 同层 | 否，仅留 pcntl | sockets 删除后该层只剩 pcntl，自然独立 |
| 是否需要同步更新 php.ini | 否 | php.ini 未声明 sockets 相关配置 |

---

## 五、验证步骤

1. **依赖复核**：确认 composer.json 与 vendor 中无 sockets 依赖（已核查）
2. **Git 提交推送**：commit message `fix: 移除非必需的 sockets 扩展，修复 Docker 构建`
3. **用户侧验证**：重新执行 `./docker-deploy.sh up` 或 `./install.sh`，该层应通过

---

## 六、执行清单

- [ ] 修改 [Dockerfile](file:///workspace/Dockerfile) 第 56-57 行：移除 `sockets`，保留 `pcntl`，更新注释
- [ ] git commit + push origin main
