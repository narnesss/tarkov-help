<?php
declare(strict_types=1);
namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};

/** Код восстановления существует открыто только в памяти и отправляемом письме. */
final class ResetPlayerPassword extends Mailable
{
    public function __construct(public readonly string $code) {}
    public function envelope(): Envelope { return new Envelope(subject:'Tarkov Compass - Password reset code'); }
    public function content(): Content { return new Content(text:'emails.reset-player-password'); }
}
