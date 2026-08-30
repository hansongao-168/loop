# 项目结构与核心流程

本文面向 Loop RAG 的开发与维护人员，描述当前代码的模块边界、数据结构和主要请求链路。部署与初始化命令见项目根目录的 `README.md`。

## 1. 系统定位

Loop RAG 是一个基于 Laravel 13 的统一 AI 网关，同时提供：

- 管理后台：创建知识库、导入/删除文档、执行知识库问答。
- 受 Bearer Token 保护的 HTTP API：供其他业务系统调用知识库与模型。
- OpenAI 兼容模型适配：通过 `/embeddings` 和 `/chat/completions` 对接 Ollama、vLLM、LM Studio 或云端网关。

当前 Web 入口由 Homebrew Apache 提供，Apache 的 `DocumentRoot` 指向 `public/`。应用不依赖 Docker，也不以 `php artisan serve` 作为项目 Web 服务器。

## 2. 总体架构

```text
管理人员（浏览器）                 业务系统
       │ Session                    │ Bearer Token
       ▼                            ▼
  routes/web.php               routes/api.php
       │                            │
       ├─ Admin Controllers         ├─ API Controllers
       │                            │
       └──────────────┬─────────────┘
                      ▼
    DocumentIngestor / RagQueryService
                 │              │
        DocumentIndexer / LoopRouter
                 ▼              ▼
        SQLite + Eloquent   OpenAI 兼容模型服务
        知识库/文档/切片     Embedding + Chat
```

职责划分遵循以下原则：

- 路由层负责 URL、HTTP 方法和中间件编排。
- 控制器负责参数校验、HTTP/页面响应及用户可见错误。
- 服务层负责文本切片、向量生成、相似度检索和模型调用。
- 模型层负责数据库关系与 JSON 字段转换。
- Blade 视图只负责管理后台展示与表单交互。

## 3. 目录结构

```text
.
├── apache/                         # Apache 虚拟主机配置模板
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/              # 后台登录、仪表盘、知识库操作
│   │   │   └── Api/                # 对外 JSON API
│   │   └── Middleware/             # 后台 Session、API Key 鉴权
│   ├── Models/                     # KnowledgeBase、Document、DocumentChunk
│   ├── Providers/                  # Laravel 服务提供者
│   └── Services/
│       ├── Ai/                     # LOOP 中央 AI 调度器（Router/Limits/Circuit/Recording/Adapters/Exceptions/Streaming）
│       ├── Support/                # 纯函数工具（CosineSimilarity）
│       ├── DocumentIngestor.php    # 入库工作流（同步/异步/重试）
│       ├── DocumentIndexer.php     # 索引管线（切片/向量化/图抽取）
│       ├── RagQueryService.php     # 检索融合与答案生成
│       ├── CommunityDetectionService.php  # 确定性 Louvain 分层社区
├── bootstrap/                      # Laravel 启动与中间件别名注册
├── config/                         # 应用、数据库、Session、AI 等配置
├── database/
│   ├── migrations/                 # 用户、缓存、队列及 RAG 表结构
│   ├── factories/                  # 测试数据工厂
│   └── seeders/                    # 数据填充入口
├── public/                         # Apache 唯一对外暴露的 Web 根目录
├── resources/
│   ├── css/、js/                   # Vite 前端资源入口
│   └── views/                      # Blade 后台页面
├── routes/
│   ├── web.php                     # 后台页面路由
│   ├── api.php                     # 外部 API 路由
│   └── console.php                 # Artisan 控制台路由
├── storage/                        # 日志、缓存、Session 和生成视图
├── tests/                          # PHPUnit 单元与功能测试
├── .env.example                    # 本地配置模板
├── artisan                         # Laravel CLI 入口
├── composer.json                   # PHP 依赖与项目脚本
├── package.json                    # Vite/Tailwind 依赖与脚本
└── vite.config.js                  # 前端构建配置
```

### 核心类

