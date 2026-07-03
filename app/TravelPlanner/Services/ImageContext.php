<?php

namespace App\TravelPlanner\Services;

use App\TravelPlanner\DTO\TravelPlan;

final class ImageContext
{
    private const FALLBACK_DESTINATION = 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1600&q=80';
    private const FALLBACK_HOTEL = 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1200&q=80';
    private const FALLBACK_ATTRACTION = 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1200&q=80';

    public function build(?TravelPlan $plan): array
    {
        return [
            'destination_hero_image' => self::FALLBACK_DESTINATION,
            'hotel_images' => [],
            'attraction_images' => [],
            'fallback_destination_image' => self::FALLBACK_DESTINATION,
            'fallback_hotel_image' => self::FALLBACK_HOTEL,
            'fallback_attraction_image' => self::FALLBACK_ATTRACTION,
            'destination_name' => $plan?->destination ?? '',
        ];
    }
}
