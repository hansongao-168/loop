# GraphRAG / 知识图谱开发计划

## 1. 文档目的

本文定义 Loop RAG 从当前“文本切片 + 向量相似度检索”演进到 GraphRAG 的实施方案、模块边界、数据模型、里程碑与验收标准。

本计划只描述开发路线，不代表现有接口已经具备文中能力。实施时优先保持当前知识库、文档导入和问答 API 向后兼容。

### 当前实施状态（2026-08-02）

首个开发切片已落地：

- 已建立 entities、relationships、mentions、relationship evidence 四张核心图表。
- 已实现严格 JSON 解析、抽取结果校验、基础实体规范化和证据幂等落库。
- 文档删除后会清理失去全部证据的关系和孤立实体。
- 已提供受 Bearer Token 保护、按知识库隔离的只读图谱调试接口。
- 已增加 `GRAPH_RAG_ENABLED` 开关，默认关闭；现有向量问答路径未改变。
- 已覆盖构图、幂等、删除清理、知识库隔离和关闭开关的自动化测试。
- 已增加可选异步入库、文档级防重叠队列任务、索引版本、阶段状态、失败原因和失败重试接口。
- 异步任务会忽略已完成或版本过期的任务；原始内容不会出现在文档 JSON 响应中。
- 已实现 Local GraphRAG MVP：实体/别名种子匹配、最多两跳的受限邻域扩展，以及图证据与向量结果的 RRF 融合。
- 查询支持 `auto | local | vector` 模式；没有可靠实体种子、图谱关闭或显式使用 `vector` 时安全降级为向量检索。
- 已增加中英文关键词召回，并将 vector、keyword、graph 三路结果统一通过 RRF 融合。
- 已增加保守实体归一：同类型规范名称优先，唯一别名候选可以合并，跨类型或歧义别名不会自动合并。
- 已实现 Global GraphRAG MVP：构建版本化社区、生成摘要与向量，并按问题召回相关社区及其原始关系证据。
- 已实现 Leiden/Louvain 分层社区（阶段 4 完成）：`CommunityDetectionService` 提供确定性 Leiden 式检测——局部移动 + 精细化阶段（保证每个存储社区内部连通）+ 图凝聚（层级深度 `GRAPH_RAG_COMMUNITY_LEVELS`，默认 2）；不连通分簇永不合并，分区稳定即停止；成员按「社区内权重份额」记录 membership_score，高层社区在 metadata 中记录被凝聚的低层社区（parent_communities）保证可追溯；Global 检索结果带 level 字段，平序时优先细粒度层级。
- 图谱变化会使既有社区全部失效；社区重建只有在摘要与向量全部准备成功后才原子替换旧版本。
- 已增加异步社区构建记录、状态查询、失败原因与知识库级防重叠任务。
- 每次图写入都会递增 `graph_version`；异步构建在开始和提交前校验版本，拒绝写入过期摘要。
- 已增加固定 JSON 评测集格式和 `rag:evaluate` 命令，输出 Recall@K、MRR、模式准确率、答案/来源覆盖率和延迟。
- 评测命令支持 JSON 明细报告和最低质量阈值，可直接通过退出码接入 CI。
- 评测已扩展到答案层：支持无答案拒答准确率、引用编号有效性和期望答案关键词覆盖率。
- 已实现可选模型候选重排：对受限 RRF 候选池进行结构化排序，严格校验完整排列，失败时自动降级。
- 已实现可选向量辅助实体消歧：限定知识库和类型，并通过绝对相似度与候选领先差距双阈值进行保守合并。
- 管理后台已增加 GraphRAG 工作台：图谱统计、实体搜索与证据详情、文档索引状态/重试、社区任务和多模式问答。
- 已完成 LOOP 中央 AI 调度器：所有 AI 调用统一经 `LoopRouter`，支持按任务声明 `provider@model` 候选链（`LOOP_*_CANDIDATES`）、`failover | round_robin | single` 三种切换策略、可选第二 provider（`LLM_BACKUP_*`）、熔断、限流、健康探测（`loop:health`，探测失败的 provider 自动后置、成功后自愈）与 `ai_call_logs` 用量记录。
- 已增加调度器自动化：`schedule:run` 驱动每分钟队列消化（文档索引、社区构建无需常驻 worker）与每 5 分钟 provider 健康探测。
- 架构重构收尾：原 `AiClient` 兼容层已删除，所有 AI 调用方（抽取、消歧、社区、重排、索引、查询）直接注入 `LoopRouter` 并按 task 路由（embed/extract/summary/rerank/answer）；原 `RagService` 拆分为 `DocumentIngestor`（入库/重试）、`DocumentIndexer`（索引管线）与 `RagQueryService`（检索与问答）。

