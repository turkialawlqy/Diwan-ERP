<?php

namespace App\Services\Accounting;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A business-rule failure while posting (unbalanced entry, closed period,
 * missing account mapping...). Rendered to users as a validation error.
 */
class AccountingException extends RuntimeException
{
    public function toValidation(string $field = 'accounting'): ValidationException
    {
        return ValidationException::withMessages([$field => $this->getMessage()]);
    }
}
