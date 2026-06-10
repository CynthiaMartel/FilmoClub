<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $table = 'post';
    protected $fillable = ['idUser','title','subtitle','content','img','visible','editorName'];

    public function user()
    {
        return $this->belongsTo(User::class, 'idUser');
    }

    public function films()
    {
        return $this->belongsToMany(Film::class, 'post_films', 'post_id', 'film_id')
                    ->withPivot('order')
                    ->orderBy('post_films.order');
    }
}

