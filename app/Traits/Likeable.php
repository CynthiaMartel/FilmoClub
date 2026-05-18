<?php

namespace App\Traits;

trait Likeable
{
    public function toggleLike(int $userId): array
    {
        $like = $this->likes()->where('user_id', $userId)->first();

        if ($like) {
            $like->delete();
            $this->decrement('likes_count');
            return ['likes_count' => $this->likes_count, 'i_liked' => false];
        }

        $this->likes()->create(['user_id' => $userId]);
        $this->increment('likes_count');
        return ['likes_count' => $this->likes_count, 'i_liked' => true];
    }
}
