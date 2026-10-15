<?php
declare(strict_types=1);
namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};
use Symfony\Component\Mime\Email;

/** Одноразовый код передаётся только почтовому транспорту и не ставится в очередь. */
final class VerifyPlayerEmail extends Mailable
{
    public function __construct(public readonly string $code) {}
    public function envelope(): Envelope
    {
        return new Envelope(subject:'Код подтверждения e-mail — Tarkov Compass',using:[static function(Email $message): void {
            $subject=(string)$message->getSubject();
            $message->getHeaders()->remove('Subject');
            $message->getHeaders()->add(new Utf8SubjectHeader('Subject',$subject));
        }]);
    }
    public function content(): Content { return new Content(text:'emails.verify-player-email'); }
}
