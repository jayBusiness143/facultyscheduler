<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules()
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string',
        ];
    }

    /**
     * Attempt to authenticate the user using the provided credentials.
     */
    public function attemptLogin()
    {
        return Auth::attempt($this->only('email', 'password'));
    }

    /**
     * Check if the email exists in the database.
     */
    public function emailExists()
    {
        return User::where('email', $this->input('email'))->exists();
    }
}