| 类 | 作用 |
| --- | --- |
| `TextChunker` | 按字符数切分文本，并按配置保留相邻切片重叠区域。 |
| `LoopRouter` | **LOOP 中央 AI 调度器**，所有 AI 调用的唯一入口。负责解析 (provider, model)、限流、熔断、failover、用量记录。 |
| `ProviderRegistry` / `ProviderAdapter` / `OpenAICompatibleAdapter` | 多 Provider 注册表与适配器。默认实现 OpenAI 兼容协议（Ollama / vLLM / 云端网关）。 |
| `ModelResolver` | 按 task 类型在 `services.loop.models` 中挑选候选 (provider, model) 链，支持 `single` / `failover` / `round_robin` 策略。 |
| `TokenBucketLimiter` / `ConcurrencyGate` | RPM/TPM token-bucket 限流和并发门控，按 (provider, model) 维度计数。 |
| `CircuitBreaker` | Cache 后端的失败计数熔断器；超阈值后短路一段时间。 |
| `UsageRecorder` + `LoopCallRecorded` 事件 | 每次 AI 调用都会触发事件，监听器写入 `ai_call_logs` 表，用于可观测性。 |
| `DocumentIngestor` | 文档入库工作流：同步导入、异步派发与失败重试。 |
| `DocumentIndexer` | 索引管线：切片、Embedding、图抽取与文档级社区失效。 |
| `RagQueryService` | RAG 查询管线：向量/关键词/图召回、RRF 融合、重排与答案生成。 |
| `CommunityDetectionService` | 确定性 Leiden 式社区检测（局部移动 + 精细化 + 分层凝聚），保证社区内部连通。 |
| `KnowledgeBaseController` | 提供知识库列表、创建与删除 API。 |
| `RagController` | 提供文档导入与 RAG 查询 API。 |
| `ChatController` | 不经过知识库，直接代理聊天模型调用（注入 `LoopRouter`）。 |
| `StreamChatController` | 通过 SSE 流式返回 `POST /api/v1/chat/stream` 的聊天增量。 |
| `KnowledgeController` | 管理后台的知识库、文档和问答操作。 |

## 4. 数据模型

```text
knowledge_bases 1 ────── N documents 1 ────── N document_chunks
```

### `knowledge_bases`

- `name`：知识库名称。
- `description`：可选说明。

### `documents`

- `knowledge_base_id`：所属知识库。
- `title`、`source`：文档标题和可选来源。
- `metadata`：可选 JSON 扩展信息。
- `status`：导入时先写入 `processing`，全部切片成功后更新为 `ready`。

### `document_chunks`

- `document_id`：所属文档。
- `position`：切片在文档中的顺序，同一文档内唯一。
- `content`：切片原文。
- `embedding`：JSON 数组形式的向量。
- `metadata`：可选 JSON 扩展信息。

删除知识库会级联删除其文档和切片；删除文档会级联删除其切片。`Document` 与 `DocumentChunk` 模型会将 JSON 字段自动转换为 PHP 数组。

## 5. 请求入口与鉴权

### 管理后台

- 入口：`/admin`，未登录时跳转 `/admin/login`。
- 鉴权：登录成功后在 Session 中写入 `admin_authenticated`。
- 凭据：读取 `ADMIN_USERNAME` 与 `ADMIN_PASSWORD`。
- 登录接口限制：每分钟最多 10 次请求。
- 知识库页面展示文档索引阶段、失败原因、实体/关系/社区统计和最近社区任务。
- 后台支持异步文档导入、失败索引重试、异步社区重建和 Auto/Local/Global/Vector 问答测试。
- 实体详情页展示别名、入边、出边、关系陈述及对应原文切片，且严格校验知识库归属。

### 外部 API

- 健康检查 `GET /api/health` 无需鉴权。
- 其余 API 必须携带 `Authorization: Bearer <AI_GATEWAY_API_KEY>`。
- 受保护接口统一限制为每分钟最多 60 次请求。
- API 路径发生异常时，Laravel 统一返回 JSON，而不是 HTML 错误页。

## 6. 核心流程

### 6.1 文档入库

入口可以是后台表单，也可以是：

```http
POST /api/knowledge-bases/{knowledgeBase}/documents
```

```text
客户端
  │ title/content/source/metadata
  ▼
控制器校验参数
  ▼
            DocumentIngestor::ingest（同步默认）
  ├─ 创建 status=pending 的 Document
  ▼ DocumentIndexer::index
  ├─ 状态置为 chunking，删除旧切片并清理孤儿图谱事实
  ├─ TextChunker 按 RAG_CHUNK_SIZE 切片
  │               并保留 RAG_CHUNK_OVERLAP
  ├─ 对每个切片调用 LoopRouter::embed（task=embed）
  ├─ 保存 content、position、embedding
  ├─ 图谱开启时抽取实体/关系并落库（task=extract）
  ├─ invalidateCommunities：清空社区并递增 graph_version
  └─ 全部成功后设置 status=ready
  ▼
返回文档及 chunks_count
```

