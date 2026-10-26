<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    /**
     * Checks the credentials. Users with two-factor enabled are not logged in
     * here: the user is returned so the second step can finish the login.
     */
    public function authenticate(): ?User
    {
        $this->ensureIsNotRateLimited();

        $guard = Auth::guard('web');
        $credentials = $this->credentials();
        $user = $guard->getProvider()->retrieveByCredentials($credentials);

        if ($user instanceof User && $user->hasTwoFactorEnabled() && $guard->validate($credentials)) {
            RateLimiter::clear($this->throttleKey());

            return $user;
        }

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return null;
    }

    /**
     * Credentials for the attempt. On a company's subdomain only that company's
     * users can sign in; inactive users can never sign in.
     */
    protected function credentials(): array
    {
        $credentials = $this->only('email', 'password');
        $tenantId = app(TenantManager::class)->getTenantId();

        $credentials[] = function ($query) use ($tenantId) {
            $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'active'));

            if ($tenantId) {
                $query->where('company_id', $tenantId);
            }
        };

        return $credentials;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
