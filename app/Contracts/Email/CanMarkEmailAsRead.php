<?php

namespace App\Contracts\Email;

interface CanMarkEmailAsRead
{
    public function markMessageAsRead(string $providerMessageId): array;
}