整个入库过程当前是同步的，并包含在同一个数据库事务中。任一 Embedding 请求失败会抛出异常并回滚本次文档及切片写入；后台将显示通用错误，API 返回 JSON 异常响应。

API 调用方也可以传入 `"async": true` 选择异步索引。服务返回 `202 Accepted` 和 `pending` 文档，由队列任务依次更新 `chunking`、`embedding`、`extracting`、`ready` 或 `failed` 状态。可通过以下接口查询和重试：

- `GET /api/knowledge-bases/{knowledgeBase}/documents/{document}/index-status`
- `POST /api/knowledge-bases/{knowledgeBase}/documents/{document}/retry-index`

重试会递增 `index_version`，队列任务会忽略过期版本。异步处理要求 Laravel Queue worker 正常运行。

### 6.2 RAG 问答

入口可以是后台问答表单，也可以是：

```http
POST /api/knowledge-bases/{knowledgeBase}/query
```

```text
问题 question
  ▼
生成问题向量
  ▼
读取该知识库的全部切片
  ▼
在 PHP 应用层计算余弦相似度
  ▼
按 score 降序选择 top_k（范围 1～20）
  ▼
组装带 [1]、[2] 编号的上下文
  ▼
发送 system + user 消息到 Chat Completion
  ▼
返回 answer、model、sources、usage
```

系统提示要求模型仅依据提供的上下文回答，并使用 `[1]`、`[2]` 格式引用来源。`sources` 中同时返回文档 ID、标题、来源、相似度和最多 240 字符的摘录。

启用 `GRAPH_RAG_ENABLED` 后，查询接口还支持以下可选参数：

- `mode`：`auto`（默认）、`local`、`global` 或 `vector`；`global` 会按问题向量召回社区摘要，并回到成员关系的原始切片证据。
- `max_hops`：Local GraphRAG 的图遍历深度，范围 1～2。
- `community_top_k`：Global 模式召回的社区数量，范围 1～10。
- `include_graph`：是否在响应中附带命中的实体与关系诊断数据。

Local 模式从问题中的实体名或别名确定种子，进行受节点数和跳数限制的邻域扩展。查询同时执行中英文关键词匹配，再通过 RRF 融合向量、关键词和图证据。未命中实体时仍保留向量与关键词混合检索。`sources` 会额外返回 `retrieval_score` 和 `channels`，用于说明证据来自 `vector`、`keyword`、`graph` 或多个通道。

设置 `GRAPH_RAG_RERANK_ENABLED=true` 后，RRF 先保留受限候选池，再由 `GRAPH_RAG_RERANK_MODEL` 返回全部候选 ID 的完整排列。只有无重复、无遗漏、无陌生 ID 的结果才会生效；模型异常或输出无效时自动回退到 RRF 顺序。响应中的 `retrieval.reranked` 表示本次是否实际采用重排。

设置 `GRAPH_RAG_SEMANTIC_ENTITY_RESOLUTION=true` 后，新实体会保存由名称、类型和描述生成的向量。名称及唯一别名无法确定匹配时，系统仅比较同知识库、同类型候选；最高相似度必须同时达到绝对阈值，并领先第二名指定差距才会合并。未达到任一条件时创建独立实体。该功能会为新实体增加 Embedding 调用，默认关闭。

Global 模式使用版本化社区摘要进行主题级召回，并把社区中的关系证据切片加入 RRF。社区算法为确定性 Leiden 式模度优化（`CommunityDetectionService`，局部移动 + 精细化（保证社区内部连通）+ 图凝聚，可重复执行结果一致）：`GRAPH_RAG_COMMUNITY_LEVELS`（默认 2）控制层级深度，level 0 为最细划分，更高层级凝聚出更粗的主题社区；不连通的分簇永不合并，凝聚不再产生新分组时提前收敛。成员记录 `membership_score`（社区内关系权重份额），高层社区在 metadata 的 `parent_communities` 中记录被凝聚的低层社区。Global 检索返回社区 `level`，相关性平序时优先细粒度层级。社区必须通过以下接口显式重建：

```http
POST /api/knowledge-bases/{knowledgeBase}/graph/rebuild-communities
```

请求体传入 `{"async": true}` 时返回 `202 Accepted` 和社区构建记录，由队列异步完成。状态查询接口为：

