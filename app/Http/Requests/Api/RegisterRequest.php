<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'nic_number' => ['required', 'string', 'max:20', 'unique:users,nic_number'],
            'nic_photo_front' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:6144'],
            'nic_photo_back' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:6144'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
