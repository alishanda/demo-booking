<?php

namespace App\Dto;

use App\Http\Requests\StoreBookingRequest;
use App\Models\User;
use Carbon\CarbonImmutable;

readonly class CreateBookingData
{
    public function __construct(
        public User $user,
        public int $roomId,
        public string $title,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    public static function fromRequest(StoreBookingRequest $request): self
    {
        /** @var array{room_id: int, title: string, starts_at: string, ends_at: string} $validated */
        $validated = $request->validated();

        return new self(
            user: $request->user(),
            roomId: $validated['room_id'],
            title: $validated['title'],
            startsAt: CarbonImmutable::parse($validated['starts_at'])->utc(),
            endsAt: CarbonImmutable::parse($validated['ends_at'])->utc(),
        );
    }
}
