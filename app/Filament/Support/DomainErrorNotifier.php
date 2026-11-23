<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Exceptions\Domain\DomainException;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

/**
 * Turns business-rule violations into admin panel notifications, so actions
 * stay one-liners that delegate to the application layer.
 */
final class DomainErrorNotifier
{
    /**
     * @return bool true if the error was handled (a known domain/auth error)
     */
    public static function notify(Throwable $e, string $title = 'Action not allowed'): bool
    {
        if (! $e instanceof DomainException && ! $e instanceof AuthorizationException) {
            return false;
        }

        Notification::make()->danger()->title($title)->body($e->getMessage())->persistent()->send();

        return true;
    }
}
