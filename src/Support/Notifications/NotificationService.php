<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Support\Clock;
use App\Support\Env;
use DateTimeImmutable;
use PDO;

final class NotificationService
{
    private EmailLayoutRenderer $emailLayout;

    public function __construct(
        private readonly PDO $pdo,
        ?EmailLayoutRenderer $emailLayout = null
    ) {
        $this->emailLayout = $emailLayout ?? new EmailLayoutRenderer();
    }

    public function sendPickupReady(?int $caseId, ?int $customerId, string $recipient, array $payload): void
    {
        $subject = $payload['subject'] ?? 'Uw apparaat staat klaar voor afhalen';
        $template = $this->renderTemplate('pickup_ready', $payload);

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $template['html']);
    }

    public function sendPickupConfirmation(?int $caseId, ?int $customerId, string $recipient, array $payload): void
    {
        $subject = $payload['subject'] ?? 'Bevestiging van apparaatophaal';
        $template = $this->renderTemplate('pickup_confirmation', $payload);

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $template['html']);
    }

    public function sendPcBuildRelease(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload,
        array $attachments = []
    ): array {
        $subject = $payload['subject'] ?? 'Twój zestaw PC jest gotowy do odbioru';
        $template = $this->renderTemplate('pc_build_release', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText, $attachments);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

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
        $template = $this->renderTemplate('intake_confirmation', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText, $attachments);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendIntakeArrivalAcknowledgement(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload
    ): array {
        $subject = $payload['subject'] ?? 'Ontvangstbevestiging service intake';
        $template = $this->renderTemplate('intake_arrival', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendIntakeRescheduled(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload
    ): array {
        $subject = $payload['subject'] ?? 'Nieuwe intake afspraak bevestigd';
        $template = $this->renderTemplate('intake_rescheduled', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendIntakeCancellation(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload
    ): array {
        $subject = $payload['subject'] ?? 'Bevestiging annulering intake afspraak';
        $template = $this->renderTemplate('intake_cancelled', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendIntakeNoShow(
        ?int $caseId,
        ?int $customerId,
        string $recipient,
        array $payload
    ): array {
        $subject = $payload['subject'] ?? 'We hebben u gemist bij uw intake';
        $template = $this->renderTemplate('intake_no_show', $payload);
        $bodyHtml = $template['html'];
        $bodyText = $template['text'];

        $result = $this->sendEmail($recipient, $subject, $bodyHtml, $bodyText);
        $status = $result['success'] ? 'sent' : 'failed';
        $error = $result['error'] ?? null;
        $sentAt = $result['success'] ? Clock::nowFormatted() : null;

        $this->recordNotification($caseId, $customerId, 'email', $recipient, $subject, $bodyHtml, $status, $error, $sentAt);

        return [
            'success' => $result['success'],
            'status' => $status,
            'error' => $error,
        ];
    }

    public function sendSms(?int $caseId, ?int $customerId, string $recipient, array $payload): void
    {
        $template = $this->renderTemplate('sms_generic', $payload);
        $this->recordNotification($caseId, $customerId, 'sms', $recipient, $payload['subject'] ?? null, $template['text']);
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

    /**
     * @param array<string, mixed> $payload
     * @return array{html: string, text: string}
     */
    private function renderTemplate(string $type, array $payload): array
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

        $contact = [
            'email' => $safePayload['company_email'] ?? 'contact@digivriend.nl',
            'phone' => $safePayload['company_phone'] ?? '033 - 785 4284',
            'address' => $safePayload['company_address'] ?? 'De Ganskuijl 103B · 3817 EZ Amersfoort',
            'website' => $safePayload['company_website'] ?? 'https://digivriend.nl',
            'logo' => $safePayload['company_logo'] ?? $safePayload['company_logo_url'] ?? '',
        ];

        return match ($type) {
            'pickup_ready' => [
                'html' => $this->emailLayout->renderEmailLayout(
                    'Uw apparaat ligt gereed bij onze servicebalie',
                    'Uw apparaat staat klaar voor vertrek',
                    [
                        sprintf('Hallo %s,', $safePayload['customer_name'] ?? 'klant'),
                        sprintf('Het team heeft %s gecontroleerd, schoongemaakt en klaargezet zodat u het direct kunt meenemen.', $safePayload['device'] ?? 'uw apparaat'),
                        'Neem bij uw bezoek de ophaalcode en een geldig legitimatiebewijs mee. Dan ronden we de overdracht binnen enkele minuten af.',
                    ],
                    [
                        'Apparaat' => $safePayload['device'] ?? 'Uw apparaat',
                        'Ophaalcode' => $safePayload['pickup_code'] ?? '—',
                        'Voorkeursmoment' => $safePayload['ready_date'] ?? 'Kies een geschikt moment',
                    ],
                    null,
                    [
                        'Kunt u niet langskomen op het genoemde moment? Laat het ons weten, dan plannen we meteen een alternatief dat beter past.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Hallo %s,\n\nUw apparaat (%s) is door ons team gecontroleerd en staat klaar. Gebruik ophaalcode %s en kom vanaf %s langs. Vergeet uw legitimatie niet; dan is de overdracht zo geregeld.\n\nHartelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['device'] ?? 'uw apparaat',
                    $safePayload['pickup_code'] ?? '—',
                    $safePayload['ready_date'] ?? 'direct beschikbaar'
                ),
            ],
            'pickup_confirmation' => [
                'html' => $this->emailLayout->renderEmailLayout(
                    'De overdracht van uw apparaat is afgerond',
                    'Bedankt voor het ophalen',
                    [
                        sprintf('Hallo %s,', $safePayload['customer_name'] ?? 'klant'),
                        'We hebben geregistreerd dat uw apparaat met de onderstaande code is opgehaald en veilig met u is meegegaan.',
                        'Heeft u nog vragen over onderhoud, software of accessoires? Laat het ons weten, dan helpen we u graag verder.',
                    ],
                    [
                        'Ophaalreferentie' => $safePayload['pickup_code'] ?? '—',
                        'Datum overdracht' => $safePayload['pickup_date'] ?? '—',
                    ],
                    null,
                    [
                        'Bewaar dit bericht als bewijs van overdracht. Zo heeft u alle informatie bij de hand voor uw administratie.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Hallo %s,\n\nWe hebben geregistreerd dat uw apparaat met referentie %s op %s is opgehaald. Heeft u nog vragen of wenst u aanvullende service? Laat het ons weten.\n\nHartelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['pickup_code'] ?? '—',
                    $safePayload['pickup_date'] ?? '—'
                ),
            ],
            'intake_confirmation' => [
                'html' => $this->emailLayout->renderEmailLayout(
                    'Uw intake staat bevestigd',
                    'We staan voor u klaar',
                    [
                        sprintf('Hallo %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Bedankt dat u een intake bij ons heeft ingepland. We reserveren tijd om samen uw vraag te bespreken en direct de eerste stappen te zetten.',
                        'Neemt u dit bericht mee op uw telefoon of geprint? Dan kunnen we uw dossier meteen ophalen aan de balie.',
                    ],
                    [
                        'Afspraakmoment' => $safePayload['appointment_at'] ?? 'Het afgesproken tijdstip',
                        'Referentie' => $safePayload['reference_code'] ?? '—',
                        'Onderwerp' => $safePayload['notes'] ?? '—',
                    ],
                    null,
                    [
                        'Kunt u onverhoopt toch niet komen? Laat het ons op tijd weten, dan zoeken we direct een moment dat beter past.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Hallo %s,\n\nBedankt voor het plannen van uw intake. Wij verwachten u op %s in onze vestiging. Neem deze bevestiging en uw apparaat mee. Uw referentie is %s.\n\nOnderwerp: %s\n\nTot snel,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het afgesproken tijdstip',
                    $safePayload['reference_code'] ?? '—',
                    $safePayload['notes'] ?? '—'
                ),
            ],
            'intake_arrival' => [
                'html' => $this->emailLayout->renderEmailLayout(
                    'Uw toestel is veilig bij ons binnengekomen',
                    'We zijn gestart met het onderzoek',
                    [
                        sprintf('Hallo %s,', $safePayload['customer_name'] ?? 'klant'),
                        sprintf('We hebben uw apparaat ontvangen en gekoppeld aan case %s. Het staat nu bij onze technici voor de eerste diagnose.', $safePayload['reference_code'] ?? 'uw case'),
                        'We houden u op de hoogte van elke stap. Zodra er nieuws is ontvangt u direct een update per e-mail of telefoon.',
                    ],
                    [
                        'Case / referentie' => $safePayload['reference_code'] ?? 'Uw case',
                        'Ontvangen op' => $safePayload['appointment_at'] ?? 'Het afgesproken moment',
                    ],
                    null,
                    [
                        'Heeft u tussentijds aanvullende informatie of inloggegevens? Deel ze gerust, dan kunnen we doorwerken zonder vertraging.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Hallo %s,\n\nWij bevestigen de ontvangst van uw apparaat voor case %s. Het toestel is op %s bij ons binnengebracht en onze technici zijn gestart met de diagnose. U hoort van ons zodra er nieuws is.\n\nHartelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['reference_code'] ?? 'uw case',
                    $safePayload['appointment_at'] ?? 'het afgesproken moment'
                ),
            ],
            'intake_rescheduled' => [
                'html' => $this->emailLayout->renderEmailLayout(
                    'Uw intake heeft een nieuwe datum',
                    'Nieuwe intakeafspraak bevestigd',
                    [
                        sprintf('Hallo %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Zoals afgesproken hebben we uw intake verplaatst. De agenda is bijgewerkt en het team rekent op u op het nieuwe moment hieronder.',
                        'In dit bericht vindt u alle details nog even overzichtelijk bij elkaar.',
                    ],
                    [
                        'Nieuw tijdstip' => $safePayload['appointment_at'] ?? 'Het nieuwe tijdstip',
                        'Referentie' => $safePayload['reference_code'] ?? '—',
                    ],
                    null,
                    [
                        'Komt er toch iets tussen? Laat het gerust weten; we denken direct met u mee voor een passend alternatief.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Hallo %s,\n\nZoals besproken hebben wij uw intake verplaatst naar %s. Uw referentie %s blijft ongewijzigd. Laat het ons weten als het tijdstip alsnog niet uitkomt, dan zoeken we direct mee.\n\nHartelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het nieuwe tijdstip',
                    $safePayload['reference_code'] ?? '—'
                ),
            ],
            'intake_cancelled' => [
                'html' => $this->emailLayout->renderEmailLayout(
                    'Uw intake is geannuleerd zoals verzocht',
                    'Annulering bevestigd',
                    [
                        sprintf('Hallo %s,', $safePayload['customer_name'] ?? 'klant'),
                        'We hebben uw bericht ontvangen en de intake volgens uw verzoek geannuleerd.',
                        'Hieronder vindt u nog even de gegevens van de afspraak zoals die stond ingepland.',
                    ],
                    [
                        'Oorspronkelijk moment' => $safePayload['appointment_at'] ?? 'Het geplande moment',
                        'Reden annulering' => $safePayload['cancellation_reason'] ?? 'Geen reden opgegeven',
                    ],
                    null,
                    [
                        'Wanneer u weer klaar bent voor een afspraak plannen we met plezier een nieuw moment. Neem gerust contact met ons op.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Hallo %s,\n\nUw intake van %s is geannuleerd. Reden: %s. Wanneer u weer een afspraak wilt plannen staan we voor u klaar.\n\nHartelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het geplande moment',
                    $safePayload['cancellation_reason'] ?? 'geen reden opgegeven'
                ),
            ],
            'intake_no_show' => [
                'html' => $this->emailLayout->renderEmailLayout(
                    'We hebben u gemist bij de intake',
                    'Kunnen we een nieuw moment plannen?',
                    [
                        sprintf('Hallo %s,', $safePayload['customer_name'] ?? 'klant'),
                        sprintf('We stonden op %s voor u klaar, maar hebben u helaas gemist.', $safePayload['appointment_at'] ?? 'het geplande moment'),
                        'Geen probleem: via de knop hieronder kiest u eenvoudig een nieuw moment. We helpen u graag alsnog verder.',
                    ],
                    [
                        'Gepland moment' => $safePayload['appointment_at'] ?? 'Het geplande moment',
                    ],
                    [
                        'Plan direct een nieuw moment',
                        $safePayload['reschedule_url'] ?? '',
                        'Kies zelf een tijdstip dat wél uitkomt.',
                    ],
                    [
                        'Zodra onze vernieuwde planner klaar is ontvangt u automatisch een nieuwe uitnodiging. Heeft u nu al hulp nodig? Laat het ons weten, dan zoeken we direct mee.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Hallo %s,\n\nWe stonden op %s voor u klaar maar hebben u gemist. Via onze planner kunt u direct een nieuw moment kiezen dat beter uitkomt. Liever persoonlijk afstemmen? Bel ons dan op 033 - 785 4284 of reageer op deze mail.\n\nHartelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het geplande moment'
                ),
            ],
            'pc_build_release' => [
                'html' => $this->emailLayout->renderEmailLayout(
                    'Twój komputer czeka na Ciebie w Digivriend',
                    'Zestaw jest gotowy do drogi',
                    [
                        sprintf('Cześć %s,', $safePayload['customer_name'] ?? 'klient'),
                        'Kończymy właśnie ostatnie testy – Twój zestaw jest złożony, sprawdzony i przygotowany do wydania.',
                        'Poniżej znajdziesz najważniejsze informacje dotyczące przekazania.',
                    ],
                    [
                        'Konfiguracja' => $safePayload['build_reference'] ?? 'Twój zestaw',
                        'Forma wydania' => $safePayload['delivery_method'] ?? 'Odbiór w salonie',
                        'Data przekazania' => $safePayload['release_date'] ?? date('d-m-Y'),
                    ],
                    [
                        'Pobierz protokół przekazania',
                        $safePayload['release_document_url'] ?? '',
                        'Kopia dokumentu znajduje się też w załączniku.',
                    ],
                    [
                        'Jeśli chcesz, możemy wspólnie przejrzeć pierwsze uruchomienie lub odpowiedzieć na dodatkowe pytania – daj nam znać.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Cześć %s,\n\nTwój zestaw PC %s jest gotowy. Forma wydania: %s dnia %s. Załączamy potwierdzenie przekazania. Jeśli masz pytania lub chcesz zmienić termin, daj nam znać.\n\nPozdrawiamy,\nZespół Digivriend",
                    $safePayload['customer_name'] ?? 'klient',
                    $safePayload['build_reference'] ?? 'Twój zestaw',
                    $safePayload['delivery_method'] ?? 'odbiór w salonie',
                    $safePayload['release_date'] ?? date('d-m-Y')
                ),
            ],
            'sms_generic' => [
                'html' => $this->emailLayout->renderPlainBlock($safePayload['body'] ?? ''),
                'text' => $safePayload['body'] ?? '',
            ],
            default => [
                'html' => $this->emailLayout->renderPlainBlock($safePayload['body'] ?? ''),
                'text' => $safePayload['body'] ?? '',
            ],
        };
    }

    private function convertHtmlToText(string $html): string
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $stripped = strip_tags($decoded);
        $normalisedWhitespace = preg_replace('/[ \t]+/', ' ', $stripped) ?? $stripped;
        $normalisedNewlines = preg_replace("/(\r\n|\r|\n)/", "\n", $normalisedWhitespace) ?? $normalisedWhitespace;
        $collapsedNewlines = preg_replace("/\n{3,}/", "\n\n", $normalisedNewlines) ?? $normalisedNewlines;

        return trim($collapsedNewlines);
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
    private function sendEmail(
        string $recipient,
        string $subject,
        string $bodyHtml,
        string $bodyText = '',
        array $attachments = []
    ): array {
        $fromAddress = trim((string) Env::get('MAIL_FROM_ADDRESS', ''));
        if ($fromAddress === '') {
            $fromAddress = 'no-reply@digivriend.local';
        }
        $fromName = (string) Env::get('MAIL_FROM_NAME', 'Digivriend');

        $headers = [];
        $headers[] = 'From: ' . $this->formatAddress($fromAddress, $fromName);
        $headers[] = 'Reply-To: ' . $this->formatAddress($fromAddress, $fromName);
        $headers[] = 'MIME-Version: 1.0';

        $plainBody = trim($bodyText) !== '' ? $bodyText : $this->convertHtmlToText($bodyHtml);
        $plainBody = $plainBody === '' ? ' ' : $plainBody;
        $normalisedPlain = $this->normaliseLineEndings($plainBody);
        $normalisedHtml = $this->normaliseLineEndings($bodyHtml);

        if ($attachments === []) {
            $boundaryAlt = '=_Alt_' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"';

            $parts = [];
            $parts[] = '--' . $boundaryAlt;
            $parts[] = 'Content-Type: text/plain; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $normalisedPlain;
            $parts[] = '--' . $boundaryAlt;
            $parts[] = 'Content-Type: text/html; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $normalisedHtml;
            $parts[] = '--' . $boundaryAlt . '--';
            $parts[] = '';

            $message = implode("\r\n", $parts);
        } else {
            $boundaryMixed = '=_Part_' . bin2hex(random_bytes(16));
            $boundaryAlt = '=_Alt_' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"';

            $parts = [];
            $parts[] = '--' . $boundaryMixed;
            $parts[] = 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"';
            $parts[] = '';
            $parts[] = '--' . $boundaryAlt;
            $parts[] = 'Content-Type: text/plain; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $normalisedPlain;
            $parts[] = '--' . $boundaryAlt;
            $parts[] = 'Content-Type: text/html; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $normalisedHtml;
            $parts[] = '--' . $boundaryAlt . '--';

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

                $parts[] = '--' . $boundaryMixed;
                $parts[] = 'Content-Type: ' . $mime . '; name="' . $this->encodeHeader($name) . '"';
                $parts[] = 'Content-Transfer-Encoding: base64';
                $parts[] = 'Content-Disposition: attachment; filename="' . $this->encodeHeader($name) . '"';
                $parts[] = '';
                $parts[] = $this->normaliseLineEndings($encodedContent);
            }

            $parts[] = '--' . $boundaryMixed . '--';
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