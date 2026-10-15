<?php
declare(strict_types=1);
namespace App\Mail;

use Symfony\Component\Mime\Header\UnstructuredHeader;

/** Явный UTF-8 Base64 сохраняет кириллицу темы при разборе SMTP-журнала. */
final class Utf8SubjectHeader extends UnstructuredHeader
{
    public function getBodyAsString(): string
    {
        return mb_encode_mimeheader($this->getBody(),'UTF-8','B',"\r\n",strlen($this->getName())+2);
    }
}
