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

    public function sendIntakeConfirmation(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload,
        array $attachments = []
    ): array {
        $subject = $payload['subject'] ?? 'Bevestiging intake afspraak';
        $body = $this->renderTemplate('intake_confirmation', $payload);

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
             'intake_confirmation' => sprintf(
                "Beste %s,\n\nBedankt voor het plannen van uw intake. Wij verwachten u op %s in onze vestiging. Neem deze bevestiging en uw apparaat mee. Uw referentiecode is %s.\n\nBeschrijving: %s\n\nTot snel,\nDigivriend",
                $safePayload['customer_name'] ?? 'klant',
                $safePayload['appointment_at'] ?? 'het afgesproken tijdstip',
                $safePayload['reference_code'] ?? '—',
                $safePayload['notes'] ?? '—'
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

            $message = $this->normaliseLineEndings($body);
        } else {
            $boundary = '=_Part_' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

            $parts = [];
            $parts[] = '--' . $boundary;
            $parts[] = 'Content-Type: text/plain; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $this->normaliseLineEndings($body);

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
                $parts[] = $this->normaliseLineEndings($encodedContent);
            }

            $parts[] = '--' . $boundary . '--';
            $message = implode("\r\n", $parts);
        }

        $mailer = strtolower((string) Env::get('MAIL_MAILER', 'log'));

        if ($mailer === 'smtp') {
            return $this->sendViaSmtp($recipient, $subject, $message, $headers, $fromAddress);
        }

        if ($mailer === 'log') {
            return ['success' => true, 'error' => null];
        }

        $sent = mail(
            $recipient,
            $this->encodeHeader($subject),
            str_replace("\r\n", "\n", $message),
            implode("\r\n", $headers)
        );

        return [
            'success' => $sent,
            'error' => $sent ? null : 'Wywołanie funkcji mail() nie powiodło się.',
        ];
    }

    private function sendViaSmtp(string $recipient, string $subject, string $message, array $headers, string $fromAddress): array
    {
        $host = trim((string) Env::get('MAIL_HOST', ''));
        $port = (int) Env::get('MAIL_PORT', 587);
        $username = (string) Env::get('MAIL_USERNAME', '');
        $password = (string) Env::get('MAIL_PASSWORD', '');
        $encryption = strtolower((string) Env::get('MAIL_ENCRYPTION', 'tls'));
        $timeout = (int) Env::get('MAIL_TIMEOUT', 30);
        $ehloDomain = (string) Env::get('MAIL_EHLO_DOMAIN', 'localhost');

        if ($host === '') {
            return ['success' => false, 'error' => 'Brak konfiguracji serwera SMTP (MAIL_HOST).'];
        }

        if (!in_array($encryption, ['tls', 'starttls', 'ssl', 'none', ''], true)) {
            return ['success' => false, 'error' => sprintf('Nieobsługiwany typ szyfrowania SMTP: %s', $encryption)];
        }

        $remoteSocket = sprintf('%s:%d', $host, $port);
        if ($encryption === 'ssl') {
            $remoteSocket = sprintf('ssl://%s:%d', $host, $port);
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => Env::get('MAIL_VERIFY_PEER', true),
                'verify_peer_name' => Env::get('MAIL_VERIFY_PEER_NAME', true),
                'allow_self_signed' => Env::get('MAIL_ALLOW_SELF_SIGNED', false),
            ],
        ]);

        $timeout = max($timeout, 5);
        $stream = @stream_socket_client($remoteSocket, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!is_resource($stream)) {
            return [
                'success' => false,
                'error' => sprintf('Połączenie SMTP nie powiodło się (%s:%d): %s', $host, $port, $errstr ?: 'nieznany błąd'),
            ];
        }

        stream_set_timeout($stream, $timeout);

        $response = $this->readSmtpResponse($stream);
        if (!$this->responseCodeIs($response, 220)) {
            fclose($stream);
            return ['success' => false, 'error' => sprintf('Nieprawidłowa odpowiedź serwera SMTP: %s', trim($response))];
        }

        $response = $this->sendSmtpCommand($stream, sprintf('EHLO %s', $ehloDomain));
        if (!$this->responseCodeIs($response, 250)) {
            fclose($stream);
            return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił komendę EHLO: %s', trim($response))];
        }

        if (in_array($encryption, ['tls', 'starttls'], true)) {
            $response = $this->sendSmtpCommand($stream, 'STARTTLS');
            if (!$this->responseCodeIs($response, 220)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił STARTTLS: %s', trim($response))];
            }

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (!stream_socket_enable_crypto($stream, true, $cryptoMethod)) {
                fclose($stream);
                return ['success' => false, 'error' => 'Nie udało się zainicjować szyfrowania TLS.'];
            }

            $response = $this->sendSmtpCommand($stream, sprintf('EHLO %s', $ehloDomain));
            if (!$this->responseCodeIs($response, 250)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił ponowne EHLO: %s', trim($response))];
            }
        }

        if ($username !== '' && $password !== '') {
            $response = $this->sendSmtpCommand($stream, 'AUTH LOGIN');
            if (!$this->responseCodeIs($response, 334)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił AUTH LOGIN: %s', trim($response))];
            }

            $response = $this->sendSmtpCommand($stream, base64_encode($username));
            if (!$this->responseCodeIs($response, 334)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Błędna odpowiedź po przesłaniu użytkownika: %s', trim($response))];
            }

            $response = $this->sendSmtpCommand($stream, base64_encode($password));
            if (!$this->responseCodeIs($response, 235)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Logowanie SMTP nie powiodło się: %s', trim($response))];
            }
        }

        $envelopeFrom = $this->extractEmailAddress($fromAddress);
        if ($envelopeFrom === '') {
            fclose($stream);
            return ['success' => false, 'error' => 'Nieprawidłowy adres nadawcy dla SMTP.'];
        }

        $response = $this->sendSmtpCommand($stream, sprintf('MAIL FROM:<%s>', $envelopeFrom));
        if (!$this->responseCodeIs($response, 250)) {
            fclose($stream);
            return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił adres nadawcy: %s', trim($response))];
        }

        $recipients = array_filter(array_map(static fn (string $value): string => trim($value), preg_split('/[,;]/', $recipient) ?: []));
        if ($recipients === []) {
            $recipients = [trim($recipient)];
        }

        $headerRecipients = $recipients;

        $acceptedRecipients = 0;

        foreach ($recipients as $index => $rcpt) {
            if ($rcpt === '') {
                continue;
            }

            $rcptAddress = $this->extractEmailAddress($rcpt);
            if ($rcptAddress === '') {
                unset($headerRecipients[$index]);
                continue;
            }

            $response = $this->sendSmtpCommand($stream, sprintf('RCPT TO:<%s>', $rcptAddress));
            if (!$this->responseCodeIs($response, 250, 251)) {
                fclose($stream);
                return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił odbiorcę %s: %s', $rcpt, trim($response))];
            }

            $acceptedRecipients++;
        }

        if ($acceptedRecipients === 0) {
            fclose($stream);
            return ['success' => false, 'error' => 'Brak poprawnych odbiorców wiadomości SMTP.'];
        }

        $response = $this->sendSmtpCommand($stream, 'DATA');
        if (!$this->responseCodeIs($response, 354)) {
            fclose($stream);
            return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił komendę DATA: %s', trim($response))];
        }

        $smtpMessage = $this->buildSmtpMessage(array_values($headerRecipients), $subject, $message, $headers);
        $this->writeSmtpData($stream, $smtpMessage);

        $response = $this->readSmtpResponse($stream);
        if (!$this->responseCodeIs($response, 250)) {
            fclose($stream);
            return ['success' => false, 'error' => sprintf('Serwer SMTP odrzucił wiadomość: %s', trim($response))];
        }

        $this->sendSmtpCommand($stream, 'QUIT');
        fclose($stream);

        return ['success' => true, 'error' => null];
    }

    /**
     * @param string[] $recipients
     */
    private function buildSmtpMessage(array $recipients, string $subject, string $message, array $headers): string
    {
        $headerMap = [];
        $extraHeaders = [];

        foreach ($headers as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $parts = explode(':', $trimmed, 2);
            if (count($parts) === 2) {
                $key = strtolower(trim($parts[0]));
                $headerMap[$key] = trim($parts[0]) . ':' . $parts[1];
            } else {
                $extraHeaders[] = $trimmed;
            }
        }

        if (!isset($headerMap['date'])) {
            $headerMap = ['date' => 'Date: ' . date(DATE_RFC2822)] + $headerMap;
        }

        $formattedRecipients = implode(', ', array_filter($recipients, static fn (string $value): bool => $value !== ''));
        if ($formattedRecipients === '') {
            $formattedRecipients = 'undisclosed-recipients:;';
        }

        $headerMap['to'] = 'To: ' . $formattedRecipients;
        $headerMap['subject'] = 'Subject: ' . $this->encodeHeader($subject);

        $preferredOrder = ['date', 'from', 'reply-to', 'to', 'subject'];
        $ordered = [];

        foreach ($preferredOrder as $key) {
            if (isset($headerMap[$key])) {
                $ordered[] = $headerMap[$key];
                unset($headerMap[$key]);
            }
        }

        foreach ($headerMap as $value) {
            $ordered[] = $value;
        }

        foreach ($extraHeaders as $value) {
            $ordered[] = $value;
        }

        $headerBlock = implode("\r\n", array_map([$this, 'normaliseHeaderLine'], $ordered));

        $normalisedMessage = $this->normaliseLineEndings($message);

        $payload = $headerBlock . "\r\n\r\n" . $normalisedMessage;
        $payloadLines = explode("\r\n", $payload);
        $escapedLines = array_map(static function (string $line): string {
            if ($line !== '' && $line[0] === '.') {
                return '.' . $line;
            }

            return $line;
        }, $payloadLines);

        return implode("\r\n", $escapedLines) . "\r\n.";
    }

    private function normaliseLineEndings(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace("\n", "\r\n", $value);
    }

    private function normaliseHeaderLine(string $line): string
    {
        return $this->normaliseLineEndings($line);
    }

    private function readSmtpResponse($stream): string
    {
        $response = '';

        while (is_resource($stream) && !feof($stream)) {
            $line = fgets($stream, 515);
            if ($line === false) {
                break;
            }

            $response .= $line;

            if (strlen($line) < 4) {
                continue;
            }

            if ($line[3] === ' ') {
                break;
            }
        }

        return $response;
    }

    private function responseCodeIs(string $response, int ...$expected): bool
    {
        if ($response === '') {
            return false;
        }

        $code = (int) substr(trim($response), 0, 3);

        return in_array($code, $expected, true);
    }

    private function sendSmtpCommand($stream, string $command): string
    {
        fwrite($stream, $command . "\r\n");

        return $this->readSmtpResponse($stream);
    }

    private function writeSmtpData($stream, string $data): void
    {
        fwrite($stream, $data . "\r\n");
    }

    private function extractEmailAddress(string $address): string
    {
        $trimmed = trim($address);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/<([^>]+)>/', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($trimmed, "'\"");
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