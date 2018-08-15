<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class JoinCartRequest extends FormRequest
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
            'type'=> 'required',
            'id'=> [
                'required'
            ],
            'num'=> 'required',
        ];
    }

    public function messages() {
        return [
            'type.required' => "Product type can't be empty",
            'id.required' => "Product Id can't be empty",
            'num.required' => "Product Qty can't be empty",
        ];
    }
}
