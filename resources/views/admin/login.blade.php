<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>登录 · Loop RAG</title>
<style>body{margin:0;display:grid;place-items:center;min-height:100vh;background:#08111d;color:#e8f0fa;font:15px system-ui}.box{width:min(400px,90vw);background:#101d2c;border:1px solid #203249;border-radius:16px;padding:30px}h1{margin:0 0 5px;color:#56d6b2}p{color:#8fa3ba}input{box-sizing:border-box;width:100%;padding:12px;margin:6px 0 14px;background:#081522;border:1px solid #2c4057;border-radius:9px;color:white}button{width:100%;padding:12px;border:0;border-radius:9px;background:#56d6b2;color:#062019;font-weight:800}.error{color:#ff9b9b}</style></head>
<body><form class="box" method="post" action="{{ route('admin.login.store') }}">@csrf
<h1>Loop RAG</h1><p>AI 服务管理控制台</p>
@if($errors->any())<div class="error">{{ $errors->first() }}</div>@endif
<label for="username">用户名</label><input id="username" name="username" value="{{ old('username') }}" required autofocus>
<label for="password">密码</label><input id="password" type="password" name="password" required>
<button>登录</button></form></body></html>
