<?php
/**
 * Tab icon for the current portal page.
 * Filled tile so the mark stays visible on a dark browser tab.
 */
if (!function_exists('portalFaviconMarkup')) {
    function portalFaviconMarkup(): string
    {
        $script = strtolower(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));

        $color = '#0f766e';
        $icon = 'grid';
        $rules = [
            ['#/(atc|admin|dlc)/index\.php$#', '#0f766e', 'grid'],
            ['#/index\.php$#', '#1d4ed8', 'login'],
            ['#student_marks|typing_marks|exam_|hall_tickets|attendance#', '#d97706', 'calendar'],
            ['#certif|completion|marksheet#', '#e11d48', 'award'],
            ['#inquir|new_admission|re_admission|convert_admission|admission_form#', '#2563eb', 'userplus'],
            ['#edit_student|view_admission|/students\.php#', '#0891b2', 'users'],
            ['#fee|course|pay_share|expense|share_|cash_share|bank_detail|earning#', '#059669', 'card'],
            ['#dispatch|inventory|uniform|material#', '#ea580c', 'box'],
            ['#document|download#', '#0369a1', 'file'],
            ['#notif|announce|banner#', '#db2777', 'bell'],
            ['#scheme#', '#ca8a04', 'gift'],
            ['#analytic|report#', '#0284c7', 'chart'],
            ['#profile#', '#475569', 'user'],
            ['#/users|user_form|atc_|dlc_|enquir#', '#0f766e', 'building'],
            ['#training|watch\.php#', '#4d7c0f', 'play'],
        ];
        foreach ($rules as [$pattern, $ruleColor, $ruleIcon]) {
            if (preg_match($pattern, $script)) {
                $color = $ruleColor;
                $icon = $ruleIcon;
                break;
            }
        }

        $paths = [
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'login' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
            'userplus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
            'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'card' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            'award' => '<circle cx="12" cy="8" r="6"/><path d="M15.5 13.5 17 22l-5-3-5 3 1.5-8.5"/>',
            'box' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
            'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
            'bell' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M12 8s-1-5-4.5-5a2.5 2.5 0 0 0 0 5M12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
            'chart' => '<path d="M3 3v18h18"/><path d="M7 16v-4M12 16V8M17 16v-6"/>',
            'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'building' => '<path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/><path d="M9 7h.01M9 11h.01M9 15h.01M15 7h.01M15 11h.01M15 15h.01"/>',
            'play' => '<circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4V8z"/>',
        ];
        $inner = $paths[$icon] ?? $paths['grid'];
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
            . '<rect width="32" height="32" rx="8" fill="' . $color . '"/>'
            . '<g fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" transform="translate(4 4)">'
            . $inner
            . '</g></svg>';

        return '<link rel="icon" href="data:image/svg+xml,' . rawurlencode($svg) . '">';
    }
}

echo portalFaviconMarkup();
