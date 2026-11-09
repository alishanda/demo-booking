<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_booking_for_authenticated_user(): void
    {
        [$user, $room] = $this->moscowRoom();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'user_id' => 999,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'id' => $response->json('id'),
            'room_id' => $room->id,
            'user_id' => $user->id,
            'title' => 'Планёрка',
        ]);
    }

    public function test_allows_back_to_back_bookings(): void
    {
        [$user, $room] = $this->moscowRoom();

        Booking::factory()->for($user)->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Следом',
            'starts_at' => '2026-11-02T11:00:00+03:00',
            'ends_at' => '2026-11-02T12:00:00+03:00',
        ])->assertCreated();
    }

    public function test_rejects_overlapping_bookings(): void
    {
        [$user, $room] = $this->moscowRoom();

        Booking::factory()->for($user)->for($room)->create([
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Конфликт',
            'starts_at' => '2026-11-02T10:30:00+03:00',
            'ends_at' => '2026-11-02T11:30:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id']);
    }

    public function test_rejects_duration_shorter_than_15_minutes(): void
    {
        [$user, $room] = $this->moscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Коротко',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:10:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_rejects_duration_longer_than_4_hours(): void
    {
        [$user, $room] = $this->moscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Долго',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T14:15:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ends_at']);
    }

    public function test_rejects_times_not_aligned_to_15_minutes(): void
    {
        [$user, $room] = $this->moscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Кривая сетка',
            'starts_at' => '2026-11-02T10:10:00+03:00',
            'ends_at' => '2026-11-02T10:40:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_rejects_booking_outside_moscow_working_hours(): void
    {
        [$user, $room] = $this->moscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Поздно',
            'starts_at' => '2026-11-02T19:00:00+03:00',
            'ends_at' => '2026-11-02T20:30:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_accepts_booking_within_novosibirsk_working_hours_with_other_offset(): void
    {
        [$user, $room] = $this->novosibirskRoom();

        Sanctum::actingAs($user);

        // 09:00–10:00 Asia/Novosibirsk (+07), передано со смещением UTC.
        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Утро в Нск',
            'starts_at' => '2026-11-02T02:00:00Z',
            'ends_at' => '2026-11-02T03:00:00Z',
        ])->assertCreated();
    }

    public function test_rejects_booking_outside_novosibirsk_working_hours(): void
    {
        [$user, $room] = $this->novosibirskRoom();

        Sanctum::actingAs($user);

        // 08:00–09:00 Asia/Novosibirsk — до открытия офиса.
        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Рано в Нск',
            'starts_at' => '2026-11-02T01:00:00Z',
            'ends_at' => '2026-11-02T02:00:00Z',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);
    }

    public function test_requires_authentication(): void
    {
        $room = Room::factory()->for(Office::factory())->create();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Без токена',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertUnauthorized();
    }

    public function test_returns_validation_errors_for_invalid_payload(): void
    {
        [$user] = $this->moscowRoom();

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'title' => '',
            'starts_at' => '2026-11-02T11:00:00+03:00',
            'ends_at' => '2026-11-02T10:00:00+03:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'ends_at']);
    }

    /**
     * @return array{0: User, 1: Room}
     */
    private function moscowRoom(): array
    {
        $user = User::factory()->create();
        $office = Office::factory()->create([
            'name' => 'Москва',
            'timezone' => 'Europe/Moscow',
        ]);
        $room = Room::factory()->for($office)->create();

        return [$user, $room];
    }

    /**
     * @return array{0: User, 1: Room}
     */
    private function novosibirskRoom(): array
    {
        $user = User::factory()->create();
        $office = Office::factory()->novosibirsk()->create();
        $room = Room::factory()->for($office)->create();

        return [$user, $room];
    }
}