```http
GET /api/knowledge-bases/{knowledgeBase}/graph/community-builds/{build}
```

每次图谱变化都会递增知识库的 `graph_version`。异步构建会记录触发时版本，并在开始及提交前校验；过期任务标记为 `failed`，不会覆盖当前数据。任何实体或关系写入、文档删除也会清空现有社区，确保旧摘要不会参与回答。没有可用社区时，Global 查询自动降级为向量与关键词检索。

### 6.3 直接聊天

```http
POST /api/v1/chat/completions
```

该接口校验 `messages`、可选 `model` 和 `temperature` 后，直接调用 `LoopRouter::chat`（`task=chat_direct`）。它不进行文本检索，也不注入知识库上下文，响应基本保持上游 OpenAI 兼容格式（额外附加 `provider`、`request_id`、`latency_ms` 字段）。

流式版本：

```http
POST /api/v1/chat/stream
```

通过 SSE (`text/event-stream`) 输出 `LoopRouter::stream()` 的增量块，每块形如 `data: {"delta":"...","finish_reason":null}\n\n`，流结束发送 `data: [DONE]\n\n` 哨兵。RAG 答案保留一次性响应以保证来源编号的确定性。

### 6.4 后台登录与访问

```text
GET /admin
  ├─ Session 已认证 → DashboardController
  └─ 未认证 → /admin/login
                  │
             POST 用户名/密码
                  │
       常量时间比较环境变量中的凭据
          ├─ 成功：刷新 Session ID，进入仪表盘
          └─ 失败：返回登录页及错误信息
```

退出登录会使当前 Session 失效并重新生成 CSRF Token。

## 7. 配置流向

运行参数从 `.env` 进入 `config/services.php`，业务代码只通过 `config()` 读取：

### 7.1 LOOP 中央 AI 调度器（新）

| 环境变量 | 消费方 | 含义 |
| --- | --- | --- |
| `LOOP_DEFAULT_PROVIDER` | `LoopRouter` | 默认 provider id（默认 `openai_compatible`）。 |
| `LOOP_DEFAULT_STRATEGY` | `LoopRouter` | `single` / `failover` / `round_robin`。 |
| `LOOP_STREAM` | `LoopRouter` | 是否在 `chat()` 内部启用流式底层。 |
| `LOOP_CACHE_TTL` | `LoopRouter` | 嵌入缓存 TTL（预留）。 |
| `LOOP_RETRY_TIMES` / `LOOP_RETRY_SLEEP_MS` | `OpenAICompatibleAdapter` | 上游 HTTP 重试次数与间隔。 |
| `LOOP_RPM` / `LOOP_TPM` / `LOOP_CONCURRENCY` | `TokenBucketLimiter` / `ConcurrencyGate` | 每条 (provider, model) 的默认限流。 |
| `LOOP_CB_FAILURE_THRESHOLD` | `CircuitBreaker` | 触发熔断的连续失败次数。 |
| `LOOP_CB_COOLDOWN` | `CircuitBreaker` | 熔断后的冷却秒数。 |
| `LOOP_RECORD` / `LOOP_RECORD_SAMPLE` | `UsageRecorder` | 是否记录到 `ai_call_logs` 及采样率。 |
| `LOOP_*_PROVIDER` | `LoopRouter` | 每个 task（embed/chat/extract/summary/rerank/answer/chat_direct）的默认 provider id。 |

### 7.2 兼容层（保留旧键）