开发计划所列功能已全部实施，Leiden 精细化阶段亦已落地。后续演进方向：社区摘要的按层增量失效、基于实测阈值迁移 PostgreSQL/pgvector 与图数据库。

## 2. 当前基线

当前系统基于 Laravel 13、PHP 8.3+、SQLite 和 OpenAI 兼容模型接口：

- 文档以纯文本同步导入。
- `TextChunker` 按固定字符数切片。
- `AiClient` 调用 Embedding 和 Chat Completion。
- 向量以 JSON 存入 `document_chunks.embedding`。
- 查询时将知识库全部切片载入 PHP，计算余弦相似度并取 Top-K。
- `RagService` 负责入库、检索、上下文拼装与答案生成。
- 目前没有实体、关系、声明、社区、图遍历或图谱版本模型。

这套实现适合小规模语义问答，但难以处理跨文档关系、多人多事件关联、全局主题总结和需要多跳推理的问题。

## 3. 建设目标

### 3.1 核心目标

1. 从文档切片中抽取可追溯的实体、关系和事实声明。
2. 在知识库范围内完成实体归一、关系去重和增量更新。
3. 同时使用向量、关键词和图邻域进行混合召回。
4. 支持局部实体问答、多跳关系问答和全局主题问答。
5. 每个回答都能回溯到具体文档与切片，而不是只引用图谱中的二手结论。
6. 为后续迁移到专业向量数据库或图数据库保留适配层。

### 3.2 非目标（首个版本）

- 不建设通用本体编辑器或完整 OWL/RDF 推理系统。
- 不以 LLM 抽取结果作为无需证据的绝对事实。
- 不在首版实现跨知识库自动合并实体。
- 不在首版支持实时协同编辑图谱。
- 不强制引入 Neo4j、NebulaGraph 等独立基础设施。

## 4. 总体方案

首版采用“关系数据库持久化图结构 + 应用层图遍历 + 现有向量检索”的方案。SQLite 可支撑本地开发和小规模知识库，数据访问封装为接口；当规模或查询复杂度达到阈值后，再替换为 PostgreSQL + pgvector 和专业图数据库。

```text
文档导入
  │
  ├─ 文本规范化与结构化切片
  ├─ Embedding 生成
  ├─ 实体/关系/声明抽取
  ├─ 实体归一与去重
  ├─ 图节点、边及证据落库
  └─ 社区发现与摘要（异步）

用户问题
  │
  ├─ 查询分类与实体识别
  ├─ 向量召回 ───────────┐
  ├─ 关键词召回 ─────────┤
  ├─ 图谱种子与多跳扩展 ─┤
  └─ 社区摘要召回 ───────┤
                         ▼
                  融合、去重、重排
                         │
                  证据上下文组装
                         │
                  LLM 生成带引用答案
```

### 4.1 两类查询模式

- **Local Search**：围绕问题中的实体寻找相关切片、直接关系和受限深度邻域，适合“某人和某项目有什么关系”等问题。
- **Global Search**：召回社区及其摘要，分组生成局部结论后汇总，适合“知识库的主要主题和风险是什么”等问题。

默认 `auto` 模式先做轻量查询分类；调用方也可以显式指定 `local`、`global` 或 `vector`，便于降级和对照评测。

## 5. 数据模型设计

所有图谱数据必须带 `knowledge_base_id`，禁止查询跨越知识库边界。

### 5.1 核心表

#### `graph_entities`

- `id`, `knowledge_base_id`
- `canonical_name`：规范名称
- `normalized_name`：用于精确归一的标准化名称
- `type`：如 Person、Organization、Product、Location、Event、Concept
- `description`：基于证据生成的简短描述
- `aliases`：JSON 别名列表
- `embedding`：可选实体描述向量
- `metadata`, `created_at`, `updated_at`
- 唯一约束建议：`knowledge_base_id + type + normalized_name`

#### `graph_relationships`

- `id`, `knowledge_base_id`
- `source_entity_id`, `target_entity_id`
- `type`：规范化关系类型
- `description`：关系的自然语言描述
- `weight`：证据强度或出现次数
- `confidence`：抽取/归一置信度
- `valid_from`, `valid_to`：可选时间范围
- `metadata`, `created_at`, `updated_at`

关系默认保留方向。相同端点和类型允许在证据层出现多次，在关系层聚合。

#### `graph_mentions`

- `id`, `entity_id`, `document_chunk_id`
- `surface_form`：原文中的名称
- `start_offset`, `end_offset`：可选字符位置
- `confidence`, `metadata`

