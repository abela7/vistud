<?php

namespace App\Identity;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Delivered after commit, never queued or logged with its bearer token. */
final class InvitationMail extends Notification
{
    public function __construct(private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your ViStud invitation')
            ->line('Create your student account. This link works once and expires in 72 hours.')
            ->action('Join ViStud', route('invitations.show').'#'.$this->token);
    }
}
