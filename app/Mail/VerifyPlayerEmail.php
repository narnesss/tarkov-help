<?php
declare(strict_types=1);
namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};

/** Одноразовый код передаётся только почтовому транспорту и не ставится в очередь. */
final class VerifyPlayerEmail extends Mailable
{
    public function __construct(public readonly string $code) {}
    public function envelope(): Envelope
    {
        return new Envelope(subject:'Tarkov Compass - Email verification code');
    }
    public function content(): Content { return new Content(text:'emails.verify-player-email'); }
}
