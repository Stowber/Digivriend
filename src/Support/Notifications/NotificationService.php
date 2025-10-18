<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Support\Clock;
use App\Support\Env;
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

    public function sendPcBuildRelease(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload,
        array $attachments = []
    ): array {
        $subject = $payload['subject'] ?? 'Twój zestaw PC jest gotowy do odbioru';
        $body = $this->renderTemplate('pc_build_release', $payload);

        $result = $this->sendEmail($recipient, $subject, $body, $attachments);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $body, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendSms(?int $caseId, ?int $customerId, string $recipient, array $payload): void
    {
        $body = $this->renderTemplate('sms_generic', $payload);
        $this->recordNotification($caseId, $customerId, 'sms', $recipient, $payload['subject'] ?? null, $body);
    }

    private function recordNotification(
        ?int $caseId,
        ?int $customerId,
        string $channel,
        string $recipient,
        ?string $subject,
        string $body,
        string $status = 'sent',
        ?string $error = null,
        ?string $sentAt = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO notifications (case_id, customer_id, channel, recipient, subject, body, status, error, sent_at, created_at) '
            . 'VALUES (:case_id, :customer_id, :channel, :recipient, :subject, :body, :status, :error, :sent_at, :created_at)'
        );

        $now = Clock::nowFormatted();
        $sentAtValue = $sentAt ?? ($status === 'sent' ? $now : null);

        $statement->execute([
            'case_id' => $caseId,
            'customer_id' => $customerId,
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => $status,
            'error' => $error,
            'sent_at' => $sentAtValue,
            'created_at' => $now,
        ]);

        $this->writeLog($channel, $recipient, $subject, $body, $now, $status, $error, $sentAtValue);
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
             'pc_build_release' => sprintf(
                "Dzień dobry %s,\n\nZestaw PC %s jest gotowy do przekazania. Sposób wydania: %s dnia %s. W załączniku znajdziesz potwierdzenie wydania. W razie pytań skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Digivriend",
                $safePayload['customer_name'] ?? 'klient',
                $safePayload['build_reference'] ?? 'Twój zestaw',
                $safePayload['delivery_method'] ?? 'odbiór w salonie',
                $safePayload['release_date'] ?? date('d-m-Y')
            ),
            'sms_generic' => sprintf(
                "%s",
                $safePayload['body'] ?? ''
            ),
            default => $safePayload['body'] ?? ''
        };
    }

    private function writeLog(
        string $channel,
        string $recipient,
        ?string $subject,
        string $body,
        string $timestamp,
        string $status,
        ?string $error = null,
        ?string $sentAt = null
    ): void {
        $directory = __DIR__ . '/../../../storage/notifications';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $filename = sprintf('%s/%s-%s.log', $directory, $channel, date('Ymd_His'));
        $contents = [
            'kanaal: ' . $channel,
            'ontvanger: ' . $recipient,
            'onderwerp: ' . ($subject ?? ''),
            'status: ' . $status,
            'aangemaakt: ' . $timestamp,
        ];

        if ($sentAt !== null) {
            $contents[] = 'verzonden: ' . $sentAt;
        }

        if ($error !== null && $error !== '') {
            $contents[] = 'fout: ' . $error;
        }

        $contents[] = 'bericht:';
        $contents[] = $body;
        $contents[] = str_repeat('-', 40);

        file_put_contents($filename, implode(PHP_EOL, $contents) . PHP_EOL, FILE_APPEND);
    }
/**
     * @param array<int, array<string, string>> $attachments
     * @return array{success: bool, error: ?string}
     */
    private function sendEmail(string $recipient, string $subject, string $body, array $attachments = []): array
    {
        $fromAddress = trim((string) Env::get('MAIL_FROM_ADDRESS', ''));
        if ($fromAddress === '') {
            $fromAddress = 'no-reply@digivriend.local';
        }
        $fromName = (string) Env::get('MAIL_FROM_NAME', 'Digivriend');

        $headers = [];
        $headers[] = 'From: ' . $this->formatAddress($fromAddress, $fromName);
        $headers[] = 'Reply-To: ' . $this->formatAddress($fromAddress, $fromName);
        $headers[] = 'MIME-Version: 1.0';

        if ($attachments === []) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';

            $message = $body;
        } else {
            $boundary = '=_Part_' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

            $parts = [];
            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: text/plain; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $body;

            foreach ($attachments as $attachment) {
                $path = $attachment['path'] ?? null;
                $content = $attachment['content'] ?? null;

                if ($path !== null) {
                    if (!is_file($path)) {
                        return ['success' => false, 'error' => sprintf('Załącznik %s nie istnieje.', $path)];
                    }

                    $fileContents = file_get_contents($path);
                    if ($fileContents === false) {
                        return ['success' => false, 'error' => sprintf('Nie można odczytać załącznika %s.', $path)];
                    }

                    $content = $fileContents;
                }

                if ($content === null) {
                    return ['success' => false, 'error' => 'Brak danych załącznika.'];
                }

                $name = (string) ($attachment['name'] ?? ($path !== null ? basename($path) : 'zalacznik.pdf'));
                $mime = (string) ($attachment['mime'] ?? 'application/octet-stream');
                $encodedContent = chunk_split(base64_encode(is_string($content) ? $content : (string) $content));

                $parts[] = '--' . $boundary;
                $parts[] = 'Content-Type: ' . $mime . '; name="' . $this->encodeHeader($name) . '"';
                $parts[] = 'Content-Transfer-Encoding: base64';
                $parts[] = 'Content-Disposition: attachment; filename="' . $this->encodeHeader($name) . '"';
                $parts[] = '';
                $parts[] = $encodedContent;
            }

            $parts[] = '--' . $boundary . '--';
            $message = implode("\r\n", $parts);
        }

        $sent = mail($recipient, $this->encodeHeader($subject), $message, implode("\r\n", $headers));

        return [
            'success' => $sent,
            'error' => $sent ? null : 'Wywołanie funkcji mail() nie powiodło się.',
        ];
    }

    private function encodeHeader(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $trimmed;
        }

        return mb_encode_mimeheader($trimmed, 'UTF-8', 'Q', "\r\n");
    }

    private function formatAddress(string $address, ?string $name = null): string
    {
        $cleanAddress = trim($address);
        if ($name === null || trim($name) === '') {
            return $cleanAddress;
        }

        return sprintf('"%s" <%s>', $this->encodeHeader($name), $cleanAddress);
    }
}