该表连接实体与原始证据，是引用、删除和重新抽取的基础。

#### `graph_relationship_evidence`

- `id`, `relationship_id`, `document_chunk_id`
- `statement`：原文支持的关系陈述
- `confidence`, `metadata`

回答中展示关系时，必须通过该表回到原始切片。

#### `graph_communities`

- `id`, `knowledge_base_id`, `level`
- `title`, `summary`, `rank`
- `embedding`, `metadata`
- `build_version`, `created_at`, `updated_at`

#### `graph_community_members`

- `community_id`, `entity_id`
- `membership_score`

### 5.2 任务与版本字段

为 `documents` 增加：

- `index_status`：`pending | chunking | embedding | extracting | resolving | ready | failed`
- `index_version`：本次索引版本
- `indexed_at`, `failure_reason`

图谱派生数据记录 `extractor_version` 或 `build_version`。删除文档时先删除其 mention/evidence，再重新计算失去全部证据的关系、实体和社区，避免残留孤立事实。

## 6. 模块边界

建议新增以下服务接口，防止所有逻辑继续集中在 `RagService`：

| 模块 | 职责 |
| --- | --- |
| `DocumentIngestionService` | 编排文档状态、切片、向量化和图谱构建任务。 |
| `GraphExtractionService` | 要求模型按 JSON Schema 输出实体、关系、声明与置信度。 |
| `EntityResolutionService` | 名称规范化、别名匹配、候选检索、合并判定。 |
| `GraphRepository` | 图节点/边/证据的读写接口，隔离具体存储。 |
| `CommunityDetectionService` | 构建社区层级、排名与社区摘要。 |
| `QueryAnalysisService` | 查询分类、查询实体识别和检索参数生成。 |
| `HybridRetrievalService` | 协调向量、关键词、图邻域和社区召回。 |
| `RetrievalFusionService` | 分数归一、RRF 融合、去重及可选重排。 |
| `ContextBuilder` | 在 token 预算内组装带稳定引用编号的证据。 |
| `GraphRagService` | Local/Global 问答编排和降级处理。 |

`AiClient` 增加结构化输出能力，但保持 HTTP 协议细节集中在客户端内部。模型不支持原生 JSON Schema 时，使用严格 JSON 提示、解析校验和有限次数修复重试。

## 7. 入库流水线

### 7.1 推荐流程

1. 创建文档并写入 `pending` 状态。
2. 规范化文本，按标题、段落和句子边界优先切片，并保留父子位置信息。
3. 批量生成切片向量。
4. 按切片或相邻切片窗口抽取实体、关系和事实声明。
5. 对抽取结果进行 Schema 校验；无效结果进入可重试失败记录。
6. 先用规范名称、类型和别名生成实体候选，再用描述相似度及模型判定消歧。
7. 写入 mention 和 relationship evidence，聚合实体与关系。
8. 将文档标记为 `ready`；社区重建可独立异步执行。

### 7.2 一致性与幂等

- 每个任务使用 `document_id + index_version + stage` 作为幂等键。
- 外部模型调用不放在长数据库事务中；每一阶段短事务提交。
- 重建同一版本前先清理该版本派生数据，防止重复边和 mention。
- 文档失败时保留失败阶段及可读错误，允许从指定阶段重试。
- 首版可以保留同步入口，但实际处理迁移至 Laravel Queue；API 返回 `202 Accepted` 和任务状态。

## 8. 检索与生成策略

### 8.1 Local Search

1. 分析问题并识别实体候选。
2. 通过名称、别名和实体向量找到图谱种子。
3. 进行最大 1～2 跳、限制节点数和边数的邻域扩展。
4. 同时执行原有切片向量召回和关键词召回。
5. 使用 Reciprocal Rank Fusion（RRF）融合多路结果。
6. 按实体覆盖率、关系置信度、证据新鲜度和切片相关度重排。
7. 只将有原文证据的实体与边放入最终上下文。

### 8.2 Global Search

1. 根据问题向量和关键词召回 Top-N 社区摘要。
2. 对每个社区生成带证据引用的局部回答及重要度分数。
3. 汇总局部回答，去除重复和冲突结论。
4. 生成最终答案，并保留到文档切片的引用链。

### 8.3 安全降级

- 图谱尚未构建：退化为当前向量 RAG。
- 查询未识别到可靠实体：以混合文本检索为主。
- 图证据与原文冲突：原文证据优先，并提示存在冲突。
- 上下文不足：明确回答“现有资料不足”，不允许根据图节点名称补写事实。

## 9. API 规划

