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
            'email' => $safePayload['company_email'] ?? 'servicedesk@digivriend.nl',
            'phone' => $safePayload['company_phone'] ?? '033 - 785 4284',
            'address' => $safePayload['company_address'] ?? 'De Ganskuijl 103B · 3817 EZ Amersfoort',
            'website' => $safePayload['company_website'] ?? 'https://digivriend.nl',
        ];

        return match ($type) {
             'pickup_ready' => [
                'html' => $this->renderEmailLayout(
                    'Uw apparaat staat klaar voor afhalen',
                    'Uw apparaat staat klaar',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        sprintf('Goed nieuws! Uw apparaat %s staat voor u klaar in onze servicebalie. Neem uw ophaalcode mee zodat we u snel kunnen helpen.', $safePayload['device'] ?? 'uw apparaat'),
                        'U bent van harte welkom om een tijdstip te kiezen dat het beste past. Meld u bij aankomst bij onze receptie en wij regelen de rest.',
                    ],
                    [
                        'Apparaat' => $safePayload['device'] ?? 'Uw apparaat',
                        'Ophaalcode' => $safePayload['pickup_code'] ?? '—',
                        'Voorkeursmoment' => $safePayload['ready_date'] ?? 'Kies een geschikt moment',
                    ],
                    null,
                    [
                        'Ons team legt alles graag nog even uit en controleert samen met u de laatste details.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nUw apparaat (%s) staat klaar om opgehaald te worden. Gebruik ophaalcode %s en plan bij voorkeur uw bezoek op %s.\n\nMet vriendelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['device'] ?? 'uw apparaat',
                    $safePayload['pickup_code'] ?? '—',
                    $safePayload['ready_date'] ?? 'een geschikt moment'
                ),
            ],
            'pickup_confirmation' => [
                'html' => $this->renderEmailLayout(
                    'Wij hebben geregistreerd dat uw apparaat is opgehaald',
                    'Bedankt voor uw bezoek',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Wat fijn dat alles gelukt is! We hebben genoteerd dat het apparaat veilig is meegegeven.',
                        'Mocht u later nog vragen hebben over service of accessoires, dan horen we het graag.',
                    ],
                    [
                        'Ophaalcode' => $safePayload['pickup_code'] ?? '—',
                        'Datum van ophalen' => $safePayload['pickup_date'] ?? '—',
                    ],
                    null,
                    [
                        'Bewaar deze bevestiging gerust in uw administratie. Hij bevat alle gegevens over dit bezoek.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nWij hebben bevestigd dat uw apparaat met code %s op %s is opgehaald. Dank voor uw bezoek en tot een volgende keer!\n\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['pickup_code'] ?? '—',
                    $safePayload['pickup_date'] ?? '—'
                ),
            ],
            'intake_confirmation' => [
                'html' => $this->renderEmailLayout(
                    'Uw intake is succesvol ingepland',
                    'Bevestiging intake afspraak',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Dank voor het inplannen van uw bezoek. We kijken ernaar uit om u persoonlijk te ontvangen en meteen met uw apparaat aan de slag te gaan.',
                        'Neem dit bericht mee (digitaal of uitgeprint) zodat we uw intake razendsnel kunnen starten.',
                    ],
                    [
                        'Afspraakmoment' => $safePayload['appointment_at'] ?? 'Het afgesproken tijdstip',
                        'Referentiecode' => $safePayload['reference_code'] ?? '—',
                        'Beschrijving' => $safePayload['notes'] ?? '—',
                    ],
                    null,
                    [
                        'Komt het toch niet uit? Laat het ons weten, dan plannen we direct een nieuw moment voor u.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nBedankt voor het plannen van uw intake. Wij verwachten u op %s in onze vestiging. Neem deze bevestiging en uw apparaat mee. Uw referentiecode is %s.\n\nBeschrijving: %s\n\nTot snel,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het afgesproken tijdstip',
                    $safePayload['reference_code'] ?? '—',
                    $safePayload['notes'] ?? '—'
                ),
            ],
            'intake_arrival' => [
                'html' => $this->renderEmailLayout(
                    'We hebben uw apparaat ontvangen',
                    'Ontvangstbevestiging service intake',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Uw device is veilig geregistreerd in ons systeem. Onze technici starten direct met het onderzoek en houden u op de hoogte.',
                    ],
                    [
                        'Case / referentie' => $safePayload['reference_code'] ?? 'Uw case',
                        'Binnengebracht op' => $safePayload['appointment_at'] ?? 'Het afgesproken moment',
                    ],
                    null,
                    [
                        'U ontvangt bericht zodra we nieuwe bevindingen hebben of als we aanvullende informatie nodig hebben.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nWij bevestigen de ontvangst van uw apparaat voor case %s. Het toestel is op %s bij ons binnengebracht en het onderzoek start direct. U ontvangt een update zodra er nieuws is.\n\nMet vriendelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['reference_code'] ?? 'uw case',
                    $safePayload['appointment_at'] ?? 'het afgesproken moment'
                ),
            ],
            'intake_rescheduled' => [
                'html' => $this->renderEmailLayout(
                    'Uw intake is verplaatst',
                    'Nieuwe intake afspraak bevestigd',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Zoals afgestemd hebben wij de intake voor u verplaatst. Alle gegevens zijn bijgewerkt in onze planning.',
                    ],
                    [
                        'Nieuw afspraakmoment' => $safePayload['appointment_at'] ?? 'Het nieuwe tijdstip',
                        'Referentiecode' => $safePayload['reference_code'] ?? '—',
                    ],
                    null,
                    [
                        'Mocht u opnieuw willen schuiven, laat het ons gerust weten. We zoeken meteen mee naar het beste moment.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nZoals besproken hebben wij uw intake verplaatst naar %s. Uw referentiecode %s blijft ongewijzigd. Laat het ons weten als de planning opnieuw aangepast moet worden.\n\nMet vriendelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het nieuwe tijdstip',
                    $safePayload['reference_code'] ?? '—'
                ),
            ],
            'intake_cancelled' => [
                'html' => $this->renderEmailLayout(
                    'We hebben uw intake geannuleerd',
                    'Bevestiging annulering intake',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'We hebben uw bericht ontvangen en de afspraak volgens afspraak geannuleerd.',
                    ],
                    [
                        'Gepland moment' => $safePayload['appointment_at'] ?? 'Het geplande moment',
                        'Reden van annulering' => $safePayload['cancellation_reason'] ?? 'Geen reden opgegeven',
                    ],
                    null,
                    [
                        'Wanneer het weer uitkomt plannen we graag een nieuw bezoek. Neem gerust contact met ons op.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nUw intake afspraak van %s is geannuleerd. Reden: %s. Wanneer u later alsnog langskomt helpen we u graag verder. Neem gerust contact met ons op voor een nieuwe afspraak.\n\nMet vriendelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het geplande moment',
                    $safePayload['cancellation_reason'] ?? 'geen reden opgegeven'
                ),
            ],
            'intake_no_show' => [
                'html' => $this->renderEmailLayout(
                    'We hebben u gemist bij de intake',
                    'We hebben u gemist',
                    [
                        sprintf('Beste %s,', $safePayload['customer_name'] ?? 'klant'),
                        'Jammer dat we elkaar hebben misgelopen. Geen zorgen: we helpen u graag alsnog verder.',
                    ],
                    [
                        'Gepland moment' => $safePayload['appointment_at'] ?? 'Het geplande moment',
                    ],
                    [
                        'Plan nieuwe intake',
                        $safePayload['reschedule_url'] ?? '',
                        'Kies eenvoudig een moment dat beter past.',
                    ],
                    [
                        'Zodra onze vernieuwde planner klaar is ontvangt u automatisch een nieuwe uitnodiging. Heeft u nu al hulp nodig? Laat het ons weten, dan zoeken we direct mee.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Beste %s,\n\nWe hadden u graag ontvangen op %s, maar we hebben u helaas gemist. Jammer dat het niet is gelukt. Zodra onze nieuwe planner gereed is ontvangt u een link om eenvoudig een nieuwe intake te boeken. Heeft u nu al hulp nodig? Neem dan contact met ons op.\n\nMet vriendelijke groet,\nDigivriend",
                    $safePayload['customer_name'] ?? 'klant',
                    $safePayload['appointment_at'] ?? 'het geplande moment'
                ),
            ],
            'pc_build_release' => [
                'html' => $this->renderEmailLayout(
                    'Twój zestaw PC jest gotowy do odbioru',
                    'Zestaw PC gotowy!',
                    [
                        sprintf('Dzień dobry %s,', $safePayload['customer_name'] ?? 'klient'),
                        'Z przyjemnością informujemy, że Twój zestaw został złożony, przetestowany i jest gotowy do wydania.',
                    ],
                    [
                        'Zestaw' => $safePayload['build_reference'] ?? 'Twój zestaw',
                        'Sposób wydania' => $safePayload['delivery_method'] ?? 'Odbiór w salonie',
                        'Data wydania' => $safePayload['release_date'] ?? date('d-m-Y'),
                    ],
                    [
                        'Pobierz potwierdzenie',
                        $safePayload['release_document_url'] ?? '',
                        'Dokument znajdziesz również w załączniku.',
                    ],
                    [
                        'Jeśli masz dodatkowe pytania dotyczące zestawu lub chcesz omówić konfigurację, skontaktuj się z nami – chętnie pomożemy.',
                    ],
                    $contact
                ),
                'text' => sprintf(
                    "Dzień dobry %s,\n\nZestaw PC %s jest gotowy do przekazania. Sposób wydania: %s dnia %s. W załączniku znajdziesz potwierdzenie wydania. W razie pytań skontaktuj się z nami.\n\nPozdrawiamy,\nZespół Digivriend",
                    $safePayload['customer_name'] ?? 'klient',
                    $safePayload['build_reference'] ?? 'Twój zestaw',
                    $safePayload['delivery_method'] ?? 'odbiór w salonie',
                    $safePayload['release_date'] ?? date('d-m-Y')
                ),
            ],
            'sms_generic' => [
                'html' => $this->renderPlainBlock($safePayload['body'] ?? ''),
                'text' => $safePayload['body'] ?? '',
            ],
            default => [
                'html' => $this->renderPlainBlock($safePayload['body'] ?? ''),
                'text' => $safePayload['body'] ?? '',
            ],
        };
    }

    private function renderEmailLayout(
        string $preheader,
        string $headline,
        array $introParagraphs,
        array $detailRows,
        ?array $cta,
        array $additionalParagraphs,
        array $contact
    ): string {
        $preheaderText = $this->escape($preheader);
        $headlineText = $this->escape($headline);
        $introHtml = $this->buildParagraphs($introParagraphs);
        $detailsHtml = $this->buildDetailRows($detailRows);
        $ctaHtml = $this->buildCtaBlock($cta);
        $additionalHtml = $this->buildParagraphs($additionalParagraphs, true);
        $contactHtml = $this->buildContactBlock($contact);

        $signatureHtml = '<div style="margin-top:32px;">'
            . '<p style="margin:0 0 6px; font-size:15px; line-height:1.6; color:#1c2333;">Met vriendelijke groet,</p>'
            . '<p style="margin:0; font-size:15px; line-height:1.6; color:#1c2333; font-weight:600;">Team Digivriend</p>'
            . '</div>';

        $additionalSection = $additionalHtml === '' ? '' : '<div style="margin-top:28px;">' . $additionalHtml . '</div>';

        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digivriend</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f5fb; font-family:'Helvetica Neue', Arial, sans-serif; color:#1c2333;">
    <span style="display:none!important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; overflow:hidden;">$preheaderText</span>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; background-color:#f4f5fb;">
        <tr>
            <td style="padding:40px 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; max-width:680px; margin:0 auto;">
                    <tr>
                        <td style="padding:0;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; background:#ffffff; border-radius:20px; overflow:hidden; box-shadow:0 24px 60px rgba(28,35,51,0.12);">
                                <tr>
                                    <td style="padding:40px 36px; background:linear-gradient(125deg,#2463eb,#10b4d2);">
                                        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;">
                                            <tr>
                                                <td style="font-size:26px; font-weight:700; color:#ffffff; letter-spacing:0.4px;">Digivriend</td>
                                            </tr>
                                            <tr>
                                                <td style="padding-top:10px; font-size:16px; color:rgba(255,255,255,0.92); line-height:1.5;">$headlineText</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:36px 36px 28px;">
                                        $introHtml
                                        $detailsHtml
                                        $ctaHtml
                                        $additionalSection
                                        $signatureHtml
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:24px 36px 36px; background-color:#f7f8fc;">
                                        $contactHtml
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="text-align:center; font-size:12px; color:#7b859b; padding:18px 12px 0;">
                            © $year Digivriend · Service met een glimlach
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    private function renderPlainBlock(string $content): string
    {
        $text = trim($content);

        if ($text === '') {
            return '<p style="margin:0; font-family:Helvetica, Arial, sans-serif; font-size:16px; line-height:1.6; color:#1c2333;">&nbsp;</p>';
        }

        return '<p style="margin:0; font-family:Helvetica, Arial, sans-serif; font-size:16px; line-height:1.6; color:#1c2333;">'
            . nl2br($this->escape($text))
            . '</p>';
    }

    private function buildParagraphs(array $paragraphs, bool $subtle = false): string
    {
        $blocks = [];

        foreach ($paragraphs as $paragraph) {
            if (!is_string($paragraph)) {
                continue;
            }

            $trimmed = trim($paragraph);
            if ($trimmed === '') {
                continue;
            }

            $blocks[] = sprintf(
                '<p style="margin:0 0 %dpx; font-size:%s; line-height:1.65; color:#1c2333;">%s</p>',
                $subtle ? 16 : 18,
                $subtle ? '15px' : '16px',
                nl2br($this->escape($trimmed))
            );
        }

        return implode('', $blocks);
    }

    private function buildDetailRows(array $rows): string
    {
        $rowHtml = [];

        foreach ($rows as $label => $value) {
            if (!is_string($value)) {
                continue;
            }

            $valueTrimmed = trim($value);
            if ($valueTrimmed === '') {
                continue;
            }

            $labelText = $this->escape(is_string($label) ? $label : (string) $label);
            $valueText = nl2br($this->escape($valueTrimmed));

            $rowHtml[] = <<<HTML
<tr>
    <td style="padding:14px 18px; width:44%; font-size:14px; font-weight:600; color:#1c2333; background-color:#f1f3fb; border-bottom:1px solid #e1e5f2;">$labelText</td>
    <td style="padding:14px 18px; font-size:14px; color:#384152; background-color:#f8f9ff; border-bottom:1px solid #e1e5f2;">$valueText</td>
</tr>
HTML;
        }

        if ($rowHtml === []) {
            return '';
        }

        $lastIndex = array_key_last($rowHtml);
        if ($lastIndex !== null) {
            $rowHtml[$lastIndex] = str_replace('border-bottom:1px solid #e1e5f2;', 'border-bottom:none;', $rowHtml[$lastIndex]);
        }

        $rowsMarkup = implode('', $rowHtml);

        return <<<HTML
<div style="margin-top:28px;">
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%; border-radius:14px; overflow:hidden; border:1px solid #e1e5f2;">
        $rowsMarkup
    </table>
</div>
HTML;
    }

    private function buildCtaBlock(?array $cta): string
    {
        if ($cta === null) {
            return '';
        }

        $label = isset($cta['label']) ? (string) $cta['label'] : (string) ($cta[0] ?? '');
        $url = isset($cta['url']) ? (string) $cta['url'] : (string) ($cta[1] ?? '');
        $subtext = isset($cta['subtext']) ? (string) $cta['subtext'] : (string) ($cta[2] ?? '');

        $hasLabel = trim($label) !== '';
        $hasUrl = trim($url) !== '';
        $hasSubtext = trim($subtext) !== '';

        if (!$hasLabel && !$hasSubtext) {
            return '';
        }

        $labelText = $this->escape($label);
        $subtextText = $hasSubtext ? nl2br($this->escape($subtext)) : '';

        if ($hasUrl && $hasLabel) {
            $urlText = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $subtextBlock = $hasSubtext
                ? '<p style="margin:14px 0 0; font-size:13px; color:rgba(255,255,255,0.9);">' . $subtextText . '</p>'
                : '';

            return <<<HTML
<div style="margin-top:30px; padding:26px; border-radius:16px; background:linear-gradient(120deg,#2463eb,#4dd0e1); color:#ffffff; text-align:center;">
    <a href="$urlText" style="display:inline-block; padding:14px 26px; background-color:#ffffff; color:#1c3faa; font-weight:600; font-size:15px; border-radius:999px; text-decoration:none; box-shadow:0 10px 25px rgba(12,54,140,0.25);">$labelText</a>
    $subtextBlock
</div>
HTML;
        }

        $content = '<p style="margin:0; font-size:15px; font-weight:600; color:#1c2333;">' . $labelText . '</p>';
        if ($hasSubtext) {
            $content .= '<p style="margin:8px 0 0; font-size:13px; color:#4f5d75;">' . $subtextText . '</p>';
        }

        return '<div style="margin-top:28px; padding:22px; border-radius:16px; background-color:#eef2ff;">' . $content . '</div>';
    }

    private function buildContactBlock(array $contact): string
    {
        $email = isset($contact['email']) ? trim((string) $contact['email']) : '';
        $phone = isset($contact['phone']) ? trim((string) $contact['phone']) : '';
        $address = isset($contact['address']) ? trim((string) $contact['address']) : '';
        $website = isset($contact['website']) ? trim((string) $contact['website']) : '';

        $items = [];
        if ($email !== '') {
            $items[] = '<span style="display:inline-block; margin-right:16px;"><span style="font-weight:600; color:#1c2333;">E-mail:</span> <a href="mailto:'
                . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" style="color:#2463eb; text-decoration:none;">' . $this->escape($email) . '</a></span>';
        }

        if ($phone !== '') {
            $sanitisedPhoneLink = preg_replace('/[^+\d]/', '', $phone);
            if ($sanitisedPhoneLink === null || $sanitisedPhoneLink === '') {
                $sanitisedPhoneLink = $phone;
            }

            $items[] = '<span style="display:inline-block; margin-right:16px;"><span style="font-weight:600; color:#1c2333;">Telefoon:</span> <a href="tel:'
                . htmlspecialchars($sanitisedPhoneLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" style="color:#2463eb; text-decoration:none;">' . $this->escape($phone) . '</a></span>';
        }

        if ($address !== '') {
            $items[] = '<span style="display:block; margin-top:8px; font-size:13px; color:#4f5d75;">' . $this->escape($address) . '</span>';
        }

        if ($website !== '') {
            $items[] = '<span style="display:inline-block; margin-top:8px;"><a href="'
                . htmlspecialchars($website, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" style="color:#2463eb; text-decoration:none;">' . $this->escape($website) . '</a></span>';
        }

        $itemsHtml = implode('<br>', $items);

        return '<div style="font-size:13px; line-height:1.7; color:#4f5d75;">'
            . '<p style="margin:0 0 10px; font-size:14px; font-weight:600; color:#1c2333;">Vragen? Wij staan voor u klaar.</p>'
            . ($itemsHtml !== '' ? $itemsHtml : '')
            . '</div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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