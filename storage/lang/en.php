<?php

return [
    'app' => [
        'name' => 'Digivriend',
    ],
    'nav' => [
        'dashboard' => 'Dashboard',
        'intake' => 'Customer intake',
        'devices' => 'Customers & devices',
        'archive' => 'Archive',
        'data_recovery' => 'Data recovery',
        'calendar' => 'Calendar',
        'documents' => 'Documents',
        'inventory' => 'Warehouse',
        'pc_builder' => 'PC build',
        'employees' => 'Employees',
        'logout' => 'Log out',
        'dump_database' => 'Database export',
        'aria' => [
            'main' => 'Main navigation',
        ],
    ],
    'language' => [
        'switcher' => [
            'label' => 'Interface language',
        ],
        'options' => [
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'en' => 'English',
        ],
    ],
    'auth' => [
        'login' => [
            'title' => 'Sign in - Digivriend',
            'heading' => 'Sign in',
            'username' => 'Username',
            'password' => 'Password',
            'button' => 'Sign in',
            'footer' => 'Use the administrator credentials from the .env file to gain access.',
            'error' => [
                'invalid_session' => 'Invalid session. Refresh the page and try again.',
                'invalid_credentials' => 'Incorrect username and password combination.',
            ],
        ],
    ],
    'messages' => [
        'session_expired' => 'Session expired. Refresh the page and try again.',
    ],
    'employees' => [
        'meta' => [
            'title' => 'Employees - Digivriend',
        ],
        'hero' => [
            'heading' => 'Digivriend team',
            'description' => 'Build schedules and grow team skills in one place. Profiles, availability, and workload are at your fingertips.',
            'quick_actions' => [
                'group_label' => 'Team quick actions',
                'add' => 'Add employee',
                'manage' => 'Manage profile',
            ],
        ],
        'filters' => [
            'aria' => 'Status filter',
            'label' => 'Status',
            'options' => [
                'active' => 'Active',
                'inactive' => 'Inactive',
                'all' => 'All',
            ],
        ],
        'stats' => [
            'aria' => 'Team summary',
            'total_label' => 'Employees',
            'view_meta' => 'in the ":view" view',
            'absences_label' => 'Planned absences',
            'absences_meta_team' => 'for the team',
            'absences_meta_employee' => 'for :name',
            'upcoming_label' => 'Upcoming actions',
            'upcoming_meta' => 'appointments and assignments to deliver',
        ],
        'permissions' => [
            'cases_assign' => 'Assign cases',
            'cases_approve' => 'Quality control approval',
            'calendar_manage' => 'Manage calendar',
            'calendar_self' => 'Edit own appointments',
            'inventory_manage' => 'Manage warehouse',
            'documents_publish' => 'Publish and version documents',
            'employees_manage' => 'Manage employees',
        ],
        'messages' => [
            'employee_created' => 'New employee added.',
            'employee_updated' => 'Employee data updated.',
            'status_changed' => 'Employee status updated.',
            'availability_added' => 'Availability entry added.',
        ],
        'errors' => [
            'invalid_employee_id' => 'Invalid employee identifier.',
            'unsupported_status' => 'Unsupported employee status.',
            'select_employee' => 'Select an employee.',
            'invalid_date_range' => 'Provide a valid date range.',
            'end_before_start' => 'End date must be later than start date.',
            'unsupported_language' => 'Unsupported language selection.',
        ],
        'panels' => [
            'list' => [
                'aria' => 'Employee list',
                'title' => 'Digivriend staff',
                'description' => 'Select a profile to view details and activity history.',
                'empty' => 'No employees to display.',
                'add' => 'Add to team',
                'unknown_name' => 'Unknown',
                'default_role' => 'Specialist',
            ],
            'profile' => [
                'aria' => 'Profile summary',
                'title' => 'Employee profile',
                'description' => 'Key personal and contact information.',
                'empty' => 'Select an employee from the list to view details.',
                'status_active' => 'Active',
                'status_inactive' => 'Inactive',
                'role' => 'Role:',
                'department' => 'Department:',
                'email' => 'Email:',
                'phone' => 'Phone:',
                'details' => 'Full details',
            ],
            'tasks' => [
                'aria' => 'Upcoming tasks overview',
                'title' => 'Upcoming tasks',
                'description' => 'Monitor assigned cases and visits.',
                'empty' => 'No scheduled actions for the selected scope.',
                'case_prefix' => 'Case #',
                'role' => 'Role:',
                'status' => 'Status:',
                'default_visit_title' => 'Visit',
                'manage' => 'Manage tasks',
            ],
            'availability' => [
                'title' => 'Availability',
                'empty' => 'No planned absences.',
                'table' => [
                    'type' => 'Type',
                    'range' => 'Range',
                    'reason' => 'Reason',
                    'none' => '—',
                ],
            ],
            'assignments' => [
                'title' => 'Assigned cases',
                'empty' => 'No active assignments.',
                'table' => [
                    'case' => 'Case',
                    'type' => 'Type',
                    'status' => 'Status',
                    'role' => 'Role',
                    'assigned_at' => 'Assigned',
                ],
            ],
            'calendar' => [
                'title' => 'Calendar visits',
                'empty' => 'No scheduled visits.',
                'table' => [
                    'time' => 'Time',
                    'title' => 'Title',
                    'status' => 'Status',
                    'case' => 'Case',
                    'none' => '—',
                ],
            ],
        ],
        'footer' => [
            'copyright' => '© :year Digivriend. All rights reserved.',
        ],
        'modals' => [
            'create' => [
                'title' => 'Add employee',
                'heading' => 'New employee',
            ],
            'manage' => [
                'title' => 'Profile and availability',
                'empty' => 'Select an employee to edit the profile.',
                'heading' => 'Employee profile',
            ],
        ],
        'forms' => [
            'profile' => [
                'full_name' => 'Full name',
                'email' => 'Email address',
                'phone' => 'Phone',
                'role' => 'System role',
                'department' => 'Department',
                'position' => 'Position',
                'color' => 'Calendar color',
                'timezone' => 'Timezone',
                'timezone_placeholder' => 'Europe/Warsaw',
                'hired_at' => 'Hire date',
                'terminated_at' => 'End date',
                'language' => 'Interface language',
                'permissions' => 'Permissions',
                'submit_create' => 'Add employee',
                'submit_update' => 'Save changes',
            ],
            'status' => [
                'deactivate' => 'Deactivate employee',
                'activate' => 'Activate employee',
            ],
            'availability' => [
                'type' => 'Entry type',
                'type_options' => [
                    'leave' => 'Leave',
                    'training' => 'Training',
                    'remote' => 'Remote work',
                    'unavailable' => 'Unavailable',
                ],
                'start' => 'Start',
                'end' => 'End',
                'reason' => 'Reason',
                'submit' => 'Add entry',
            ],
        ],
    ],
    'dashboard' => [
        'meta' => [
            'title' => 'Digivriend - Dashboard',
        ],
        'header' => [
            'logo_aria' => 'Digivriend dashboard',
            'subtitle' => 'Service platform',
        ],
        'hero' => [
            'badge' => 'Real-time overview',
            'title' => 'Digivriend operations dashboard',
            'summary' => ':open_cases active cases and :pending_notifications notifications waiting for follow-up. Keep warehouse operations and customer communication in real time.',
            'metrics' => [
                'active_cases' => [
                    'label' => 'Active cases',
                    'hint_waiting' => ':days days waiting time',
                    'hint_clear' => 'Immediate follow-up',
                ],
                'today_pickups' => [
                    'label' => 'Pickups today',
                    'hint_any' => 'Plan handover and communication',
                    'hint_none' => 'No pickups scheduled',
                ],
                'notifications' => [
                    'label' => 'Open notifications',
                    'hint_any' => 'Customers still to notify',
                    'hint_none' => 'All customers informed',
                ],
            ],
            'meta' => [
                'last_update' => [
                    'label' => 'Last update',
                ],
                'period' => [
                    'label' => 'Period',
                ],
                'period_value' => 'Last :days days',
                'filter' => [
                    'label' => 'Active filter',
                ],
                'last_activity' => [
                    'label' => 'Last activity',
                ],
                'last_activity_none' => 'No activity yet',
                'filter_all' => 'All cases',
            ],
            'actions' => [
                'pickup' => 'New pickup confirmation',
                'customer_notification' => 'New customer notification',
                'network_check' => 'Network check letter',
            ],
        ],
        'filters' => [
            'aria' => 'Dashboard filters',
            'case_type' => [
                'label' => 'Case type',
                'all' => 'All types',
            ],
            'period' => [
                'label' => 'Period',
                'option' => 'Last :days days',
            ],
            'submit' => 'Apply filter',
        ],
        'highlights' => [
            'aria' => 'Key KPIs',
            'customers' => [
                'title' => 'Customer base',
                'subtitle' => 'Unique profiles under management',
                'hint' => ':cases active cases linked',
            ],
            'cases' => [
                'title' => 'Case pipeline',
                'subtitle' => 'Workload in the selected period',
                'hint' => ':completed completed',
                'progress' => ':rate% of cases completed',
                'action' => 'View insights',
            ],
            'warehouse' => [
                'title' => 'Warehouse status',
                'subtitle' => 'Availability and reservations',
                'hint' => ':available available · :ready ready',
                'progress_ready' => ':rate% ready for pickup',
                'action' => 'Open warehouse overview',
            ],
            'notifications' => [
                'title' => 'Communication',
                'subtitle' => 'Notifications sent',
                'hint' => ':pending notifications still pending',
                'progress' => ':rate% processed',
                'action' => 'View channels',
            ],
            'lead_time' => [
                'title' => 'Avg. lead time',
                'subtitle' => 'From ready to pickup',
                'value' => ':days days',
                'hint' => 'Focus on swift follow-up of ready notifications.',
            ],
            'wait_time' => [
                'title' => 'Longest wait',
                'subtitle' => 'How long is the oldest case waiting?',
                'value' => ':days days',
                'empty' => 'No waiting queue',
                'hint' => 'Monitor for escalation and extra follow-up.',
            ],
        ],
        'activity' => [
            'aria' => 'Team activity and communication',
            'cases' => [
                'title' => 'Activity in the last :days days',
                'subtitle' => 'Insights into case updates during the selected period.',
                'range' => ':start – :end',
                'stats' => [
                    'updates' => 'Case updates',
                    'completed' => 'Completed',
                    'success' => 'Success rate',
                ],
                'footer' => [
                    'label' => 'Last update',
                ],
            ],
            'notifications' => [
                'title' => 'Notifications sent',
                'subtitle' => 'Channels that recently reached customers.',
                'total' => 'Total :total',
                'empty' => 'No notifications sent in this period yet.',
                'footer' => [
                    'open' => 'Open notifications',
                ],
            ],
            'recent_cases' => [
                'title' => 'Latest cases',
                'subtitle' => 'Recently updated records for quick follow-up.',
                'empty' => 'No cases registered yet.',
                'link' => 'View case',
                'meta_template' => 'Type: :type · Status: :status · :updated_at',
            ],
            'notes' => [
                'title' => 'Recent notes',
                'subtitle' => 'Latest customer interactions and service log.',
                'empty' => 'No notes added yet.',
                'case_label' => 'Case',
                'customer_label' => 'Customer',
            ],
        ],
        'panels' => [
            'grid_aria' => 'Operational details',
            'pickups' => [
                'title' => 'Pending pickup confirmations',
                'subtitle' => 'Real-time overview of customers ready for pickup',
                'view_all' => 'View all',
                'headers' => [
                    'customer' => 'Customer',
                    'code' => 'Code',
                    'ready_date' => 'Ready date',
                    'contact' => 'Contact',
                ],
                'empty' => 'No pending confirmations.',
                'phone' => 'Phone',
                'email' => 'Email',
                'status' => 'Status',
            ],
            'case_summary' => [
                'title' => 'Case distribution',
                'subtitle' => 'Insight per type and status',
                'empty' => 'No cases created yet.',
            ],
            'trend' => [
                'title' => 'Activity in the last :days days',
                'subtitle' => 'Number of case updates per day',
                'empty' => 'No case activity in this period.',
            ],
            'notifications' => [
                'title' => 'Notifications sent',
                'subtitle' => 'Channel performance and follow-up',
                'empty' => 'No notifications sent in this period yet.',
                'footer' => 'Completed cases: :count',
            ],
        ],
        'modals' => [
            'cases' => [
                'title' => 'Case insight details',
                'summary' => 'In the last :days days, :created cases were created and :completed were completed.',
                'completion' => 'Completion rate: :rate%.',
                'empty' => 'No cases created yet.',
                'tip' => 'Tip: filter by type to analyse specific services faster.',
            ],
            'warehouse' => [
                'title' => 'Warehouse insight',
                'summary' => 'The warehouse contains :total records with :ready ready for pickup and :reserved reserved.',
                'available' => 'Available',
                'ready' => 'Ready',
                'reserved' => 'Reserved',
                'tip' => 'Plan handovers from this overview and coordinate with the service team.',
            ],
            'notifications' => [
                'title' => 'Notification channels',
                'summary' => 'In the selected period :total notifications were sent to customers.',
                'empty' => 'No notifications sent in this period yet.',
                'open' => 'Open notifications: :pending.',
                'tip' => 'Automate follow-ups or schedule manual actions right away.',
            ],
        ],
        'common' => [
            'unknown' => 'Unknown',
        ],
        'case_types' => [
            'pickup' => 'Pickup',
            'delivery' => 'Delivery',
            'repair' => 'Repair',
            'diagnostics' => 'Diagnostics',
        ],
        'case_status' => [
            'klaar' => 'Ready',
            'open' => 'Open',
            'in_behandeling' => 'In progress',
            'gesloten' => 'Closed',
            'opgehaald' => 'Collected',
        ],
        'warehouse' => [
            'status' => [
                'ready' => 'Ready',
                'reserved' => 'Reserved',
                'processing' => 'Processing',
                'waiting' => 'Waiting',
            ],
        ],
        'notification_channels' => [
            'sms' => 'SMS',
            'email' => 'Email',
            'phone' => 'Phone',
            'whatsapp' => 'WhatsApp',
        ],
        'footer' => [
            'copyright' => '© :year :app. All rights reserved.',
        ],
    ],
    'dashboard' => [
        'meta' => [
            'title' => 'Digivriend - Dashboard',
        ],
        'header' => [
            'logo_aria' => 'Digivriend dashboard',
            'subtitle' => 'Service platform',
        ],
        'hero' => [
            'badge' => 'Real-time overview',
            'title' => 'Digivriend operations dashboard',
            'summary' => ':open_cases active cases and :pending_notifications notifications waiting for follow-up. Keep warehouse operations and customer communication in real time.',
            'metrics' => [
                'active_cases' => [
                    'label' => 'Active cases',
                    'hint_waiting' => ':days days waiting time',
                    'hint_clear' => 'Immediate follow-up',
                ],
                'today_pickups' => [
                    'label' => 'Pickups today',
                    'hint_any' => 'Plan handover and communication',
                    'hint_none' => 'No pickups scheduled',
                ],
                'notifications' => [
                    'label' => 'Open notifications',
                    'hint_any' => 'Customers still to notify',
                    'hint_none' => 'All customers informed',
                ],
            ],
            'meta' => [
                'last_update' => [
                    'label' => 'Last update',
                ],
                'period' => [
                    'label' => 'Period',
                ],
                'period_value' => 'Last :days days',
                'filter' => [
                    'label' => 'Active filter',
                ],
                'last_activity' => [
                    'label' => 'Last activity',
                ],
                'last_activity_none' => 'No activity yet',
                'filter_all' => 'All cases',
            ],
            'actions' => [
                'pickup' => 'New pickup confirmation',
                'customer_notification' => 'New customer notification',
                'network_check' => 'Network check letter',
            ],
        ],
        'filters' => [
            'aria' => 'Dashboard filters',
            'case_type' => [
                'label' => 'Case type',
                'all' => 'All types',
            ],
            'period' => [
                'label' => 'Period',
                'option' => 'Last :days days',
            ],
            'submit' => 'Apply filter',
        ],
        'highlights' => [
            'aria' => 'Key KPIs',
            'customers' => [
                'title' => 'Customer base',
                'subtitle' => 'Unique profiles under management',
                'hint' => ':cases active cases linked',
            ],
            'cases' => [
                'title' => 'Case pipeline',
                'subtitle' => 'Workload in the selected period',
                'hint' => ':completed completed',
                'progress' => ':rate% of cases completed',
                'action' => 'View insights',
            ],
            'warehouse' => [
                'title' => 'Warehouse status',
                'subtitle' => 'Availability and reservations',
                'hint' => ':available available · :ready ready',
                'progress_ready' => ':rate% ready for pickup',
                'action' => 'Open warehouse overview',
            ],
            'notifications' => [
                'title' => 'Communication',
                'subtitle' => 'Notifications sent',
                'hint' => ':pending notifications still pending',
                'progress' => ':rate% processed',
                'action' => 'View channels',
            ],
            'lead_time' => [
                'title' => 'Avg. lead time',
                'subtitle' => 'From ready to pickup',
                'value' => ':days days',
                'hint' => 'Focus on swift follow-up of ready notifications.',
            ],
            'wait_time' => [
                'title' => 'Longest wait',
                'subtitle' => 'How long is the oldest case waiting?',
                'value' => ':days days',
                'empty' => 'No waiting queue',
                'hint' => 'Monitor for escalation and extra follow-up.',
            ],
        ],
        'activity' => [
            'aria' => 'Team activity and communication',
            'cases' => [
                'title' => 'Activity in the last :days days',
                'subtitle' => 'Insights into case updates during the selected period.',
                'range' => ':start – :end',
                'stats' => [
                    'updates' => 'Case updates',
                    'completed' => 'Completed',
                    'success' => 'Success rate',
                ],
                'footer' => [
                    'label' => 'Last update',
                ],
            ],
            'notifications' => [
                'title' => 'Notifications sent',
                'subtitle' => 'Channels that recently reached customers.',
                'total' => 'Total :total',
                'empty' => 'No notifications sent in this period yet.',
                'footer' => [
                    'open' => 'Open notifications',
                ],
            ],
            'recent_cases' => [
                'title' => 'Latest cases',
                'subtitle' => 'Recently updated records for quick follow-up.',
                'empty' => 'No cases registered yet.',
                'link' => 'View case',
                'meta_template' => 'Type: :type · Status: :status · :updated_at',
            ],
            'notes' => [
                'title' => 'Recent notes',
                'subtitle' => 'Latest customer interactions and service log.',
                'empty' => 'No notes added yet.',
                'case_label' => 'Case',
                'customer_label' => 'Customer',
            ],
        ],
        'panels' => [
            'grid_aria' => 'Operational details',
            'pickups' => [
                'title' => 'Pending pickup confirmations',
                'subtitle' => 'Real-time overview of customers ready for pickup',
                'view_all' => 'View all',
                'headers' => [
                    'customer' => 'Customer',
                    'code' => 'Code',
                    'ready_date' => 'Ready date',
                    'contact' => 'Contact',
                ],
                'empty' => 'No pending confirmations.',
                'phone' => 'Phone',
                'email' => 'Email',
                'status' => 'Status',
            ],
            'case_summary' => [
                'title' => 'Case distribution',
                'subtitle' => 'Insight per type and status',
                'empty' => 'No cases created yet.',
            ],
            'trend' => [
                'title' => 'Activity in the last :days days',
                'subtitle' => 'Number of case updates per day',
                'empty' => 'No case activity in this period.',
            ],
            'notifications' => [
                'title' => 'Notifications sent',
                'subtitle' => 'Channel performance and follow-up',
                'empty' => 'No notifications sent in this period yet.',
                'footer' => 'Completed cases: :count',
            ],
        ],
        'modals' => [
            'cases' => [
                'title' => 'Case insight details',
                'summary' => 'In the last :days days, :created cases were created and :completed were completed.',
                'completion' => 'Completion rate: :rate%.',
                'empty' => 'No cases created yet.',
                'tip' => 'Tip: filter by type to analyse specific services faster.',
            ],
            'warehouse' => [
                'title' => 'Warehouse insight',
                'summary' => 'The warehouse contains :total records with :ready ready for pickup and :reserved reserved.',
                'available' => 'Available',
                'ready' => 'Ready',
                'reserved' => 'Reserved',
                'tip' => 'Plan handovers from this overview and coordinate with the service team.',
            ],
            'notifications' => [
                'title' => 'Notification channels',
                'summary' => 'In the selected period :total notifications were sent to customers.',
                'empty' => 'No notifications sent in this period yet.',
                'open' => 'Open notifications: :pending.',
                'tip' => 'Automate follow-ups or schedule manual actions right away.',
            ],
        ],
        'common' => [
            'unknown' => 'Unknown',
        ],
        'case_types' => [
            'pickup' => 'Pickup',
            'delivery' => 'Delivery',
            'repair' => 'Repair',
            'diagnostics' => 'Diagnostics',
        ],
        'case_status' => [
            'klaar' => 'Ready',
            'open' => 'Open',
            'in_behandeling' => 'In progress',
            'gesloten' => 'Closed',
            'opgehaald' => 'Collected',
        ],
        'warehouse' => [
            'status' => [
                'ready' => 'Ready',
                'reserved' => 'Reserved',
                'processing' => 'Processing',
                'waiting' => 'Waiting',
            ],
        ],
        'notification_channels' => [
            'sms' => 'SMS',
            'email' => 'Email',
            'phone' => 'Phone',
            'whatsapp' => 'WhatsApp',
        ],
        'footer' => [
            'copyright' => '© :year :app. All rights reserved.',
        ],
    ],
    'common' => [
        'close' => 'Close',
    ],
];