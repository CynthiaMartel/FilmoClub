<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mews\Purifier\Facades\Purifier;

class PostController extends Controller
{
    // MOSTRAR todos los posts
    public function index(Request $request)
    {
        $user = auth('sanctum')->user();

        $search = $request->input('search');
        $query = Post::with(['user:id,name', 'films:idFilm,title,frame']);

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                  ->orWhere('subtitle', 'LIKE', "%{$search}%")
                  ->orWhere('content', 'LIKE', "%{$search}%");
            });
        }

        $query->orderBy('created_at', 'desc');

        $canSeeEverything = false;

        if ($user) {
            if ($user->idRol == 1 || $user->idRol == 2 || (method_exists($user, 'isAdmin') && ($user->isAdmin() || $user->isEditor()))) {
                $canSeeEverything = true;
            }
        }

        if (!$canSeeEverything) {
            $query->where('visible', 1);
        }

        $posts = $query->get();

        return response()->json($posts);
    }

    // GUARDAR un nuevo post
    public function store(Request $request)
    {
        $user = auth('sanctum')->user();

        if (!$user || !($user->idRol == 1 || $user->idRol == 2 || (method_exists($user, 'isAdmin') && ($user->isAdmin() || $user->isEditor())))) {
            return response()->json(['message' => 'No tienes permisos para crear posts.'], 403);
        }

        $validated = $request->validate([
            'title'      => 'required|string|max:255',
            'subtitle'   => 'nullable|string|max:255',
            'content'    => 'required|string',
            'img'        => ['nullable', 'url', 'max:500', function ($attr, $value, $fail) {
                $host = (string) parse_url($value, PHP_URL_HOST);
                if (preg_match('/^(localhost|127\.|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|::1$|0\.0\.0\.0)/i', $host)) {
                    $fail('La URL de imagen no puede apuntar a direcciones de red internas.');
                }
            }],
            'visible'    => 'boolean',
            'editorName' => 'nullable|string|max:150',
            'film_ids'   => 'nullable|array|max:10',
            'film_ids.*' => 'integer|exists:films,idFilm',
        ]);

        $validated['content'] = Purifier::clean($validated['content'], 'posts');
        $validated['idUser']  = $user->id;

        $filmIds = $validated['film_ids'] ?? [];
        unset($validated['film_ids']);

        $post = Post::create($validated);

        if (!empty($filmIds)) {
            $syncData = [];
            foreach ($filmIds as $order => $filmId) {
                $syncData[$filmId] = ['order' => $order];
            }
            $post->films()->sync($syncData);
        }

        $post->load(['user:id,name', 'films:idFilm,title,frame']);

        return response()->json([
            'message' => 'Post creado correctamente.',
            'post' => $post
        ], 201);
    }

    // MOSTRAR un solo post por ID
    public function show($id)
    {
        $post = Post::with(['user:id,name', 'films:idFilm,title,frame'])->findOrFail($id);

        $user = auth('sanctum')->user();

        $isAdminOrEditor = $user && ($user->idRol == 1 || $user->idRol == 2 || (method_exists($user, 'isAdmin') && ($user->isAdmin() || $user->isEditor())));

        if (!$post->visible && !$isAdminOrEditor) {
            return response()->json(['message' => 'No tienes permiso para ver este post.'], 403);
        }

        return response()->json($post);
    }

    // ACTUALIZAR un post existente
    public function update(Request $request, $id)
    {
        $user = auth('sanctum')->user();

        if (!$user || !($user->idRol == 1 || $user->idRol == 2 || (method_exists($user, 'isAdmin') && ($user->isAdmin() || $user->isEditor())))) {
            return response()->json(['message' => 'No tienes permisos para actualizar posts.'], 403);
        }

        $post = Post::findOrFail($id);

        $validated = $request->validate([
            'title'      => 'required|string|max:255',
            'subtitle'   => 'nullable|string|max:255',
            'content'    => 'required|string',
            'img'        => ['nullable', 'url', 'max:500', function ($attr, $value, $fail) {
                $host = (string) parse_url($value, PHP_URL_HOST);
                if (preg_match('/^(localhost|127\.|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|::1$|0\.0\.0\.0)/i', $host)) {
                    $fail('La URL de imagen no puede apuntar a direcciones de red internas.');
                }
            }],
            'visible'    => 'boolean',
            'editorName' => 'nullable|string|max:150',
            'film_ids'   => 'nullable|array|max:10',
            'film_ids.*' => 'integer|exists:films,idFilm',
        ]);

        $validated['content'] = Purifier::clean($validated['content'], 'posts');

        $filmIds = $validated['film_ids'] ?? [];
        unset($validated['film_ids']);

        $post->update($validated);

        $syncData = [];
        foreach ($filmIds as $order => $filmId) {
            $syncData[$filmId] = ['order' => $order];
        }
        $post->films()->sync($syncData);

        $post->load(['user:id,name', 'films:idFilm,title,frame']);

        return response()->json([
            'message' => 'Post actualizado correctamente.',
            'post' => $post
        ]);
    }

    // ELIMINAR un post
    public function destroy($id)
    {
        $user = auth('sanctum')->user();

        if (!$user || !($user->idRol == 1 || $user->idRol == 2 || (method_exists($user, 'isAdmin') && ($user->isAdmin() || $user->isEditor())))) {
            return response()->json(['message' => 'No tienes permisos para eliminar posts.'], 403);
        }

        $post = Post::findOrFail($id);
        $post->delete();

        return response()->json(['message' => 'Post eliminado correctamente.']);
    }
}
