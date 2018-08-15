<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Help extends Model
{
    //
    protected $guarded = [];
    protected $table = 'help';

    public $timestamps = false;


    public function getDatelineAttribute($dateline) {
        return $this->dateline = date('Y-m-d H:i:s', $dateline);
    }

}
