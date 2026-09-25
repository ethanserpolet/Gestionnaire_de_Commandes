<?php
declare(strict_types=1);

namespace App\Support;

use App\Auth\Session;

final class View
{
    public static function render(string $view, array $data = []): void
    {
        try {
            $data['currentUser'] = Session::currentUser();
        } catch (\Throwable $e) {
            // Keeps error pages renderable when the database is unreachable.
            $data['currentUser'] = null;
        }
        extract($data, EXTR_SKIP);

        $viewFile = dirname(__DIR__, 2) . "/views/$view.php";
        if (!is_file($viewFile)) {
            throw new \RuntimeException("Vue introuvable : $view");
        }

        ob_start();
        try {
            require $viewFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $content = ob_get_clean();

        require dirname(__DIR__, 2) . '/views/layout.php';
    }

    /** Renders a standalone page (no app layout), e.g. printable documents. */
    public static function renderBare(string $view, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require dirname(__DIR__, 2) . "/views/$view.php";
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function money(float|int|string|null $value): string
    {
        return number_format((float) $value, 2, ',', "\u{202F}") . "\u{00A0}€";
    }

    public static function bytes(int|string|null $bytes): string
    {
        $b = (int) $bytes;
        if ($b < 1024) {
            return $b . ' o';
        }
        if ($b < 1048576) {
            return round($b / 1024) . ' Ko';
        }
        return number_format($b / 1048576, 1, ',', '') . ' Mo';
    }

    // --- Statuts ---------------------------------------------------------

    private const STATUT_LABELS = [
        'brouillon' => 'Brouillon',
        'en_attente' => 'En attente',
        'en_validation' => 'En validation',
        'valide' => 'Validé',
        'finalise' => 'Finalisé',
        'refuse' => 'Refusé',
    ];

    public static function statutLabel(string $statut): string
    {
        return self::STATUT_LABELS[$statut] ?? $statut;
    }

    public static function badge(string $statut, string $extraClass = ''): string
    {
        $class = 'status status-' . self::e($statut) . ($extraClass !== '' ? ' ' . $extraClass : '');
        return '<span class="' . $class . '">' . self::e(self::statutLabel($statut)) . '</span>';
    }

    // --- Dates -----------------------------------------------------------

    private const MOIS_COURTS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    private const MOIS_LONGS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    public static function date(?string $value, bool $withTime = false): string
    {
        if (!$value) {
            return '—';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return '—';
        }
        $out = (int) date('j', $ts) . ' ' . self::MOIS_COURTS[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
        return $withTime ? $out . ' à ' . date('H:i', $ts) : $out;
    }

    public static function todayLong(): string
    {
        $ts = time();
        return self::JOURS[(int) date('w', $ts)] . ' ' . (int) date('j', $ts) . ' '
            . self::MOIS_LONGS[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }

    // --- Avatars ---------------------------------------------------------

    public static function initials(?string $name): string
    {
        $initials = '';
        foreach (preg_split('/[\s.\-]+/u', trim((string) $name)) ?: [] as $part) {
            if ($part !== '') {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }
        return mb_substr($initials, 0, 2) ?: '?';
    }

    public static function avatar(?string $name, string $size = ''): string
    {
        $name = (string) $name;
        $hue = abs(crc32($name)) % 360;
        $style = sprintf(
            'background:linear-gradient(135deg,hsl(%d 72%% 62%%),hsl(%d 70%% 50%%))',
            $hue,
            ($hue + 38) % 360
        );
        $class = 'avatar' . ($size !== '' ? ' avatar-' . $size : '');
        return '<span class="' . $class . '" style="' . $style . '" title="' . self::e($name) . '" aria-hidden="true">'
            . self::e(self::initials($name)) . '</span>';
    }

    // --- Icônes (tracés inspirés de Lucide, licence ISC) -----------------

    public static function icon(string $name, int $size = 18): string
    {
        $body = self::ICONS[$name] ?? self::ICONS['circle'];
        return '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . $body . '</svg>';
    }

    private const ICONS = [
        'circle' => '<circle cx="12" cy="12" r="10"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'x-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'package' => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'eye' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'store' => '<path d="M3 9 4.5 4h15L21 9"/><path d="M4 9v11h16V9"/><path d="M3 9h18"/><path d="M10 20v-5h4v5"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'menu' => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'euro' => '<path d="M4 10h12"/><path d="M4 14h9"/><path d="M19 6a7.7 7.7 0 0 0-5.2-2A7.9 7.9 0 0 0 6 12c0 4.4 3.5 8 7.8 8 2 0 3.8-.8 5.2-2"/>',
        'building' =>'<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01"/><path d="M16 6h.01"/><path d="M12 6h.01"/><path d="M12 10h.01"/><path d="M12 14h.01"/><path d="M16 10h.01"/><path d="M16 14h.01"/><path d="M8 10h.01"/><path d="M8 14h.01"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
        'paperclip' => '<path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
        'trash' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'send' => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'alert' => '<circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'edit' => '<path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
        'map-pin' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'tag' => '<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.42l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
        'power' => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'receipt' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/>',
    ];
}
