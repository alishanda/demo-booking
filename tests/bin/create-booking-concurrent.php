<?php

declare(strict_types=1);

use App\Dto\CreateBookingData;
use App\Models\User;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(getenv('BOOKING_PAYLOAD') ?: 'null', true, 512, JSON_THROW_ON_ERROR);

try {
    $booking = $app->make(BookingService::class)->create(new CreateBookingData(
        user: User::query()->findOrFail($payload['user_id']),
        roomId: $payload['room_id'],
        title: $payload['title'],
        startsAt: CarbonImmutable::parse($payload['starts_at'])->utc(),
        endsAt: CarbonImmutable::parse($payload['ends_at'])->utc(),
    ));

    echo json_encode(['ok' => true, 'id' => $booking->id], JSON_THROW_ON_ERROR);
    exit(0);
} catch (ValidationException $exception) {
    echo json_encode([
        'ok' => false,
        'errors' => $exception->errors(),
    ], JSON_THROW_ON_ERROR);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(2);
}
