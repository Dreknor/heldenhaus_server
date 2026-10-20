<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRecipe extends Model
{
    protected $fillable = ['user_id', 'recipe_key', 'source'];
}
