<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/** The confirmation link. The hash is the route's {token}, which the acceptance suite can fill. */
class VerifyStudentEmail extends VerifyEmail
{
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'token' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }
}
