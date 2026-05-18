<?php

namespace App\Http\Controllers;

use App\Models\UserComment;
use App\Models\UserEntry;
use App\Models\UserFriend;
use App\Models\Film;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;

class UserCommentController extends Controller
{
    public function index(string $type, int $id): JsonResponse
    {
        $authUser = Auth::user();
        $modelClass = $this->resolveModel($type);

        if (!$modelClass) {
            return response()->json(['error' => 'Tipo de entidad no válido.'], 400);
        }

        $query = UserComment::where('commentable_type', $modelClass)
            ->where('commentable_id', $id)
            ->with(['user:id,name', 'user.profile:user_id,avatar'])
            ->orderBy('created_at', 'desc');

        if ($authUser) {
            $query->withExists(['likes as i_liked' => fn($q) => $q->where('user_id', $authUser->id)]);
        }

        $comments = $query->get();

        return response()->json([
            'success' => true,
            'total' => $comments->count(),
            'data' => $comments,
        ], 200);
    }

    public function store(Request $request, string $type, int $id): JsonResponse
    {
        $user = Auth::user();
        $modelClass = $this->resolveModel($type);

        if (!$modelClass) {
            return response()->json(['error' => 'Tipo de entidad no válido.'], 400);
        }

        $request->validate([
            'comment' => 'required|string|max:1000',
            'visibility' => 'in:public,friends,private',
        ]);

        $comment = UserComment::create([
            'user_id' => $user->id,
            'commentable_id' => $id,
            'commentable_type' => $modelClass,
            'comment' => $request->comment,
            'visibility' => $request->visibility ?? 'public',
        ]);

        $comment->load(['user:id,name', 'user.profile:user_id,avatar']);
        $comment->i_liked = false;

        return response()->json([
            'success' => true,
            'message' => 'Comentario publicado correctamente.',
            'data' => $comment,
        ], 201);
    }

    public function destroy(int $commentId): JsonResponse
    {
        $user = Auth::user();
        $comment = UserComment::findOrFail($commentId);

        if ($comment->user_id !== $user->id && !$user->isAdmin()) {
            return response()->json(['error' => 'No tienes permiso para eliminar este comentario.'], 403);
        }

        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Comentario eliminado correctamente.',
        ], 200);
    }

    public function toggleLike(int $commentId): JsonResponse
    {
        $comment = UserComment::findOrFail($commentId);
        return response()->json($comment->toggleLike(Auth::id()));
    }

    public function communityFilmComments(): JsonResponse
    {
        $userId = Auth::id();

        $followedIds = UserFriend::where('follower_id', $userId)
            ->where('status', 'accepted')
            ->pluck('followed_id');

        if ($followedIds->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $comments = UserComment::whereIn('user_id', $followedIds)
            ->where('commentable_type', Film::class)
            ->whereIn('visibility', ['public', 'friends'])
            ->where('status', 'approved')
            ->whereHas('commentable')
            ->with(['user:id,name', 'user.profile:user_id,avatar', 'commentable'])
            ->withExists(['likes as i_liked' => fn($q) => $q->where('user_id', $userId)])
            ->orderBy('created_at', 'desc')
            ->take(8)
            ->get();

        $data = $comments->map(fn($c) => [
            'id'          => $c->id,
            'comment'     => $c->comment,
            'likes_count' => $c->likes_count,
            'i_liked'     => (bool) $c->i_liked,
            'created_at'  => $c->created_at,
            'user'        => [
                'id'      => $c->user->id,
                'name'    => $c->user->name,
                'profile' => ['avatar' => $c->user->profile->avatar ?? null],
            ],
            'film'        => [
                'idFilm' => $c->commentable->idFilm,
                'title'  => $c->commentable->title,
                'frame'  => $c->commentable->frame,
            ],
        ]);

        return response()->json(['data' => $data]);
    }

    private function resolveModel(string $type): ?string
    {
        return match ($type) {
            'film'  => Film::class,
            'entry' => UserEntry::class,
            default => null,
        };
    }
}
