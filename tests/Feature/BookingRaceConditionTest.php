<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BookingRaceConditionTest extends TestCase
{
    /**
     * Без транзакционной обёртки RefreshDatabase: дочерние процессы должны видеть комнату и пользователя.
     */
    use DatabaseMigrations;

    public function test_concurrent_creates_do_not_double_book_the_same_slot(): void
    {
        $user = User::factory()->create();
        $office = Office::factory()->create([
            'name' => 'Москва',
            'timezone' => 'Europe/Moscow',
        ]);
        $room = Room::factory()->for($office)->create();

        $payload = [
            'user_id' => $user->id,
            'room_id' => $room->id,
            'title' => 'Гонка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ];

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => 'testing',
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'BOOKING_PAYLOAD' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];

        $script = base_path('tests/bin/create-booking-concurrent.php');

        $results = Process::concurrently(function ($pool) use ($script, $env): void {
            foreach (['first', 'second'] as $name) {
                $pool->as($name)->path(base_path())->env($env)->timeout(15)->command([
                    PHP_BINARY,
                    $script,
                ]);
            }
        });

        $outcomes = collect([$results['first'], $results['second']])->map(
            function ($process) {
                $this->assertNotSame(
                    2,
                    $process->exitCode(),
                    'Неожиданная ошибка процесса: '.$process->errorOutput()
                );

                return json_decode($process->output(), true);
            }
        );

        $this->assertCount(1, $outcomes->where('ok', true)->all());
        $this->assertCount(1, $outcomes->where('ok', false)->all());
        $this->assertSame(1, Booking::query()->where('room_id', $room->id)->count());
    }
}
