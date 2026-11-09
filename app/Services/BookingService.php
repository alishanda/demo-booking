<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BookingService
{
    private const MIN_DURATION_MINUTES = 15;

    private const MAX_DURATION_MINUTES = 240;

    private const SLOT_MINUTES = 15;

    private const WORKDAY_START = '09:00';

    private const WORKDAY_END = '20:00';

    /**
     * @param  array{room_id: int, title: string, starts_at: string, ends_at: string}  $data
     */
    public function create(User $user, array $data): Booking
    {
        $room = Room::query()->with('office')->findOrFail($data['room_id']);

        $startsAt = CarbonImmutable::parse($data['starts_at'])->utc();
        $endsAt = CarbonImmutable::parse($data['ends_at'])->utc();

        $this->assertDurationAndGrid($room, $startsAt, $endsAt);
        $this->assertWithinWorkingHours($room, $startsAt, $endsAt);

        return DB::transaction(function () use ($user, $room, $data, $startsAt, $endsAt): Booking {
            Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

            if ($this->hasOverlap($room->id, $startsAt, $endsAt)) {
                throw ValidationException::withMessages([
                    'room_id' => 'Комната уже занята в указанный интервал.',
                ]);
            }

            return Booking::query()->create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'title' => $data['title'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        });
    }

    public function cancel(User $user, Booking $booking): void
    {
        if ($booking->user_id !== $user->id) {
            throw new AccessDeniedHttpException('Отменить бронь может только её автор.');
        }

        $booking->delete();
    }

    private function assertDurationAndGrid(Room $room, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $durationMinutes = $startsAt->diffInMinutes($endsAt);

        if ($durationMinutes < self::MIN_DURATION_MINUTES || $durationMinutes > self::MAX_DURATION_MINUTES) {
            throw ValidationException::withMessages([
                'ends_at' => 'Длительность брони должна быть от 15 минут до 4 часов.',
            ]);
        }

        if ($durationMinutes % self::SLOT_MINUTES !== 0) {
            throw ValidationException::withMessages([
                'ends_at' => 'Длительность брони должна быть кратна 15 минутам.',
            ]);
        }

        $timezone = $room->office->timezone;
        $localStart = $startsAt->timezone($timezone);
        $localEnd = $endsAt->timezone($timezone);

        if (! $this->isAlignedToGrid($localStart) || ! $this->isAlignedToGrid($localEnd)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Время начала и окончания должно быть с шагом 15 минут (например, 10:00, 10:15).',
            ]);
        }
    }

    private function assertWithinWorkingHours(Room $room, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        $timezone = $room->office->timezone;
        $localStart = $startsAt->timezone($timezone);
        $localEnd = $endsAt->timezone($timezone);

        if ($localStart->toDateString() !== $localEnd->toDateString()) {
            throw ValidationException::withMessages([
                'starts_at' => 'Бронь должна целиком укладываться в один рабочий день офиса.',
            ]);
        }

        $workStart = $localStart->setTimeFromTimeString(self::WORKDAY_START);
        $workEnd = $localStart->setTimeFromTimeString(self::WORKDAY_END);

        if ($localStart->lt($workStart) || $localEnd->gt($workEnd)) {
            throw ValidationException::withMessages([
                'starts_at' => 'Бронь должна целиком укладываться в рабочее время 09:00–20:00 по местному времени офиса.',
            ]);
        }
    }

    private function hasOverlap(int $roomId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        return Booking::query()
            ->where('room_id', $roomId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();
    }

    private function isAlignedToGrid(CarbonImmutable $time): bool
    {
        return $time->second === 0
            && $time->micro === 0
            && $time->minute % self::SLOT_MINUTES === 0;
    }
}
