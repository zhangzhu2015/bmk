<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UserLoginRequest extends FormRequest
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
            'username'   => [
                'required',
                'exists:user',
            ],
            'password' => 'required|string|min:6|max:20',
        ];
    }

    public function messages()
    {
        return [
            'password.required'=> 'message.TEXT_001'
        ];
    }
}
