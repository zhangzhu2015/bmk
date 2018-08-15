<?php

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
		Schema::defaultStringLength(191);
        Validator::extend('password', function ($attribute, $value, $parameters, $validator) {
            return DB::table('user')->where('password', md5($value))->where('id', Auth::id())->exists();
        });

        Validator::extend('is_mobile', function ($attribute, $value, $parameters, $validator) {
            if(!$value || strlen($value) < 10){
                return false;
            }
            if((int)substr($value , 0 , 1) === 0){
                $value = substr($value , 1);
            }
            return preg_match('/^9[0-9]\d{8}$/',$value);
        });
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {

    }
}
