<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class RestPasswordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'old_password'=> [
                'required',
                'password'
            ],
            'password'=> 'required|min:6|max:32|confirmed',
            'password_confirmation'=> 'required',
        ];
    }
    
    public function messages() {
        return [
            'old_password.required'=> "Old password can't be empty",
            'old_password.password'=> "Old password is error",
            'password.required'=> "New password can't be empty",
            'password.min'=> 'Incorrect password(6-32 characters).',
            'password.max'=> 'Incorrect password(6-32 characters).',
            'password.confirmed'=> '两次密码不一样',
            'password_confirmation.required'=> "New password can't be empty",
        ];
    }
}
