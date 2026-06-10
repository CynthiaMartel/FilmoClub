<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $film->title }}{{ $film->release_date ? ' (' . $film->release_date->year . ')' : '' }} — FilmoClub</title>

    <!-- Open Graph (WhatsApp, Telegram, Facebook, LinkedIn…) -->
    <meta property="og:type" content="video.movie">
    <meta property="og:site_name" content="FilmoClub">
    <meta property="og:url" content="{{ url('/films/' . $film->idFilm) }}">
    <meta property="og:title" content="{{ $film->title }}{{ $film->release_date ? ' (' . $film->release_date->year . ')' : '' }}">
    <meta property="og:description" content="{{ Str::limit($film->overview_es ?: $film->overview ?: '', 200) }}">
    @if($film->frame)
    <meta property="og:image" content="{{ $film->frame }}">
    <meta property="og:image:width" content="500">
    <meta property="og:image:height" content="750">
    @elseif($film->backdrop)
    <meta property="og:image" content="{{ $film->backdrop }}">
    <meta property="og:image:width" content="1280">
    <meta property="og:image:height" content="720">
    @endif

    <!-- Twitter / X Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $film->title }}{{ $film->release_date ? ' (' . $film->release_date->year . ')' : '' }}">
    <meta name="twitter:description" content="{{ Str::limit($film->overview_es ?: $film->overview ?: '', 200) }}">
    @if($film->frame || $film->backdrop)
    <meta name="twitter:image" content="{{ $film->frame ?: $film->backdrop }}">
    @endif
</head>
<body>
    <script>window.location.replace('/films/{{ $film->idFilm }}');</script>
</body>
</html>