| 环境变量 | 消费方 | 含义 |
| --- | --- | --- |
| `AI_GATEWAY_API_KEY` | `EnsureAiApiKey` | 调用本服务 API 的 Bearer Token。 |
| `LLM_BASE_URL` | `LoopRouter`（默认 provider）/ 仪表盘 | OpenAI 兼容服务根地址，包含 `/v1`。 |
| `LLM_API_KEY` | 同上 | 上游模型服务 Token；本机 Ollama 可使用占位值。 |
| `LLM_CHAT_MODEL` | `LoopRouter`（answer/chat 默认模型） | 默认聊天模型。 |
| `LLM_EMBEDDING_MODEL` | `LoopRouter`（embed 默认模型） | 默认向量模型。 |
| `LLM_TIMEOUT` | `OpenAICompatibleAdapter` | 上游请求超时秒数。 |
| `RAG_CHUNK_SIZE` | `TextChunker` | 单个切片的最大字符数，最小 200。 |
| `RAG_CHUNK_OVERLAP` | `TextChunker` | 相邻切片重叠字符数，小于切片大小。 |
| `RAG_TOP_K` | `RagQueryService` | 默认召回切片数。 |
| `GRAPH_RAG_ENABLED` | 图谱入库与查询服务 | 是否启用图谱抽取和 Local GraphRAG。 |
| `GRAPH_RAG_EXTRACTION_MODEL` | `LoopRouter`（extract 任务） | 实体关系抽取模型。 |
| `GRAPH_RAG_SUMMARY_MODEL` | `LoopRouter`（summary 任务） | 社区标题与摘要生成模型。 |
| `GRAPH_RAG_MAX_NODES` | `LocalGraphSearchService` | 单次图邻域扩展的节点上限。 |
| `GRAPH_RAG_RERANK_ENABLED` | `CandidateRerankerService` | 是否启用模型候选重排，默认关闭。 |
| `GRAPH_RAG_RERANK_MODEL` | `LoopRouter`（rerank 任务） | 候选重排使用的模型。 |
| `GRAPH_RAG_RERANK_CANDIDATES` | `RagQueryService` | 送入重排模型的最大 RRF 候选数量。 |
| `GRAPH_RAG_SEMANTIC_ENTITY_RESOLUTION` | `EntityResolutionService` | 是否启用向量辅助实体消歧，默认关闭。 |
| `GRAPH_RAG_ENTITY_SIMILARITY_THRESHOLD` | `EntityResolutionService` | 自动合并的最低余弦相似度。 |
| `GRAPH_RAG_ENTITY_SIMILARITY_MARGIN` | `EntityResolutionService` | 第一候选相对第二候选必须达到的领先差距。 |
| `ADMIN_USERNAME` / `ADMIN_PASSWORD` | `AuthController` | 后台登录凭据。 |

修改 `.env` 后应运行 `php artisan optimize:clear`，避免继续使用缓存配置。

## 8. 开发与变更指引

### 新增 API

1. 在 `routes/api.php` 定义路由，并确认是否放入 `ai.key` 中间件组。
2. 在 `app/Http/Controllers/Api/` 添加薄控制器并完成输入校验。
3. 可复用的业务逻辑放入 `app/Services/`，不要堆积在控制器中。
4. 数据结构变更通过新 migration 完成，不修改已部署过的 migration。
5. 在 `tests/Feature/` 添加鉴权、校验、成功与失败路径测试。

### 修改 RAG 行为

- 切片策略：修改 `TextChunker`，并覆盖空文本、重叠和多语言文本测试。
- 检索/提示词：修改 `RagQueryService`，保持来源编号与 `sources` 返回顺序一致。
- 模型协议：新增 provider 时实现 `App\Services\Ai\ProviderAdapter` 接口，并在 `AppServiceProvider::register` 的 `ProviderRegistry` 上注册；不要把协议细节泄漏到控制器。
- 新增模型配置：先加入 `.env.example` 和 `config/services.php` 的 `services.loop.*` 区块，业务代码使用 `config()` 读取。

## 9. 当前实现边界

- 向量以 JSON 存入数据库，没有使用专用向量数据库或向量索引。
- 查询会将目标知识库的全部切片加载到应用内存并逐一计算余弦相似度；数据量增大后需要迁移到数据库/向量引擎检索。
- 文档入库为同步串行请求，大文档的处理时间等于所有 Embedding 调用耗时之和；队列表已存在，但当前 RAG 流程没有使用队列。
- `LoopRouter::chat` 默认一次性返回；流式仅在 `/api/v1/chat/stream` 上由 `LoopRouter::stream()` 提供。RAG 答案保持一次性响应以避免破坏 `[1]`、`[2]` 来源编号。
- 后台使用环境变量中的单一账号，不使用 `users` 表，也没有角色权限模型。
- 当前仅接收请求体中的纯文本，不包含 PDF、Word、网页抓取或文件解析流程。
- LOOP 调度器当前仅注册 `openai_compatible` 一个 driver；新增 Anthropic / Azure OpenAI / 本地 GGUF 等需要新增 adapter + 在 `services.loop.providers` 注册条目。

这些边界不是接口承诺。扩展时应优先保持控制器输入输出稳定，将异步任务、解析器或检索引擎封装在服务层。

## 10. 架构评估与依赖规则

