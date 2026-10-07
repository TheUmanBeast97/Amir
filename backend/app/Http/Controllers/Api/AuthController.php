<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // l'email si salva e si cerca sempre in minuscolo: «Edoardo@…» e «edoardo@…» sono la stessa persona
        $user = User::where('email', Str::lower($data['email']))->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Credenziali non valide.']]);
        }

        return $this->ok([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->present($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return $this->ok(new \stdClass);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok($this->present($request->user()));
    }

    /** @return array{id:int,name:string,email:string} */
    private function present(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];
    }
}
