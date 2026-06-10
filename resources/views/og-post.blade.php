<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $post->title }} — FilmoClub News</title>

    <!-- Open Graph (WhatsApp, Telegram, Facebook, LinkedIn…) -->
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="FilmoClub">
    <meta property="og:url" content="{{ url('/post-reed/' . $post->id) }}">
    <meta property="og:title" content="{{ $post->title }}">
    <meta property="og:description" content="{{ Str::limit(strip_tags($post->subtitle ?: $post->content), 200) }}">
    @if($post->img)
    <meta property="og:image" content="{{ $post->img }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    @elseif($post->films->isNotEmpty() && $post->films->first()->frame)
    <meta property="og:image" content="{{ $post->films->first()->frame }}">
    <meta property="og:image:width" content="500">
    <meta property="og:image:height" content="750">
    @endif
    <meta property="article:published_time" content="{{ $post->created_at->toIso8601String() }}">
    @if($post->editorName)
    <meta property="article:author" content="{{ $post->editorName }}">
    @endif

    <!-- Twitter / X Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $post->title }}">
    <meta name="twitter:description" content="{{ Str::limit(strip_tags($post->subtitle ?: $post->content), 200) }}">
    @if($post->img)
    <meta name="twitter:image" content="{{ $post->img }}">
    @elseif($post->films->isNotEmpty() && $post->films->first()->frame)
    <meta name="twitter:image" content="{{ $post->films->first()->frame }}">
    @endif
</head>
<body>
    <script>window.location.replace('/post-reed/{{ $post->id }}');</script>
</body>
</html>
