<?php

return [
    'app' => [
        'name' => 'Digivriend',
    ],
    'nav' => [
        'dashboard' => 'Dashboard',
        'intake' => 'Klantregistratie',
        'devices' => 'Klanten & apparaten',
        'archive' => 'Archief',
        'data_recovery' => 'Data recovery',
        'calendar' => 'Kalender',
        'documents' => 'Documenten',
        'inventory' => 'Magazijn',
        'pc_builder' => 'PC-bouw',
        'employees' => 'Medewerkers',
        'logout' => 'Afmelden',
        'dump_database' => 'Database export',
        'aria' => [
            'main' => 'Hoofdnavigatie',
        ],
    ],
    'language' => [
        'switcher' => [
            'label' => 'Interfacetaal',
        ],
        'options' => [
            'nl' => 'Nederlands',
            'pl' => 'Pools',
            'en' => 'Engels',
        ],
    ],
    'auth' => [
        'login' => [
            'title' => 'Inloggen - Digivriend',
            'heading' => 'Inloggen',
            'username' => 'Gebruikersnaam',
            'password' => 'Wachtwoord',
            'button' => 'Aanmelden',
            'footer' => 'Gebruik de beheerdersgegevens uit het .env-bestand om toegang te krijgen.',
            'error' => [
                'invalid_session' => 'Ongeldige sessie. Vernieuw de pagina en probeer het opnieuw.',
                'invalid_credentials' => 'Onjuiste combinatie van gebruikersnaam en wachtwoord.',
            ],
        ],
    ],
    'messages' => [
        'session_expired' => 'Sessie verlopen. Vernieuw de pagina en probeer het opnieuw.',
    ],
    'employees' => [
        'meta' => [
            'title' => 'Medewerkers - Digivriend',
        ],
        'hero' => [
            'heading' => 'Digivriend-team',
            'description' => 'Bouw planningen en ontwikkel teamcompetenties op één plek. Profiel, beschikbaarheid en werkdruk zijn direct beschikbaar.',
            'quick_actions' => [
                'group_label' => 'Snelle teamacties',
                'add' => 'Medewerker toevoegen',
                'manage' => 'Profiel beheren',
            ],
        ],
        'filters' => [
            'aria' => 'Statusfilter',
            'label' => 'Status',
            'options' => [
                'active' => 'Actief',
                'inactive' => 'Inactief',
                'all' => 'Alle',
            ],
        ],
        'stats' => [
            'aria' => 'Teamsamenvatting',
            'total_label' => 'Medewerkers',
            'view_meta' => 'in de ":view"-weergave',
            'absences_label' => 'Geplande afwezigheden',
            'absences_meta_team' => 'voor het team',
            'absences_meta_employee' => 'voor :name',
            'upcoming_label' => 'Aankomende acties',
            'upcoming_meta' => 'afspraken en taken om uit te voeren',
        ],
        'permissions' => [
            'cases_assign' => 'Zaken toewijzen',
            'cases_approve' => 'Kwaliteitscontrole goedkeuren',
            'calendar_manage' => 'Agenda beheren',
            'calendar_self' => 'Eigen afspraken bewerken',
            'inventory_manage' => 'Magazijn beheren',
            'documents_publish' => 'Documenten publiceren en versiebeheer',
            'employees_manage' => 'Medewerkers beheren',
        ],
        'messages' => [
            'employee_created' => 'Nieuwe medewerker toegevoegd.',
            'employee_updated' => 'Medewerkergegevens bijgewerkt.',
            'status_changed' => 'Medewerkerstatus gewijzigd.',
            'availability_added' => 'Beschikbaarheidsinvoer toegevoegd.',
        ],
        'errors' => [
            'invalid_employee_id' => 'Ongeldige medewerker-id.',
            'unsupported_status' => 'Niet-ondersteunde medewerkerstatus.',
            'select_employee' => 'Selecteer een medewerker.',
            'invalid_date_range' => 'Geef een geldig datumbereik op.',
            'end_before_start' => 'Einddatum moet later zijn dan startdatum.',
            'unsupported_language' => 'Niet-ondersteunde taalkeuze.',
        ],
        'panels' => [
            'list' => [
                'aria' => 'Medewerkerslijst',
                'title' => 'Personeel Digivriend',
                'description' => 'Selecteer een profiel om details en activiteiten te bekijken.',
                'empty' => 'Geen medewerkers om te tonen.',
                'add' => 'Toevoegen aan team',
                'unknown_name' => 'Onbekend',
                'default_role' => 'Specialist',
            ],
            'profile' => [
                'aria' => 'Profieloverzicht',
                'title' => 'Medewerkersprofiel',
                'description' => 'Belangrijke persoonlijke en contactgegevens.',
                'empty' => 'Selecteer een medewerker uit de lijst om details te zien.',
                'status_active' => 'Actief',
                'status_inactive' => 'Inactief',
                'role' => 'Rol:',
                'department' => 'Afdeling:',
                'email' => 'E-mail:',
                'phone' => 'Telefoon:',
                'details' => 'Alle details',
            ],
            'tasks' => [
                'aria' => 'Overzicht aankomende taken',
                'title' => 'Aankomende taken',
                'description' => 'Volg toegewezen zaken en afspraken.',
                'empty' => 'Geen geplande acties voor het geselecteerde bereik.',
                'case_prefix' => 'Zaak #',
                'role' => 'Rol:',
                'status' => 'Status:',
                'default_visit_title' => 'Afspraak',
                'manage' => 'Taken beheren',
            ],
            'availability' => [
                'title' => 'Beschikbaarheid',
                'empty' => 'Geen geplande afwezigheden.',
                'table' => [
                    'type' => 'Type',
                    'range' => 'Periode',
                    'reason' => 'Reden',
                    'none' => '—',
                ],
            ],
            'assignments' => [
                'title' => 'Toegewezen zaken',
                'empty' => 'Geen actieve zaken.',
                'table' => [
                    'case' => 'Zaak',
                    'type' => 'Type',
                    'status' => 'Status',
                    'role' => 'Rol',
                    'assigned_at' => 'Toegewezen',
                ],
            ],
            'calendar' => [
                'title' => 'Agenda-afspraken',
                'empty' => 'Geen geplande afspraken.',
                'table' => [
                    'time' => 'Tijd',
                    'title' => 'Titel',
                    'status' => 'Status',
                    'case' => 'Zaak',
                    'none' => '—',
                ],
            ],
        ],
        'footer' => [
            'copyright' => '© :year Digivriend. Alle rechten voorbehouden.',
        ],
        'modals' => [
            'create' => [
                'title' => 'Medewerker toevoegen',
                'heading' => 'Nieuwe medewerker',
            ],
            'manage' => [
                'title' => 'Profiel en beschikbaarheid',
                'empty' => 'Selecteer een medewerker om het profiel te bewerken.',
                'heading' => 'Medewerkersprofiel',
            ],
        ],
        'forms' => [
            'profile' => [
                'full_name' => 'Volledige naam',
                'email' => 'E-mailadres',
                'phone' => 'Telefoon',
                'role' => 'Systeemrol',
                'department' => 'Afdeling',
                'position' => 'Functie',
                'color' => 'Kalenderkleur',
                'timezone' => 'Tijdzone',
                'timezone_placeholder' => 'Europe/Amsterdam',
                'hired_at' => 'Datum indiensttreding',
                'terminated_at' => 'Einddatum',
                'language' => 'Interfacetaal',
                'permissions' => 'Rechten',
                'submit_create' => 'Medewerker toevoegen',
                'submit_update' => 'Wijzigingen opslaan',
            ],
            'status' => [
                'deactivate' => 'Medewerker deactiveren',
                'activate' => 'Medewerker activeren',
            ],
            'availability' => [
                'type' => 'Type',
                'type_options' => [
                    'leave' => 'Vakantie',
                    'training' => 'Training',
                    'remote' => 'Thuiswerken',
                    'unavailable' => 'Niet beschikbaar',
                ],
                'start' => 'Start',
                'end' => 'Einde',
                'reason' => 'Reden',
                'submit' => 'Invoer toevoegen',
            ],
        ],
    ],
    'dashboard' => [
        'meta' => [
            'title' => 'Digivriend - Dashboard',
        ],
        'header' => [
            'logo_aria' => 'Digivriend dashboard',
            'subtitle' => 'Serviceplatform',
        ],
        'hero' => [
            'badge' => 'Realtime overzicht',
            'title' => 'Digivriend Operations Dashboard',
            'summary' => ':open_cases actieve cases en :pending_notifications meldingen wachten op opvolging. Houd magazijn en communicatie real-time in het oog.',
            'metrics' => [
                'active_cases' => [
                    'label' => 'Actieve cases',
                    'hint_waiting' => ':days dagen wachttijd',
                    'hint_clear' => 'Directe opvolging',
                ],
                'today_pickups' => [
                    'label' => 'Ophaalmomenten vandaag',
                    'hint_any' => 'Plan overdracht en communicatie',
                    'hint_none' => 'Geen ophaalacties gepland',
                ],
                'notifications' => [
                    'label' => 'Open meldingen',
                    'hint_any' => 'Nog te informeren klanten',
                    'hint_none' => 'Alle klanten op de hoogte',
                ],
            ],
            'meta' => [
                'last_update' => [
                    'label' => 'Laatste update',
                ],
                'period' => [
                    'label' => 'Periode',
                ],
                'period_value' => 'Laatste :days dagen',
                'filter' => [
                    'label' => 'Actief filter',
                ],
                'last_activity' => [
                    'label' => 'Laatste activiteit',
                ],
                'last_activity_none' => 'Nog geen activiteit',
                'filter_all' => 'Alle cases',
            ],
            'actions' => [
                'pickup' => 'Nieuwe ophaalbevestiging',
                'customer_notification' => 'Nieuwe klantmelding',
                'network_check' => 'Netwerkcheck-brief',
            ],
        ],
        'filters' => [
            'aria' => 'Dashboardfilters',
            'case_type' => [
                'label' => 'Case type',
                'all' => 'Alle typen',
            ],
            'period' => [
                'label' => 'Periode',
                'option' => 'Laatste :days dagen',
            ],
            'submit' => 'Filter toepassen',
        ],
        'highlights' => [
            'aria' => 'Belangrijkste KPI\'s',
            'customers' => [
                'title' => 'Klantbestand',
                'subtitle' => 'Unieke profielen in beheer',
                'hint' => ':cases actieve cases gekoppeld',
            ],
            'cases' => [
                'title' => 'Case traject',
                'subtitle' => 'Werkvoorraad in geselecteerde periode',
                'hint' => ':completed afgerond',
                'progress' => ':rate% van de cases afgerond',
                'action' => 'Diepte-inzicht',
            ],
            'warehouse' => [
                'title' => 'Magazijnstatus',
                'subtitle' => 'Beschikbaarheid & reserveringen',
                'hint' => ':available beschikbaar · :ready klaar',
                'progress_ready' => ':rate% klaar voor uitgifte',
                'action' => 'Bekijk magazijn',
            ],
            'notifications' => [
                'title' => 'Communicatie',
                'subtitle' => 'Uitgestuurde notificaties',
                'hint' => ':pending meldingen wachten nog',
                'progress' => ':rate% afgehandeld',
                'action' => 'Bekijk kanalen',
            ],
            'lead_time' => [
                'title' => 'Gem. doorlooptijd',
                'subtitle' => 'Van gereed tot opgehaald',
                'value' => ':days dagen',
                'hint' => 'Focus op snelle opvolging van gereedmeldingen.',
            ],
            'wait_time' => [
                'title' => 'Langste wachttijd',
                'subtitle' => 'Hoelang staat de oudste case klaar?',
                'value' => ':days dagen',
                'empty' => 'Geen wachtrij',
                'hint' => 'Monitor op escalatie en extra opvolging.',
            ],
        ],
        'activity' => [
            'aria' => 'Teamactiviteiten en communicatie',
            'cases' => [
                'title' => 'Activiteit laatste :days dagen',
                'subtitle' => 'Inzichten in de case-updates binnen de geselecteerde periode.',
                'range' => ':start – :end',
                'stats' => [
                    'updates' => 'Case-updates',
                    'completed' => 'Afgerond',
                    'success' => 'Succesratio',
                ],
                'footer' => [
                    'label' => 'Laatste update',
                ],
            ],
            'notifications' => [
                'title' => 'Verstuurde meldingen',
                'subtitle' => 'Overzicht van kanalen die klanten recent bereikten.',
                'total' => 'Totaal :total',
                'empty' => 'Er zijn nog geen meldingen verzonden in deze periode.',
                'footer' => [
                    'open' => 'Open meldingen',
                ],
            ],
            'recent_cases' => [
                'title' => 'Laatste cases',
                'subtitle' => 'Recent bijgewerkte dossiers voor snelle opvolging.',
                'empty' => 'Nog geen cases geregistreerd.',
                'link' => 'Bekijk case',
                'meta_template' => 'Type: :type · Status: :status · :updated_at',
            ],
            'notes' => [
                'title' => 'Recente notities',
                'subtitle' => 'Laatste klantinteracties en servicelogboek.',
                'empty' => 'Er zijn nog geen notities toegevoegd.',
                'case_label' => 'Case',
                'customer_label' => 'Klant',
            ],
        ],
        'panels' => [
            'grid_aria' => 'Operationele details',
            'pickups' => [
                'title' => 'Openstaande ophaalbevestigingen',
                'subtitle' => 'Realtime overzicht van klanten die gereed staan',
                'view_all' => 'Bekijk alle',
                'headers' => [
                    'customer' => 'Klant',
                    'code' => 'Code',
                    'ready_date' => 'Datum gereed',
                    'contact' => 'Contact',
                ],
                'empty' => 'Geen openstaande bevestigingen.',
                'phone' => 'Tel',
                'email' => 'E-mail',
                'status' => 'Status',
            ],
            'case_summary' => [
                'title' => 'Case verdeling',
                'subtitle' => 'Inzicht per type en status',
                'empty' => 'Nog geen cases aangemaakt.',
            ],
            'trend' => [
                'title' => 'Activiteit laatste :days dagen',
                'subtitle' => 'Aantal case-updates per dag',
                'empty' => 'Geen case-activiteit in deze periode.',
            ],
            'notifications' => [
                'title' => 'Verstuurde meldingen',
                'subtitle' => 'Kanaalprestatie en follow-up',
                'empty' => 'Nog geen meldingen verzonden in deze periode.',
                'footer' => 'Afgeronde cases: :count',
            ],
        ],
        'modals' => [
            'cases' => [
                'title' => 'Diepte-inzicht case traject',
                'summary' => 'In de laatste :days dagen zijn :created cases aangemaakt waarvan :completed werden afgerond.',
                'completion' => 'Afrondingspercentage: :rate%.',
                'empty' => 'Nog geen cases aangemaakt.',
                'tip' => 'Tip: filter op type om specifieke diensten sneller te analyseren.',
            ],
            'warehouse' => [
                'title' => 'Magazijninzicht',
                'summary' => 'Het magazijn bevat :total registraties met :ready klaar voor uitgifte en :reserved gereserveerd.',
                'available' => 'Beschikbaar',
                'ready' => 'Klaar',
                'reserved' => 'Gereserveerd',
                'tip' => 'Plan uitgiftes vanuit dit overzicht en stem af met het serviceteam.',
            ],
            'notifications' => [
                'title' => 'Notificatiekanalen',
                'summary' => 'In de geselecteerde periode zijn :total meldingen verstuurd naar klanten.',
                'empty' => 'Nog geen meldingen verzonden in deze periode.',
                'open' => 'Open meldingen: :pending.',
                'tip' => 'Laat meldingen automatisch opvolgen of plan handmatige acties direct.',
            ],
        ],
        'common' => [
            'unknown' => 'Onbekend',
        ],
        'case_types' => [
            'pickup' => 'Ophaal',
            'delivery' => 'Levering',
            'repair' => 'Reparatie',
            'diagnostics' => 'Diagnose',
        ],
        'case_status' => [
            'klaar' => 'Klaar',
            'open' => 'Open',
            'in_behandeling' => 'In behandeling',
            'gesloten' => 'Gesloten',
            'opgehaald' => 'Opgehaald',
        ],
        'warehouse' => [
            'status' => [
                'ready' => 'Klaar',
                'reserved' => 'Gereserveerd',
                'processing' => 'In verwerking',
                'waiting' => 'Wachtend',
            ],
        ],
        'notification_channels' => [
            'sms' => 'SMS',
            'email' => 'E-mail',
            'phone' => 'Telefoon',
            'whatsapp' => 'WhatsApp',
        ],
        'footer' => [
            'copyright' => '© :year :app. Alle rechten voorbehouden.',
        ],
    ],
    'common' => [
        'close' => 'Sluiten',
    ],
];