本节是 2026-08-28 对当前代码库（`app/` 下 58 个 PHP 文件）的一次模块化、高内聚、低耦合、单向依赖专项检查结论，供维护者与 AI 编码助手共同遵守。10.3 节中的修复代码为「建议形态」；**2026-08-29 更新：P1~P4 各项已全部落地**（监听上移组合根、删除 `AiClient` 兼容层并统一注入 `LoopRouter`、抽取 `Support\CosineSimilarity`、社区失效上移文档级、抽取 `DocumentIndexer`、`RagService` 拆分为 `DocumentIngestor` + `RagQueryService`），10.1 中对应问题已消除。

### 10.1 评估结论总览

> 下表为 2026-08-28 检查时的原始结论，P1~P4 落地后：单向依赖违规清零，`AiClient` 兼容层已整体移除（所有调用方直接注入 `LoopRouter`），余弦相似度统一为 `Support\CosineSimilarity`，入库与查询分属 `DocumentIngestor`/`RagQueryService`。

| 维度 | 结论 | 关键证据 |
| --- | --- | --- |
| 模块化 | 基本达标 | 第 2、3 节所述分层职责清晰；`app/Services/Ai`（LOOP 调度器子系统）是无环、单一入口（`LoopRouter`）的子图，在 `AppServiceProvider` 中有 8 个显式 singleton 绑定。 |
| 高内聚 | 部分达标 | `RagService`（202 行、9 个构造器依赖）混合入库管线、队列派发、检索管线、答案生成与数学计算；`LoopRouter::dispatch()` 与 `stream()` 的守卫/记录逻辑重复；余弦相似度在 3 个服务中各有一份等价实现（见 10.3.3）。 |
| 低耦合 | 部分达标 | 容器无接口绑定，全部依赖具体类；`@deprecated` 的 `AiClient` 仍是 5 个服务的实际依赖而非过渡层；`AiClient::client()` 为零调用方死代码（见 10.3.4）。 |
| 单向依赖 | 存在违规 | `Document` 模型反向调用 `GraphRepository`（10.3.1）；`RagService` 与 `ProcessDocumentIndex` Job 互相依赖成环（10.3.2）；另有 1 处隐藏时序耦合（10.3.5）。 |

### 10.2 依赖方向规则（AI 编码硬约束）

当前合法依赖方向：

```text
routes (web / api)
   ▼
Controllers (Admin / Api)          Console Commands
   ▼                                    ▼
Jobs ────────────────────────► Services
                                  │
              ┌───────────────────┼───────────────────┐
              ▼                   ▼                   ▼
        Services/Ai         其他 Services          Models
     (LoopRouter 子系统)   (RagService 等)    (仅 Eloquent 关系)
              ▼                                       ▼
     ProviderAdapter 实现                          数据库表
```

新增或修改代码时必须遵守：

- 允许：Controllers → Services；Jobs → Services；Console → Services；Service → Service（保持无环）；Service → Models；Model → Model（仅 Eloquent 关联）。
- 禁止：Model 引用任何 Service，或在模型事件中进行 `app()` 容器查找（现状见 10.3.1）。
- 禁止：Service 静态派发某个 Job，而该 Job 的 `handle()` 又注入同一 Service 形成回环（现状见 10.3.2）。
- 禁止：控制器内编写数据聚合/统计查询，应下沉到服务层（`DashboardController` 的调用统计已由 `AiUsageReport` 服务承接）。
- 禁止：HTTP 协议细节进入控制器；新增 Provider 时实现 `ProviderAdapter` 并在 `ProviderRegistry` 注册（见第 8 节）。
- 一切 AI 调用直接注入 `LoopRouter`（`AiClient` 兼容层已于 P4 落地时删除，不得重新引入）。
- `AppServiceProvider` 是组合根，唯一允许「知道所有类型」的位置；模型事件监听统一在此注册。

### 10.3 违规明细与修复示例

#### 10.3.1 Model 反向依赖 Service：`Document::deleted` 中的容器查找

现状 `app/Models/Document.php:34-39`：

```php
protected static function booted(): void
{
    static::deleted(function (Document $document) {
        app(GraphRepository::class)->removeOrphans($document->knowledge_base_id);
    });
}
```

问题：模型层反向依赖服务层，且是全应用唯一的 `app()` 容器查找；任何 `Document::delete()` 都会隐式触发全库社区清空与 `graph_version` 递增，对调用方不可见。

修复：把监听注册上移到组合根 `AppServiceProvider::boot()`，模型恢复纯净，外部行为不变：

