<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Eltern schalten eine Funktion für ihr Kind früher (early) oder später (defer) frei (§13.1). */
class MemberFeatureOverride extends Model
{
    protected $fillable = ['user_id', 'feature_key', 'mode', 'set_by'];
}
