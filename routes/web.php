<?php

use App\Http\Controllers\FilmDataController;
use App\Http\Controllers\TestsDBApisController;
use App\Jobs\ImportFilmsJob;

use App\Models\Film;
use App\Models\Post;
use App\Models\User;
use App\Models\UserEntry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;


// web.php -> contiene rutas de mantenimiento y utilidades de desarrollo, como rutas de funcionamiento "interno", no de uso "público" para usuarios de la app


Route::get('/', function () {
    return view('welcome');
});

// OG tags para bots de mensajería — solo llegan aquí via .htaccess
Route::get('/films/{id}', function ($id) {
    $film = Film::find((int) $id);
    if (!$film) abort(404);
    return response(view('og-film', compact('film')))->header('Cache-Control', 'public, max-age=3600');
})->where('id', '[0-9]+');

Route::get('/entry/{type}/{id}', function ($type, $id) {
    $entry = UserEntry::with(['user:id,name', 'films:idFilm,title,frame'])->find((int) $id);
    if (!$entry) abort(404);
    return response(view('og-entry', compact('entry')))->header('Cache-Control', 'public, max-age=3600');
})->where('id', '[0-9]+');

Route::get('/profile/{username}', function ($username) {
    $user = User::with('profile')->where('name', $username)->first();
    if (!$user) abort(404);
    return response(view('og-profile', compact('user')))->header('Cache-Control', 'public, max-age=3600');
});

Route::get('/news/{id}', function ($id) {
    $post = Post::with('films:idFilm,title,frame')->find((int) $id);
    if (!$post || !$post->visible) abort(404);
    return response(view('og-post', compact('post')))->header('Cache-Control', 'public, max-age=3600');
})->where('id', '[0-9]+');

Route::get('/post-reed/{id}', function ($id) {
    $post = Post::with('films:idFilm,title,frame')->find((int) $id);
    if (!$post || !$post->visible) abort(404);
    return response(view('og-post', compact('post')))->header('Cache-Control', 'public, max-age=3600');
})->where('id', '[0-9]+');

/*
|--------------------------------------------------------------------------
|  --RUTAS DE PRUEBA CONEXIÓN APIS Y LLENADO DE BD--
|--------------------------------------------------------------------------
*/
// --Rutas para probar traer datos desde API TMDB y API WIKIDATA vía Postman o similar, y comprobar la conexión a estas APIS-- //

// Ruta para obtener el token CSRF desde Postman o cliente externo
Route::get('/csrf-token', function () {
    return response()->json(['csrf_token' => csrf_token(),]);});
    
// Test TMDB + wikidata pero solo lectura, sin guardar en BD
Route::get('/films/test_tmdb/{year}', [TestsDBApisController::class, 'testTMDb'])->name('films.test.tmdb');


// Test Wikidata solo lectura, solo premios/nominaciones/festivales 
Route::get('/films/test_wikidata/{wikidataId}', [TestsDBApisController::class, 'testWikidata'])->name('films.test.wikidata');

// Test WikiData solo lectura, para saber si busca por título
Route::get('/wikidata/test_title_wikidata/{title}', [TestsDBApisController::class, 'testFindWikidataIdByTitle']);


/*
|--------------------------------------------------------------------------
|  --RUTAS IMPORTACIÓN DATOS A BD--
|--------------------------------------------------------------------------
*/
// Importación directa (síncrona) desde Postman — modo discover por defecto
// GET /films/import/2026-01-01/2026-12-31/1  → inserta films nuevos de la página 1
Route::post('/films/import/{dateFrom}/{dateTo}/{startPage?}/{endPage?}', [FilmDataController::class, 'importFromTMDB'])->name('films.import');

// Ruta manual para encolar jobs desde Postman/Web
// POST /films/import-queue/2026-01-01/2026-12-31/1/5?mode=discover
// mode=discover → onlyNew=true (release_date.desc) | mode=sync → onlyNew=false (popularity.desc)
Route::post('/films/import-queue/{dateFrom}/{dateTo}/{from}/{to}', function ($dateFrom, $dateTo, $from, $to) {
    $mode    = request('mode', 'discover');
    $onlyNew = $mode !== 'sync';
    $sortBy  = $onlyNew ? 'release_date.desc' : 'popularity.desc';

    $dispatched = 0;
    for ($p = (int) $from; $p <= (int) $to; $p++) {
        ImportFilmsJob::dispatch($dateFrom, $dateTo, $p, $sortBy, $onlyNew);
        $dispatched++;
    }
    return response()->json([
        'message' => "Jobs encolados: {$dispatched} páginas ({$from}→{$to}) | {$dateFrom}→{$dateTo} | modo: {$mode}",
    ]);
});



