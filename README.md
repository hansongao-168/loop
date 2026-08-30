# Loop RAG AI Server

面向多个业务项目的统一 AI 网关，基于 Laravel 13、PHP 8.5 和 Homebrew Apache。LLM 使用本机原生 Ollama，不使用 Docker。

开发前建议先阅读 [`docs/PROJECT_STRUCTURE_AND_FLOWS.md`](docs/PROJECT_STRUCTURE_AND_FLOWS.md)，其中说明了目录职责、数据模型、鉴权方式、文档入库和 RAG 查询的完整链路。

## 本机架构

```text
业务项目 ──Bearer Token──> Homebrew Apache :80 ──> Laravel API
                                                   ├── LLM API
                                                   └── RAG 知识库
                                                          │
                                                原生 Ollama :11434
```

Web 请求统一由 Homebrew Apache 处理。不要使用 `php artisan serve`。

## 初始化项目

```bash
cp .env.example .env
touch database/database.sqlite
php artisan key:generate
php artisan migrate
composer run dev
```

`composer run dev` 只构建前端资源并清理 Laravel 缓存，不会启动其他 Web 服务器。

## 配置 Homebrew Apache

确认 Homebrew 已安装 `httpd` 和 PHP 8.5，并在 Apache 中启用了 PHP、rewrite、headers 和虚拟主机配置。

将项目提供的虚拟主机配置加入：

```text
/usr/local/etc/httpd/extra/httpd-vhosts.conf
```

配置模板位于 [`apache/looprag.test.conf`](apache/looprag.test.conf)。同时在 `/etc/hosts` 中加入：

```text
127.0.0.1 looprag.test
127.0.0.1 test.gzai.com
```

检查并启动 Apache：

```bash
sudo /usr/local/opt/httpd/bin/httpd -t
sudo brew services restart httpd
```

访问后台：`http://looprag.test/admin`

## 原生 Ollama

```bash
brew install ollama
brew services start ollama
ollama pull nomic-embed-text
ollama pull qwen3:4b
```

`.env` 保持以下设置：

```dotenv
APP_URL=http://looprag.test
LLM_BASE_URL=http://127.0.0.1:11434/v1
LLM_CHAT_MODEL=qwen2.5:7b-instruct
LLM_EMBEDDING_MODEL=nomic-embed-text
```

推荐配置：主模型用非思考的 instruct 模型（本机实测 7b 一次生成即带引用，端到端 ~8 s/题），qwen3 这类思考模型会先生成长推理（实测 ~150 s/题），只放候选链末尾兜底。

验证 Ollama：

```bash
curl http://127.0.0.1:11434/api/tags
```

## 多模型自动切换

所有 AI 调用统一经过 `App\Services\Ai\LoopRouter` 中央调度器。任务链在 `.env` 中用 `provider@model` 语法声明，后面的候选是前面的自动降级：

```dotenv
# 主模型失败时自动切到 backup provider 的 qwen2.5:7b
LOOP_ANSWER_CANDIDATES="openai_compatible@qwen3:4b,backup@qwen2.5:7b"
LOOP_CHAT_CANDIDATES="openai_compatible@qwen3:4b,backup@llama3.2:3b"

# backup provider（任意 OpenAI 兼容服务），留空则不启用
LLM_BACKUP_BASE_URL=http://127.0.0.1:11435/v1
LLM_BACKUP_API_KEY=ollama
```

切换策略 `LOOP_DEFAULT_STRATEGY`：

- `failover`（默认）：按声明顺序尝试，失败自动切下一个模型。
- `round_robin`：按请求轮转起始模型分摊负载，单次请求内仍然失败降级。
- `single`：只用第一个候选，不降级。

调度器还内置了自动保护：同一 `(provider, model)` 连续失败会熔断（`LOOP_CB_*`），探测失败（见下文 `loop:health`）的 provider 会被自动排到候选链末尾，真实调用成功后自动恢复；RPM/TPM/并发限流按模型对独立计数（`LOOP_RPM` 等）。每次调用的 provider、模型、延迟、token 消耗写入 `ai_call_logs`，后台首页展示 24 小时聚合、按模型用量统计和各 provider 健康状态（`loop:health` 每 5 分钟刷新）。

`php artisan loop:health` 可手动探测所有 provider 并输出健康表；命令以非零退出码表示存在不可用 provider，可直接接入监控。

## 自动化调度

文档索引、图谱社区构建等都是队列任务。本项目按 Apache + brew services 的部署方式，用 Laravel 调度器自动消化队列与健康探测，无需常驻 worker 进程。调度内容（见 `routes/console.php`）：

- 每分钟：`queue:work --stop-when-empty`，处理完当前积压后退出。
- 每 5 分钟：`loop:health`，刷新 provider 健康状态。

在 crontab 中加入唯一一条入口（注意 `php` 必须写绝对路径——cron 的 PATH 只有 `/usr/bin:/bin`，裸 `php` 会静默失败）：

```bash
* * * * * cd '/path/to/LooP RaG' && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
```

## API

健康检查：`GET http://looprag.test/api/health`

所有业务接口使用 `Authorization: Bearer <AI_GATEWAY_API_KEY>`：

- `POST /api/v1/chat/completions`：直接调用模型
- `POST /api/knowledge-bases`：创建知识库
- `POST /api/knowledge-bases/{id}/documents`：导入文档
- `POST /api/knowledge-bases/{id}/query`：RAG 问答

生产环境必须更换 `APP_KEY`、后台密码和 `AI_GATEWAY_API_KEY`，关闭 `APP_DEBUG`，并为 Apache 配置 HTTPS。