```php
// app/Models/Document.php —— 整体删除 booted() 方法与 use App\Services\GraphRepository;

// app/Providers/AppServiceProvider.php —— boot() 中追加
use App\Models\Document;
use App\Services\GraphRepository;

public function boot(): void
{
    Document::deleted(function (Document $document) {
        app(GraphRepository::class)->removeOrphans($document->knowledge_base_id);
    });
}
```

依赖方向变为「Provider（组合根）→ Service」，`Document` 不再知道 `GraphRepository` 的存在。

#### 10.3.2 Service ↔ Job 循环依赖

现状：`app/Services/RagService.php:5,38,51` 静态派发 `ProcessDocumentIndex`，而 `app/Jobs/ProcessDocumentIndex.php:27` 的 `handle(RagService $rag)` 又注入 `RagService`，两个类互相依赖。

修复（最小改动打破回环）：把索引管线抽为独立的 `DocumentIndexer`，Job 只依赖它，`RagService` 保留派发职责并委托该类：

```php
// app/Services/DocumentIndexer.php（新建；承接 RagService::indexDocument 的实现原样迁移）
namespace App\Services;

use App\Models\Document;

class DocumentIndexer
{
    public function __construct(
        private AiClient $ai,
        private TextChunker $chunker,
        private GraphExtractionService $graphExtractor,
        private GraphRepository $graphRepository,
    ) {}

    public function index(Document $document): void
    {
        // 原 RagService::indexDocument 方法体原样迁入
    }
}
```

```php
// app/Jobs/ProcessDocumentIndex.php —— 仅改注入类型
use App\Services\DocumentIndexer;

public function handle(DocumentIndexer $indexer): void
{
    // …版本与状态守卫不变…
    $indexer->index($document);
    // …异常处理不变…
}
```

```php
// app/Services/RagService.php —— 删除 indexDocument 方法体，改为委托；
// chunker / graphExtractor / graphRepository 三个仅被索引使用的依赖随迁
public function __construct(
    private AiClient $ai,
    private DocumentIndexer $indexer,
    private LocalGraphSearchService $localGraphSearch,
    private GlobalGraphSearchService $globalGraphSearch,
    private KeywordRetrievalService $keywordRetrieval,
    private RetrievalFusionService $retrievalFusion,
    private CandidateRerankerService $candidateReranker,
) {}

public function indexDocument(Document $document): void
{
    $this->indexer->index($document);
}
```

修复后的依赖为 `RagService → DocumentIndexer`、`ProcessDocumentIndex → DocumentIndexer`、`RagService → ProcessDocumentIndex`（仅派发方向），无环。

#### 10.3.3 内聚不足：RagService 混合职责与余弦相似度 3 处重复

现状：`private function cosine` 在 `app/Services/RagService.php:188`、`app/Services/EntityResolutionService.php:119`、`app/Services/GlobalGraphSearchService.php:48` 各有一份逐行等价的实现。`RagService` 同时承担入库管线（`ingest`/`indexDocument`）、队列派发（`ingestAsync`/`retryIndex`）、检索管线（`ask` 内的向量 + 关键词 + 图证据 + RRF 融合 + 模型重排）与答案生成。

修复第一步（纯机械，零行为变化）：抽取纯函数工具类，三处调用点改为 `CosineSimilarity::score($a, $b)`，各自的 `cosine()` 方法删除：

```php
// app/Support/CosineSimilarity.php（新建）
namespace App\Support;

final class CosineSimilarity
{
    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function score(array $a, array $b): float
    {
        if (count($a) !== count($b) || $a === []) {
            return -1.0;
        }
        $dot = $aa = $bb = 0.0;
        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
            $aa += $value ** 2;
            $bb += $b[$i] ** 2;
        }

        return ($aa > 0 && $bb > 0) ? $dot / (sqrt($aa) * sqrt($bb)) : -1.0;
    }
}
```

修复第二步（中期）：按管线把 `RagService` 拆为 `DocumentIngestor`（`ingest`/`ingestAsync`/`retryIndex`，依赖 `DocumentIndexer`）与 `RagQueryService`（`ask`）。调用方只有控制器与 Job，拆分不改变对外 API。

#### 10.3.4 废弃 shim 的死代码与依赖扩散

