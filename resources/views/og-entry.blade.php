@php
    $typeLabels = ['user_list' => 'Lista', 'user_debate' => 'Debate', 'user_review' => 'Reseña'];
    $typeLabel  = $typeLabels[$entry->type] ?? 'Entrada';
    $author     = $entry->user->name ?? 'FilmoClub';
    $title      = $entry->title ?: "{$typeLabel} de {$author}";
    $desc       = Str::limit(strip_tags($entry->content ?? ''), 200);
    $image      = $entry->cover_image ?? $entry->films->first()?->frame ?? null;
    $url        = url("/entry/{$entry->type}/{$entry->id}");
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }} — FilmoClub</title>

    <!-- Open Graph -->
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="FilmoClub">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $desc ?: "{$typeLabel} de {$author} en FilmoClub" }}">
    @if($image)
    <meta property="og:image" content="{{ $image }}">
    @endif

    <!-- Twitter / X Card -->
    <meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $desc ?: "{$typeLabel} de {$author} en FilmoClub" }}">
    @if($image)
    <meta name="twitter:image" content="{{ $image }}">
    @endif
</head>
<body>
    <script>window.location.replace('/entry/{{ $entry->type }}/{{ $entry->id }}');</script>
</body>
</html>
