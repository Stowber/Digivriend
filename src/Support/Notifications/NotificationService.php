<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Support\Clock;
use DateTimeImmutable;
use PDO;

final class NotificationService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function sendPickupReady(?int $caseId, ?int $customerId, string $recipient, array $payload): void
    {
        $subject = $payload['subject'] ?? 'Uw apparaat staat klaar voor afhalen';
        $body = $this->renderTemplate('pickup_ready', $payload);

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $body);
    }

    public function sendPickupConfirmation(?int $caseId, ?int $customerId, string $recipient, array $payload): void
    {
        $subject = $payload['subject'] ?? 'Bevestiging van apparaatophaal';
        $body = $this->renderTemplate('pickup_confirmation', $payload);

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $body);
    }

    public function sendSms(?int $caseId, ?int $customerId, string $recipient, array $payload): void
    {
        $body = $this->renderTemplate('sms_generic', $payload);
        $this->recordNotification($caseId, $customerId, 'sms', $recipient, $payload['subject'] ?? null, $body);
    }

    private function recordNotification(?int $caseId, ?int $customerId, string $channel, string $recipient, ?string $subject, string $body): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications (case_id, customer_id, channel, recipient, subject, body, status, sent_at) VALUES (:case_id, :customer_id, :channel, :recipient, :subject, :body, :status, :sent_at)'
        );

        $now = Clock::nowFormatted();

        $statement->execute([
            'case_id' => $caseId,
            'customer_id' => $customerId,
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => 'sent',
            'sent_at' => $now,
        ]);

        $this->writeLog($channel, $recipient, $subject, $body, $now);
    }

    private function renderTemplate(string $type, array $payload): string
    {
        $safePayload = array_map(static function ($value): string {
            if ($value instanceof DateTimeImmutable) {
                return $value->format('d-m-Y H:i');
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode($value, JSON_THROW_ON_ERROR);
        }, $payload);

        return match ($type) {
            'pickup_ready' => sprintf(
                "Beste %s,\n\nUw apparaat (%s) staat klaar om opgehaald te worden. Gebruik ophaalcode %s en plan bij voorkeur uw bezoek op %s.\n\nMet vriendelijke groet,\nDigivriend",
                $safePayload['customer_name'] ?? 'klant',
                $safePayload['device'] ?? 'uw apparaat',
                $safePayload['pickup_code'] ?? '—',
                $safePayload['ready_date'] ?? 'een geschikt moment'
            ),
            'pickup_confirmation' => sprintf(
                "Beste %s,\n\nWij hebben bevestigd dat uw apparaat met code %s op %s is opgehaald. Dank voor uw bezoek en tot een volgende keer!\n\nDigivriend",
                $safePayload['customer_name'] ?? 'klant',
                $safePayload['pickup_code'] ?? '—',
                $safePayload['pickup_date'] ?? '—'
            ),
            'sms_generic' => sprintf(
                "%s",
                $safePayload['body'] ?? ''
            ),
            default => $safePayload['body'] ?? ''
        };
    }

    private function writeLog(string $channel, string $recipient, ?string $subject, string $body, string $timestamp): void
    {
        $directory = __DIR__ . '/../../../storage/notifications';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $filename = sprintf('%s/%s-%s.log', $directory, $channel, date('Ymd_His'));
        $contents = [
            'kanaal: ' . $channel,
            'ontvanger: ' . $recipient,
            'onderwerp: ' . ($subject ?? ''),
            'verzonden: ' . $timestamp,
            'bericht:',
            $body,
            str_repeat('-', 40),
        ];

        file_put_contents($filename, implode(PHP_EOL, $contents) . PHP_EOL, FILE_APPEND);
    }
}