# Loop RAG AI Server

面向多个业务项目的统一 AI 网关，基于 Laravel 13、PHP 8.5 和 Homebrew Apache。LLM 使用本机原生 Ollama，不使用 Docker。

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
LLM_CHAT_MODEL=qwen3:4b
LLM_EMBEDDING_MODEL=nomic-embed-text
```

验证 Ollama：

```bash
curl http://127.0.0.1:11434/api/tags
```

## API

健康检查：`GET http://looprag.test/api/health`

所有业务接口使用 `Authorization: Bearer <AI_GATEWAY_API_KEY>`：

- `POST /api/v1/chat/completions`：直接调用模型
- `POST /api/knowledge-bases`：创建知识库
- `POST /api/knowledge-bases/{id}/documents`：导入文档
- `POST /api/knowledge-bases/{id}/query`：RAG 问答

生产环境必须更换 `APP_KEY`、后台密码和 `AI_GATEWAY_API_KEY`，关闭 `APP_DEBUG`，并为 Apache 配置 HTTPS。
