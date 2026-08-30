@extends('layouts.admin')
@section('title', $entity->canonical_name)
@section('content')
<p><a class="back-link" href="{{ route('admin.bases.show', $knowledgeBase) }}">← 返回 {{ $knowledgeBase->name }}</a></p>

<div class="stats-grid">
    <section class="card"><div class="muted">类型</div><div class="stat small">{{ $entity->type }}</div></section>
    <section class="card"><div class="muted">提及</div><div class="stat">{{ $entity->mentions->count() }}</div></section>
    <section class="card"><div class="muted">出边</div><div class="stat">{{ $entity->outgoingRelationships->count() }}</div></section>
    <section class="card"><div class="muted">入边</div><div class="stat">{{ $entity->incomingRelationships->count() }}</div></section>
</div>

<section class="card">
    <h2>实体信息</h2>
    <p>{{ $entity->description ?: '暂无描述。' }}</p>
    <div class="muted">规范名称：{{ $entity->canonical_name }}</div>
    <div class="muted">别名：{{ implode('、', $entity->aliases ?? []) ?: '无' }}</div>
</section>

<div class="two-column">
    <section class="card">
        <h2>关系与证据</h2>
        @php
            $relationships = $entity->outgoingRelationships
                ->map(fn ($relationship) => ['direction' => '→', 'other' => $relationship->targetEntity, 'relation' => $relationship])
                ->concat($entity->incomingRelationships->map(fn ($relationship) => ['direction' => '←', 'other' => $relationship->sourceEntity, 'relation' => $relationship]));
        @endphp
        @forelse($relationships as $item)
            <div class="item">
                <strong>{{ $item['direction'] }} {{ $item['relation']->type }} · {{ $item['other']->canonical_name }}</strong>
                <span class="muted">置信度 {{ $item['relation']->confidence }}</span>
                @foreach($item['relation']->evidence as $evidence)
                    <blockquote>{{ $evidence->statement }}<br><small class="muted">{{ $evidence->chunk->document->title }}</small></blockquote>
                @endforeach
            </div>
        @empty
            <p class="muted">暂无关系。</p>
        @endforelse
    </section>

    <section class="card">
        <h2>原文提及</h2>
        @forelse($entity->mentions as $mention)
            <div class="item">
                <strong>{{ $mention->surface_form }}</strong> <span class="muted">{{ $mention->chunk->document->title }}</span>
                <p class="excerpt">{{ $mention->chunk->content }}</p>
            </div>
        @empty
            <p class="muted">暂无提及证据。</p>
        @endforelse
    </section>
</div>
@endsection