保留现有接口：

```http
POST /api/knowledge-bases/{id}/documents
POST /api/knowledge-bases/{id}/query
```

文档导入响应逐步改为异步任务；查询接口新增可选参数：

```json
{
  "question": "……",
  "mode": "auto",
  "top_k": 8,
  "max_hops": 2,
  "include_graph": false
}
```

查询响应保留 `answer`、`model`、`sources`、`usage`，并可增加：

```json
{
  "mode": "local",
  "entities": [],
  "relationships": [],
  "retrieval": {
    "vector_hits": 8,
    "graph_hits": 12,
    "communities": 0
  }
}
```

新增管理接口建议：

- `GET /knowledge-bases/{id}/graph`：分页查看实体和关系。
- `GET /knowledge-bases/{id}/graph/entities/{entity}`：查看邻域及证据。
- `POST /knowledge-bases/{id}/graph/rebuild`：触发全量重建。
- `POST /documents/{document}/retry-index`：重试失败文档。
- `GET /documents/{document}/index-status`：查看处理阶段与错误。

管理后台后续增加图谱构建状态、失败重试、实体详情、关系证据和轻量图可视化；可视化不是首个可用版本的阻塞项。

## 10. 分阶段实施计划

### 阶段 0：基线与评测集

**工作项**

- 固化现有 RAG 行为与接口测试。
- 建立包含直接事实、跨切片、多跳、全局总结、无答案问题的最小评测集。
- 记录当前召回率、答案正确率、引用正确率、延迟与模型调用成本。

**验收标准**

- 核心 API 有鉴权、校验、成功、失败和知识库隔离测试。
- 评测脚本可重复运行并输出基线报告。

### 阶段 1：图谱 Schema 与抽取 MVP

**工作项**

- 新增实体、关系、mention 和 evidence 表及 Eloquent 模型。
- 定义抽取 DTO/JSON Schema、类型白名单和关系命名规则。
- 实现 `GraphExtractionService` 与确定性解析校验。
- 导入文档时生成有证据链的初始图谱。

**验收标准**

- 同一切片重复处理不产生重复 mention/edge evidence。
- 每条关系至少关联一个有效切片证据。
- 删除文档不会留下仅由该文档支持的事实。
- 模型输出异常可以重试并留下可诊断错误。

### 阶段 2：实体归一与增量索引

**工作项**

- 实现实体名称规范化、别名、候选召回和合并策略。
- 引入队列任务、分阶段状态机、幂等键和失败重试。
- 支持文档重新索引、删除后的图谱修复。

**验收标准**

- 同知识库常见别名能合并，重名不同实体不会仅凭名称强制合并。
- 任务重复执行结果一致。
- 单文档失败不回滚其他文档，也不阻塞整个知识库查询。

### 阶段 3：Local GraphRAG 与混合召回

**工作项**

- 实现查询实体识别、图谱种子召回和受限多跳遍历。
- 增加关键词召回、RRF 融合与上下文 token 预算。
- 扩展查询 API，保留 vector-only 降级路径。
- 返回图谱命中及其原始文档引用。

**验收标准**

- 多跳评测集相对阶段 0 有可量化提升。
- 所有答案引用均能解析到当前知识库的文档切片。
- 图为空、抽取失败或图检索超时时仍可返回向量 RAG 结果。
- 图遍历严格受 hop、节点数、边数和超时限制。

### 阶段 4：社区与 Global GraphRAG

**工作项**

- 选择并实现社区发现算法，首选可离线运行的 Leiden/Louvain 等方案。
- 生成分层社区摘要，保存摘要版本和来源成员。
- 实现 Map-Reduce 式全局问答及社区摘要增量失效机制。

**验收标准**

- 全局问题可覆盖多个社区而非只返回单一相似切片。
- 社区摘要可追溯到成员实体、关系和原始切片。
- 图谱变更后受影响摘要会标记过期并重建。

### 阶段 5：可观测性、后台与存储演进

**工作项**

- 增加各阶段耗时、成功率、重试率、token 和成本指标。
- 后台展示索引状态、图谱统计、失败原因和证据详情。
- 基于实测阈值评估迁移 PostgreSQL/pgvector 与图数据库。
- 补充备份、恢复、重建和数据一致性运维文档。

**验收标准**

- 可按知识库和文档定位处理瓶颈与失败阶段。
- 数据库迁移前后使用同一检索契约并通过回归测试。
- 完成容量、延迟与恢复演练报告。

## 11. 测试与评估方案

### 11.1 自动化测试

