<?php
declare(strict_types=1);
namespace App\Orchid\Presenters;

/** Представление редактора без передачи адреса почты сторонним сервисам аватаров. */
final class UserPresenter extends \Orchid\Support\Presenter implements \Orchid\Screen\Contracts\Personable
{
    public function title(): string { return $this->entity->name; }
    public function subTitle(): string { return $this->entity->email; }
    public function url(): string { return route('platform.quests'); }
    public function image(): ?string { return null; }
}
