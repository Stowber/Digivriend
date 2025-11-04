<?php

return [
    'app' => [
        'name' => 'Digivriend',
    ],
    'nav' => [
        'dashboard' => 'Pulpit',
        'intake' => 'Rejestracja klienta',
        'devices' => 'Klienci i urządzenia',
        'archive' => 'Archiwum',
        'data_recovery' => 'Odzyskiwanie danych',
        'calendar' => 'Kalendarz',
        'documents' => 'Dokumenty',
        'inventory' => 'Magazyn',
        'pc_builder' => 'Budowa PC',
        'employees' => 'Pracownicy',
        'logout' => 'Wyloguj',
        'dump_database' => 'Eksport bazy danych',
        'aria' => [
            'main' => 'Nawigacja główna',
        ],
    ],
    'language' => [
        'switcher' => [
            'label' => 'Wybierz język',
        ],
        'options' => [
            'nl' => 'Holenderski',
            'pl' => 'Polski',
            'en' => 'Angielski',
        ],
    ],
    'auth' => [
        'login' => [
            'title' => 'Logowanie - Digivriend',
            'heading' => 'Logowanie',
            'username' => 'Nazwa użytkownika',
            'password' => 'Hasło',
            'button' => 'Zaloguj się',
            'footer' => 'Użyj danych administratora z pliku .env, aby uzyskać dostęp.',
            'error' => [
                'invalid_session' => 'Nieprawidłowa sesja. Odśwież stronę i spróbuj ponownie.',
                'invalid_credentials' => 'Nieprawidłowa kombinacja nazwy użytkownika i hasła.',
            ],
        ],
    ],
    'messages' => [
        'session_expired' => 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.',
    ],
    'employees' => [
        'meta' => [
            'title' => 'Pracownicy - Digivriend',
        ],
        'hero' => [
            'heading' => 'Zespół Digivriend',
            'description' => 'Buduj harmonogram i rozwijaj kompetencje zespołu w jednym miejscu. Profil, dostępność oraz obciążenie pracowników masz teraz pod ręką.',
            'quick_actions' => [
                'group_label' => 'Szybkie akcje zespołu',
                'add' => 'Dodaj pracownika',
                'manage' => 'Zarządzaj profilem',
            ],
        ],
        'filters' => [
            'aria' => 'Filtr statusu',
            'label' => 'Status',
            'options' => [
                'active' => 'Aktywni',
                'inactive' => 'Nieaktywni',
                'all' => 'Wszyscy',
            ],
        ],
        'stats' => [
            'aria' => 'Podsumowanie zespołu',
            'total_label' => 'Pracownicy',
            'view_meta' => 'w widoku ":view"',
            'absences_label' => 'Planowane nieobecności',
            'absences_meta_team' => 'dla zespołu',
            'absences_meta_employee' => 'dla :name',
            'upcoming_label' => 'Nadchodzące działania',
            'upcoming_meta' => 'wizyty i zadania do realizacji',
        ],
        'permissions' => [
            'cases_assign' => 'Przydzielanie zleceń',
            'cases_approve' => 'Akceptacja kontroli jakości',
            'calendar_manage' => 'Pełne zarządzanie kalendarzem',
            'calendar_self' => 'Edycja własnych wizyt',
            'inventory_manage' => 'Zarządzanie magazynem',
            'documents_publish' => 'Publikacja i wersjonowanie dokumentów',
            'employees_manage' => 'Zarządzanie pracownikami',
        ],
        'messages' => [
            'employee_created' => 'Dodano nowego pracownika.',
            'employee_updated' => 'Zaktualizowano dane pracownika.',
            'status_changed' => 'Zmieniono status pracownika.',
            'availability_added' => 'Dodano wpis dostępności.',
        ],
        'errors' => [
            'invalid_employee_id' => 'Nieprawidłowy identyfikator pracownika.',
            'unsupported_status' => 'Nieobsługiwany status pracownika.',
            'select_employee' => 'Wybierz pracownika.',
            'invalid_date_range' => 'Podaj prawidłowy zakres dat.',
            'end_before_start' => 'Data zakończenia musi być późniejsza od rozpoczęcia.',
            'unsupported_language' => 'Nieobsługiwany wybór języka.',
        ],
        'panels' => [
            'list' => [
                'aria' => 'Lista pracowników',
                'title' => 'Kadra Digivriend',
                'description' => 'Wybierz profil, aby przejść do szczegółów i historii aktywności.',
                'empty' => 'Brak pracowników do wyświetlenia.',
                'add' => 'Dodaj do zespołu',
                'unknown_name' => 'Nieznany',
                'default_role' => 'Specjalista',
            ],
            'profile' => [
                'aria' => 'Profil skrócony',
                'title' => 'Profil pracownika',
                'description' => 'Kluczowe informacje personalne i kontaktowe.',
                'empty' => 'Wybierz pracownika z listy obok, aby zobaczyć szczegóły.',
                'status_active' => 'Aktywny',
                'status_inactive' => 'Nieaktywny',
                'role' => 'Rola:',
                'department' => 'Dział:',
                'email' => 'E-mail:',
                'phone' => 'Telefon:',
                'details' => 'Pełne szczegóły',
            ],
            'tasks' => [
                'aria' => 'Panel nadchodzących zadań',
                'title' => 'Nadchodzące zadania',
                'description' => 'Monitoruj przydzielone sprawy i wizyty.',
                'empty' => 'Brak zaplanowanych działań dla wybranego zakresu.',
                'case_prefix' => 'Zlecenie #',
                'role' => 'Rola:',
                'status' => 'Status:',
                'default_visit_title' => 'Wizyta',
                'manage' => 'Zarządzaj zadaniami',
            ],
            'availability' => [
                'title' => 'Dostępność',
                'empty' => 'Brak zaplanowanych nieobecności.',
                'table' => [
                    'type' => 'Typ',
                    'range' => 'Zakres',
                    'reason' => 'Powód',
                    'none' => '—',
                ],
            ],
            'assignments' => [
                'title' => 'Przydzielone zlecenia',
                'empty' => 'Brak aktywnych zleceń.',
                'table' => [
                    'case' => 'Zlecenie',
                    'type' => 'Typ',
                    'status' => 'Status',
                    'role' => 'Rola',
                    'assigned_at' => 'Przydzielono',
                ],
            ],
            'calendar' => [
                'title' => 'Wizyty w kalendarzu',
                'empty' => 'Brak zaplanowanych wizyt.',
                'table' => [
                    'time' => 'Termin',
                    'title' => 'Tytuł',
                    'status' => 'Status',
                    'case' => 'Zlecenie',
                    'none' => '—',
                ],
            ],
        ],
        'footer' => [
            'copyright' => '© :year Digivriend. Wszystkie prawa zastrzeżone.',
        ],
        'modals' => [
            'create' => [
                'title' => 'Dodaj pracownika',
                'heading' => 'Nowy pracownik',
            ],
            'manage' => [
                'title' => 'Profil i dostępność',
                'empty' => 'Wybierz pracownika, aby edytować jego profil.',
                'heading' => 'Profil pracownika',
            ],
        ],
        'forms' => [
            'profile' => [
                'full_name' => 'Imię i nazwisko',
                'email' => 'Adres e-mail',
                'phone' => 'Telefon',
                'role' => 'Rola systemowa',
                'department' => 'Dział',
                'position' => 'Stanowisko',
                'color' => 'Kolor kalendarza',
                'timezone' => 'Strefa czasowa',
                'timezone_placeholder' => 'Europe/Warsaw',
                'hired_at' => 'Data zatrudnienia',
                'terminated_at' => 'Data zakończenia',
                'language' => 'Język interfejsu',
                'permissions' => 'Uprawnienia',
                'submit_create' => 'Dodaj pracownika',
                'submit_update' => 'Zapisz zmiany',
            ],
            'status' => [
                'deactivate' => 'Zawieś pracownika',
                'activate' => 'Aktywuj pracownika',
            ],
            'availability' => [
                'type' => 'Typ wpisu',
                'type_options' => [
                    'leave' => 'Urlop',
                    'training' => 'Szkolenie',
                    'remote' => 'Praca zdalna',
                    'unavailable' => 'Niedostępny',
                ],
                'start' => 'Początek',
                'end' => 'Koniec',
                'reason' => 'Powód',
                'submit' => 'Dodaj wpis',
            ],
        ],
    ],
    'dashboard' => [
        'meta' => [
            'title' => 'Digivriend - Panel',
        ],
        'header' => [
            'logo_aria' => 'Panel Digivriend',
            'subtitle' => 'Platform usługowe',
        ],
        'hero' => [
            'badge' => 'Podgląd w czasie rzeczywistym',
            'title' => 'Panel operacyjny Digivriend',
            'summary' => ':open_cases aktywnych spraw i :pending_notifications powiadomień czeka na działanie. Monitoruj magazyn i komunikację na bieżąco.',
            'metrics' => [
                'active_cases' => [
                    'label' => 'Aktywne sprawy',
                    'hint_waiting' => ':days dni oczekiwania',
                    'hint_clear' => 'Natychmiastowa reakcja',
                ],
                'today_pickups' => [
                    'label' => 'Dzisiejsze odbiory',
                    'hint_any' => 'Zaplanuj wydanie i kontakt',
                    'hint_none' => 'Brak zaplanowanych odbiorów',
                ],
                'notifications' => [
                    'label' => 'Otwarte powiadomienia',
                    'hint_any' => 'Klienci do poinformowania',
                    'hint_none' => 'Wszyscy klienci poinformowani',
                ],
            ],
            'meta' => [
                'last_update' => [
                    'label' => 'Ostatnia aktualizacja',
                ],
                'period' => [
                    'label' => 'Okres',
                ],
                'period_value' => 'Ostatnie :days dni',
                'filter' => [
                    'label' => 'Aktywny filtr',
                ],
                'last_activity' => [
                    'label' => 'Ostatnia aktywność',
                ],
                'last_activity_none' => 'Brak aktywności',
                'filter_all' => 'Wszystkie sprawy',
            ],
            'actions' => [
                'pickup' => 'Nowe potwierdzenie odbioru',
                'customer_notification' => 'Nowe powiadomienie klienta',
                'network_check' => 'Pismo z kontrolą sieci',
            ],
        ],
        'filters' => [
            'aria' => 'Filtry panelu',
            'case_type' => [
                'label' => 'Typ sprawy',
                'all' => 'Wszystkie typy',
            ],
            'period' => [
                'label' => 'Okres',
                'option' => 'Ostatnie :days dni',
            ],
            'submit' => 'Zastosuj filtr',
        ],
        'highlights' => [
            'aria' => 'Kluczowe wskaźniki',
            'customers' => [
                'title' => 'Baza klientów',
                'subtitle' => 'Zarządzane profile klientów',
                'hint' => ':cases aktywnych spraw powiązanych',
            ],
            'cases' => [
                'title' => 'Przepływ spraw',
                'subtitle' => 'Praca w wybranym okresie',
                'hint' => ':completed zakończonych',
                'progress' => 'Zrealizowano :rate% spraw',
                'action' => 'Pokaż szczegóły',
            ],
            'warehouse' => [
                'title' => 'Stan magazynu',
                'subtitle' => 'Dostępność i rezerwacje',
                'hint' => ':available dostępne · :ready gotowe',
                'progress_ready' => ':rate% gotowe do wydania',
                'action' => 'Podgląd magazynu',
            ],
            'notifications' => [
                'title' => 'Komunikacja',
                'subtitle' => 'Wysłane powiadomienia',
                'hint' => ':pending powiadomień czeka',
                'progress' => 'Obsłużono :rate%',
                'action' => 'Kanały powiadomień',
            ],
            'lead_time' => [
                'title' => 'Śr. czas realizacji',
                'subtitle' => 'Od gotowe do odebrane',
                'value' => ':days dni',
                'hint' => 'Dbaj o szybką reakcję na gotowe zgłoszenia.',
            ],
            'wait_time' => [
                'title' => 'Najdłuższe oczekiwanie',
                'subtitle' => 'Jak długo czeka najstarsza sprawa?',
                'value' => ':days dni',
                'empty' => 'Brak kolejki',
                'hint' => 'Monitoruj eskalacje i dodatkowe działania.',
            ],
        ],
        'activity' => [
            'aria' => 'Aktywność zespołu i komunikacja',
            'cases' => [
                'title' => 'Aktywność ostatnie :days dni',
                'subtitle' => 'Zmiany w sprawach w wybranym okresie.',
                'range' => ':start – :end',
                'stats' => [
                    'updates' => 'Aktualizacje spraw',
                    'completed' => 'Zakończone',
                    'success' => 'Skuteczność',
                ],
                'footer' => [
                    'label' => 'Ostatnia aktualizacja',
                ],
            ],
            'notifications' => [
                'title' => 'Wysłane powiadomienia',
                'subtitle' => 'Kanały, które ostatnio dotarły do klientów.',
                'total' => 'Łącznie :total',
                'empty' => 'W tym okresie nie wysłano jeszcze powiadomień.',
                'footer' => [
                    'open' => 'Otwarte powiadomienia',
                ],
            ],
            'recent_cases' => [
                'title' => 'Najnowsze sprawy',
                'subtitle' => 'Ostatnio aktualizowane rekordy do szybkiej reakcji.',
                'empty' => 'Brak zarejestrowanych spraw.',
                'link' => 'Zobacz sprawę',
                'meta_template' => 'Typ: :type · Status: :status · :updated_at',
            ],
            'notes' => [
                'title' => 'Najnowsze notatki',
                'subtitle' => 'Ostatnie interakcje z klientami i dziennik serwisowy.',
                'empty' => 'Nie dodano jeszcze notatek.',
                'case_label' => 'Sprawa',
                'customer_label' => 'Klient',
            ],
        ],
        'panels' => [
            'grid_aria' => 'Szczegóły operacyjne',
            'pickups' => [
                'title' => 'Otwarte potwierdzenia odbioru',
                'subtitle' => 'Bieżąca lista klientów gotowych do odbioru',
                'view_all' => 'Zobacz wszystkie',
                'headers' => [
                    'customer' => 'Klient',
                    'code' => 'Kod',
                    'ready_date' => 'Data gotowości',
                    'contact' => 'Kontakt',
                ],
                'empty' => 'Brak oczekujących potwierdzeń.',
                'phone' => 'Tel',
                'email' => 'E-mail',
                'status' => 'Status',
            ],
            'case_summary' => [
                'title' => 'Podział spraw',
                'subtitle' => 'Podgląd według typu i statusu',
                'empty' => 'Nie utworzono jeszcze spraw.',
            ],
            'trend' => [
                'title' => 'Aktywność ostatnie :days dni',
                'subtitle' => 'Liczba aktualizacji dziennie',
                'empty' => 'Brak aktywności w tym okresie.',
            ],
            'notifications' => [
                'title' => 'Wysłane powiadomienia',
                'subtitle' => 'Skuteczność kanałów i follow-up',
                'empty' => 'Brak wysłanych powiadomień w tym okresie.',
                'footer' => 'Zakończone sprawy: :count',
            ],
        ],
        'modals' => [
            'cases' => [
                'title' => 'Szczegóły przebiegu spraw',
                'summary' => 'W ostatnich :days dniach utworzono :created spraw, z czego :completed zakończono.',
                'completion' => 'Współczynnik zakończeń: :rate%.',
                'empty' => 'Nie utworzono jeszcze spraw.',
                'tip' => 'Wskazówka: filtruj po typie, aby szybciej analizować usługi.',
            ],
            'warehouse' => [
                'title' => 'Szczegóły magazynu',
                'summary' => 'Magazyn zawiera :total rekordów, z czego :ready gotowe do wydania i :reserved zarezerwowane.',
                'available' => 'Dostępne',
                'ready' => 'Gotowe',
                'reserved' => 'Zarezerwowane',
                'tip' => 'Planuj wydania z tego widoku i koordynuj z zespołem serwisowym.',
            ],
            'notifications' => [
                'title' => 'Kanały powiadomień',
                'summary' => 'W wybranym okresie wysłano :total powiadomień do klientów.',
                'empty' => 'W tym okresie nie wysłano jeszcze powiadomień.',
                'open' => 'Otwarte powiadomienia: :pending.',
                'tip' => 'Automatyzuj powiadomienia lub zaplanuj ręczne działania.',
            ],
        ],
        'common' => [
            'unknown' => 'Nieznane',
        ],
        'case_types' => [
            'pickup' => 'Odbiór',
            'delivery' => 'Dostawa',
            'repair' => 'Naprawa',
            'diagnostics' => 'Diagnostyka',
        ],
        'case_status' => [
            'klaar' => 'Gotowa',
            'open' => 'Otwarta',
            'in_behandeling' => 'W realizacji',
            'gesloten' => 'Zamknięta',
            'opgehaald' => 'Odebrana',
        ],
        'warehouse' => [
            'status' => [
                'ready' => 'Gotowe',
                'reserved' => 'Zarezerwowane',
                'processing' => 'Przetwarzanie',
                'waiting' => 'Oczekujące',
            ],
        ],
        'notification_channels' => [
            'sms' => 'SMS',
            'email' => 'E-mail',
            'phone' => 'Telefon',
            'whatsapp' => 'WhatsApp',
        ],
        'footer' => [
            'copyright' => '© :year :app. Wszelkie prawa zastrzeżone.',
        ],
    ],
    'common' => [
        'close' => 'Zamknij',
    ],
];