<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class QuotaActivity extends Model
{
	//
	public function getOne(){
		$result = DB::table('quota_activity') -> select('id','user_start_time','user_end_time') 
		-> where('is_open_buyer', 1) 
		-> where('is_open', 1)
		-> where('status',1)-> first();
		return $result;
	}
	
}

?>