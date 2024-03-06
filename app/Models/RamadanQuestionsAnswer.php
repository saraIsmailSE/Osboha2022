<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RamadanQuestionsAnswer extends Model
{
    protected $fillable = [
        'ramadan_question_id',
        'user_id',
        'status',
        'points',
        'reviews',
        'reviewer_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function ramadanQuestion()
    {
        return $this->belongsTo(RamadanQuestion::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
