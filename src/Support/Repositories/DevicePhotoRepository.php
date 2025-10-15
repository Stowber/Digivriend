declare(strict_types=1);

namespace App\Support\Repositories;

use PDO;

final class DevicePhotoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function store(int $deviceId, string $orientation, string $filePath, ?string $capturedAt = null): void
    {
        $this->removeOrientation($deviceId, $orientation);

        $statement = $this->pdo->prepare(
            'INSERT INTO device_photos (device_id, orientation, file_path, captured_at)
             VALUES (:device_id, :orientation, :file_path, :captured_at)'
        );
        $statement->execute([
            'device_id' => $deviceId,
            'orientation' => $orientation,
            'file_path' => $filePath,
            'captured_at' => $capturedAt,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forDevice(int $deviceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM device_photos WHERE device_id = :device_id ORDER BY created_at ASC'
        );
        $statement->execute(['device_id' => $deviceId]);

        return $statement->fetchAll() ?: [];
    }

    public function findById(int $photoId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM device_photos WHERE id = :id');
        $statement->execute(['id' => $photoId]);
        $photo = $statement->fetch();

        return $photo !== false ? $photo : null;
    }

    public function removeOrientation(int $deviceId, string $orientation): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM device_photos WHERE device_id = :device_id AND orientation = :orientation'
        );
        $statement->execute([
            'device_id' => $deviceId,
            'orientation' => $orientation,
        ]);
    }
}