现状：`app/Services/AiClient.php:104-111` 的 `client()` 仍从 `config('services.ai.*')` 自建 HTTP 客户端，经全库检索确认零调用方（管理后台仪表盘已改用 `LoopRouter::ping()`/`listModels()`）。同时 `GraphExtractionService`、`EntityResolutionService`、`CommunityBuildService`、`CandidateRerankerService`、`RagService` 仍注入 `@deprecated` 的 `AiClient`。

修复：直接删除 `client()` 方法及仅被它使用的 `Http`、`PendingRequest` import（`RequestException` 仍被 `unwrap()` 使用，必须保留）：

```php
// app/Services/AiClient.php —— 删除以下内容
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Legacy helper retained for callers that previously built a
 * PendingRequest manually …
 */
public function client(): PendingRequest
{
    return Http::baseUrl(config('services.ai.base_url'))
        ->withToken(config('services.ai.api_key_upstream'))
        ->acceptJson()
        ->timeout(config('services.ai.timeout'))
        ->retry(2, 300);
}
```

既有 5 处 `AiClient` 注入的切换需单独处理：shim 会把 `LoopChatResult`/`LoopEmbedResult` 转换回 OpenAI 兼容数组形状，直接换注入点会改变返回类型，应逐个适配并配合测试；在此之前 `AiClient` 保持可用。

#### 10.3.5 隐藏时序耦合：社区按 chunk 失效

现状：`app/Services/GraphRepository.php:23-24` 在 `storeChunkGraph()` 事务内每次清空该知识库全部社区并递增 `graph_version`。一次入库含 N 个切片即失效 N 次；而 `RagService::indexDocument`（`app/Services/RagService.php:64`）在索引开始时已调用 `removeOrphans` 清空过一次，这 N 次属于纯冗余。`graph_version` 兼作异步社区构建的乐观锁（`BuildGraphCommunities` 携带 `expectedGraphVersion` 校验），冗余递增会让入库期间触发的社区重建更容易被误判为过期。

修复：把失效从 chunk 级上移到文档级：

```php
// app/Services/GraphRepository.php
// 1) storeChunkGraph() 事务内删除这两行：
//    $chunk->document->knowledgeBase->graphCommunities()->delete();
//    $chunk->document->knowledgeBase()->increment('graph_version');

// 2) 新增文档级失效方法
public function invalidateCommunities(int $knowledgeBaseId): void
{
    GraphCommunity::query()->where('knowledge_base_id', $knowledgeBaseId)->delete();
    KnowledgeBase::query()->whereKey($knowledgeBaseId)->increment('graph_version');
}
```

```php
// app/Services/DocumentIndexer.php（见 10.3.2）—— index() 末尾、status=ready 更新前调用一次
$this->graphRepository->invalidateCommunities($document->knowledge_base_id);
```

落地后 `graph_version` 在一次索引中由「每 chunk 一次（N+1 次）」降为「两次」：索引开始时 `removeOrphans` 清理旧事实并 +1，索引结束时 `invalidateCommunities` 使中途触发构建的社区全部过期并 +1。

### 10.4 重构优先级建议

| 优先级 | 事项 | 影响面 | 外部行为 | 状态 |
| --- | --- | --- | --- | --- |
| P1 | 10.3.1 模型事件监听上移组合根 | 2 个文件 | 无变化 | ✅ 已落地 |
| P1 | 10.3.4 删除 `AiClient::client()` 死代码 | 1 个文件 | 无变化（零调用方） | ✅ 已落地 |
| P2 | 10.3.3 第一步：抽取 `CosineSimilarity` | 4 个文件（新增 1、修改 3） | 无变化（实现逐行等价） | ✅ 已落地 |
| P2 | 10.3.5 社区失效上移文档级 | 2~3 个文件 | `graph_version` 每次索引 +2（开始清理 + 结束失效），相关测试契约已同步 | ✅ 已落地 |
| P3 | 10.3.2 抽取 `DocumentIndexer` 打破 Job 回环 | 新增 1、修改 2 个文件 | 无变化 | ✅ 已落地 |
| P4 | 10.3.3 第二步：`RagService` 拆分入库/查询 | 新增 `DocumentIngestor`/`RagQueryService`，修改控制器与评测服务调用点 | 无变化（对外 API 不变） | ✅ 已落地 |
| P4 | `AiClient` 注入点切换为 `LoopRouter` | 全部 AI 调用方 + 删除 `AiClient` 与容器绑定 | 失败时抛 `ProviderUnavailableException`（原 shim 解包为 `RequestException`），相关测试已适配 | ✅ 已落地 |
