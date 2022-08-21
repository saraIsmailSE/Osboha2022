<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasFactory;
    protected $fillable = [
        'media',
        'post_id',
        'comment_id',
        'reaction_id',
        'infographic_series_id',
        'infographic_id ',
        'book_id',
        'group_id',
        'type' => 'required',
        'user_id' => 'required',


    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function posts()
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
    public function comments()
    {
        return $this->belongsTo(Comment::class, 'comment_id');
    }
    public function reaction()
    {
        return $this->belongsTo(Reaction::class, 'reaction_id');
    }
    public function infographics()
    {
        return $this->belongsTo(Infographic::class, 'infographic_id');
    }
    public function series()
    {
        return $this->belongsTo(InfographicSeries::class, 'infographic_series_id');
    }
}