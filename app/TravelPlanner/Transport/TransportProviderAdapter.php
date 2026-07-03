<?php

namespace App\TravelPlanner\Transport;

use App\TravelPlanner\DTO\UserRequest;

interface TransportProviderAdapter
{
    /**
     * @return array<int, TransportOption>
     */
    public function search(UserRequest $request): array;
}
