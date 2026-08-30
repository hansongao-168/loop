@extends('layouts.admin')
@section('title', $knowledgeBase->name)
@section('content')
<div class="stats-grid">
    <section class="card"><div class="muted">文档</div><div class="stat">{{ $knowledgeBase->documents->count() }}</div></section>
    <section class="card"><div class="muted">实体</div><div class="stat">{{ $graphStats['entities'] }}</div></section>
    <section class="card"><div class="muted">关系</div><div class="stat">{{ $graphStats['relationships'] }}</div></section>
    <section class="card"><div class="muted">社区</div><div class="stat">{{ $graphStats['communities'] }}</div></section>
</div>

<div class="two-column">
    <section class="card">
        <h2>导入文档</h2>
        <form method="post" action="{{ route('admin.documents.store', $knowledgeBase) }}">
            @csrf
            <label>标题</label><input name="title" value="{{ old('title') }}" required>
            <label>来源 URL（可选）</label><input name="source" value="{{ old('source') }}">
            <label>文档正文</label><textarea name="content" required placeholder="粘贴 Markdown、说明文档或纯文本">{{ old('content') }}</textarea>
            <label class="check"><input type="checkbox" name="async" value="1" checked> 异步索引（推荐大文档）</label>
            <button class="btn">导入文档</button>
        </form>
    </section>

    <section class="card">
        <h2>RAG 测试</h2>
        <form method="post" action="{{ route('admin.query', $knowledgeBase) }}">
            @csrf
            <label>查询模式</label>
            <select name="mode">
                @foreach(['auto' => 'Auto', 'local' => 'Local GraphRAG', 'global' => 'Global GraphRAG', 'vector' => 'Vector'] as $value => $label)
                    <option value="{{ $value }}" @selected(($queryMode ?? 'auto') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <label>问题</label><textarea name="question" required placeholder="根据当前知识库提问">{{ $question ?? '' }}</textarea>
            <button class="btn">开始问答</button>
        </form>
        @if(isset($queryError))<div class="error">{{ $queryError }}</div>@endif
        @if(isset($result) && $result)
            <div class="result-head"><h2>回答</h2><span class="badge">{{ $result['mode'] }}</span></div>
            <div class="answer">{{ $result['answer'] }}</div>
            <h2 class="section-title">来源</h2>
            @foreach($result['sources'] as $source)
                <div class="item">
                    <strong>[{{ $source['index'] }}] {{ $source['title'] }}</strong>
                    <span class="muted">{{ implode(' + ', $source['channels'] ?? []) }}</span><br>
                    <small>{{ $source['excerpt'] }}</small>
                </div>
            @endforeach
        @endif
    </section>
</div>

<section class="card">
    <div class="row between">
        <div><h2>文档与索引</h2><span class="muted">异步任务需要 queue worker</span></div>
        <span class="muted">{{ $knowledgeBase->documents->count() }} 个</span>
    </div>
    @forelse($knowledgeBase->documents as $doc)
        <div class="item row between wrap">
            <span class="grow">
                <strong>{{ $doc->title }}</strong>
                <span class="status status-{{ $doc->status }}">{{ $doc->status }}</span><br>
                <small class="muted">{{ $doc->chunks_count }} 个切片 · 索引版本 {{ $doc->index_version }} · {{ $doc->source ?: '无来源地址' }}</small>
                @if($doc->failure_reason)<br><small class="danger-text">{{ $doc->failure_reason }}</small>@endif
            </span>
            <div class="row">
                @if($doc->status === 'failed')
                    <form method="post" action="{{ route('admin.documents.retry', $doc) }}">@csrf<button class="btn subtle">重试</button></form>
                @endif
                <form method="post" action="{{ route('admin.documents.destroy', $doc) }}" onsubmit="return confirm('确认删除此文档？')">
                    @csrf @method('DELETE')<button class="btn danger">删除</button>
                </form>
            </div>
        </div>
    @empty
        <p class="muted">暂无文档。</p>
    @endforelse
</section>

<div class="two-column wide-left">
    <section class="card">
        <div class="row between wrap">
            <div><h2>知识图谱实体</h2><span class="muted">Graph version {{ $graphStats['graph_version'] }}</span></div>
            <form method="get" class="search-form">
                <input name="entity_search" value="{{ $entitySearch }}" placeholder="搜索实体名称">
                <button class="btn subtle">搜索</button>
            </form>
        </div>
        @forelse($entities as $entity)
            <a class="item entity-row" href="{{ route('admin.entities.show', [$knowledgeBase, $entity]) }}">
                <span><strong>{{ $entity->canonical_name }}</strong><br><small class="muted">{{ $entity->type }} · {{ $entity->mentions_count }} 次提及</small></span>
                <span class="muted">{{ $entity->incoming_relationships_count + $entity->outgoing_relationships_count }} 条关系 →</span>
            </a>
        @empty
            <p class="muted">暂无匹配实体。启用 GraphRAG 并重新索引文档后显示。</p>
        @endforelse
        @if($entities->hasPages())
            <div class="pager">
                @if($entities->onFirstPage())<span class="muted">上一页</span>@else<a href="{{ $entities->previousPageUrl() }}">上一页</a>@endif
                <span>{{ $entities->currentPage() }} / {{ $entities->lastPage() }}</span>
                @if($entities->hasMorePages())<a href="{{ $entities->nextPageUrl() }}">下一页</a>@else<span class="muted">下一页</span>@endif
            </div>
        @endif
    </section>

    <section class="card">
        <div class="row between"><h2>社区构建</h2>
            <form method="post" action="{{ route('admin.communities.rebuild', $knowledgeBase) }}">@csrf<button class="btn subtle">异步重建</button></form>
        </div>
        @forelse($communityBuilds as $build)
            <div class="item">
                <strong>#{{ $build->id }}</strong> <span class="status status-{{ $build->status }}">{{ $build->status }}</span><br>
                <small class="muted">图版本 {{ $build->graph_version }} · {{ $build->communities_count ?? '—' }} 个社区</small>
                @if($build->failure_reason)<br><small class="danger-text">{{ $build->failure_reason }}</small>@endif
            </div>
        @empty
            <p class="muted">暂无社区构建记录。</p>
        @endforelse
    </section>
</div>
@endsection
