@php
    $profile = $user->profile;
    $avatar  = $profile?->avatar ?? null;
    $bio     = Str::limit($profile?->bio ?? '', 200);
    $films   = $profile?->films_seen ?? 0;
    $desc    = $bio ?: ($films ? "Ha visto {$films} películas en FilmoClub." : "Perfil de {$user->name} en FilmoClub.");
    $url     = url("/profile/{$user->name}");
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $user->name }} — FilmoClub</title>

    <!-- Open Graph -->
    <meta property="og:type" content="profile">
    <meta property="og:site_name" content="FilmoClub">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:title" content="{{ $user->name }} en FilmoClub">
    <meta property="og:description" content="{{ $desc }}">
    @if($avatar)
    <meta property="og:image" content="{{ $avatar }}">
    @endif

    <!-- Twitter / X Card -->
    <meta name="twitter:card" content="{{ $avatar ? 'summary' : 'summary' }}">
    <meta name="twitter:title" content="{{ $user->name }} en FilmoClub">
    <meta name="twitter:description" content="{{ $desc }}">
    @if($avatar)
    <meta name="twitter:image" content="{{ $avatar }}">
    @endif
</head>
<body>
    <script>window.location.replace('/profile/{{ $user->name }}');</script>
</body>
</html>
