<?php

namespace App\Rules;

use App\Services\PasswordPolicyService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * One shared complexity rule, referenced by every password-set path:
 *
 *   'new_password' => ['required', 'string', new PasswordPolicy()]
 *
 * Anything added to PasswordPolicyService::complexityError() is therefore
 * enforced everywhere at once — no per-form copies to drift apart.
 */
class PasswordPolicy implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($err = PasswordPolicyService::complexityError(is_string($value) ? $value : null)) {
            $fail($err);
        }
    }
}
