<?php

namespace App\Http\Requests;

use Laravel\Fortify\Http\Requests\VerifyEmailRequest;

/** Fortify's check, with the email hash in {token} rather than {hash}. */
class VerifyStudentEmailRequest extends VerifyEmailRequest
{
    public function authorize(): bool
    {
        if (! hash_equals((string) $this->user()->getKey(), (string) $this->route('id'))) {
            return false;
        }

        $token = (string) ($this->route('token') ?? $this->route('hash'));

        return hash_equals(sha1($this->user()->getEmailForVerification()), $token);
    }
}