- 单元测试：文本规范化、抽取解析、名称归一、RRF、图遍历边界、上下文裁剪。
- Feature 测试：入库状态、幂等、删除清理、知识库隔离、API 兼容与降级。
- Contract 测试：模拟 OpenAI 兼容接口的正常、超时、限流、无效 JSON 和维度变化。
- 集成测试：小型固定语料构图后，断言实体、关系、证据和问答结果。

测试中使用 Fake AI 响应，避免默认依赖本机 Ollama；另设显式开启的真实模型测试。

### 11.2 质量指标

- **Entity/Relation Precision、Recall、F1**：抽取与归一质量。
- **Recall@K / MRR / nDCG**：检索质量。
- **Answer Correctness**：答案是否覆盖标准要点。
- **Citation Precision / Completeness**：引用是否真的支持结论，重要结论是否都有引用。
- **Abstention Accuracy**：无答案问题能否正确拒答。
- **P50/P95 latency**：入库各阶段及查询耗时。
- **Cost per document/query**：模型调用次数、token 和重试成本。

进入下一阶段前，应保存当前评测结果；GraphRAG 默认启用前，至少保证引用正确率和无答案拒答不低于向量基线。

## 12. 安全与数据治理

- 所有查询、关联和后台接口均按 `knowledge_base_id` 强制隔离。
- 文档内容属于不可信输入；抽取和问答提示必须防止文档内指令覆盖系统指令。
- 图谱描述、社区摘要和模型输出均视为派生数据，不作为绕过原文证据的可信来源。
- 日志不记录完整敏感文档和上游密钥；失败样本采用截断或脱敏内容。
- 限制文档大小、切片数、实体数、单次图遍历规模和并发任务数，防止资源耗尽。
- 删除文档时同步或通过可靠任务清理其全部证据和派生摘要。

## 13. 关键风险与应对

| 风险 | 应对策略 |
| --- | --- |
| LLM 抽取幻觉或格式不稳定 | 严格 Schema、置信度阈值、证据必填、有限重试、评测抽检。 |
| 实体误合并污染图谱 | 保守合并、记录别名与合并依据、支持拆分/重建。 |
| 图谱规模导致 SQLite 查询变慢 | 索引、邻域上限、离线社区计算；达到阈值后迁移存储。 |
| 入库成本和延迟显著上升 | 队列、批量 Embedding、缓存、按版本增量处理。 |
| 图结果看似合理但无原文支撑 | 强制 relationship evidence，生成阶段只接受可引用证据。 |
| 社区摘要随图谱变化而陈旧 | 版本号、脏标记、受影响社区重建。 |
| API 演进破坏现有调用方 | 保留字段与 vector 降级，新字段保持可选，增加契约测试。 |

## 14. 存储升级触发条件

不预先绑定特定产品，通过指标决定迁移：

- 单知识库切片达到数十万，PHP 全量余弦检索无法满足 P95 延迟目标。
- 图节点/边达到百万级，递归 SQL 或应用层遍历成为主要瓶颈。
- 需要高并发写入、复杂路径查询、跨机器扩容或在线图算法。

建议演进路径：

1. SQLite（开发/MVP）。
2. PostgreSQL + pgvector（统一事务、向量索引和中等规模图关系表）。
3. PostgreSQL/对象存储保存业务与证据，专业图数据库承担复杂遍历；由 `GraphRepository` 屏蔽差异。

## 15. 建议的首个开发切片

第一批实现控制在可独立验收的最小闭环：

1. 建立阶段 0 评测语料和现有 RAG 基线。
2. 新增四张核心图表：entities、relationships、mentions、relationship evidence。
3. 为 `AiClient` 增加可测试的结构化输出方法。
4. 完成单切片实体/关系抽取、Schema 校验和证据落库。
5. 完成文档删除与重复索引一致性测试。
6. 增加只读图谱调试接口，但暂不改变现有问答路径。

该切片完成后再进入实体归一和 GraphRAG 检索，可以先验证“图构得是否可信”，避免抽取、检索和生成问题相互混淆。

## 16. 开发完成定义（Definition of Done）

一个 GraphRAG 功能只有同时满足以下条件才视为完成：

- 数据库变更使用新 migration，并支持回滚。
- 服务边界清晰，控制器不包含抽取或图遍历业务逻辑。
- 关键成功、失败、重试、删除和隔离路径有自动化测试。
- 所有展示给模型的图事实都能回溯到文档切片。
- 新配置同步加入 `.env.example` 和项目文档。
- 提供可观测指标及可读错误，不依赖日志猜测任务状态。
- 通过固定评测集，结果与基线差异有记录。
- API 变更保持兼容，或明确采用新版本路径。
