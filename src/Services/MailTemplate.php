<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Roles;
use App\Support\View;

/** Table-based HTML e-mails with inline styles (compatible Outlook / Gmail / mobile). */
final class MailTemplate
{
    private const TONES = [
        'primary' => ['#6366f1', '#eef0ff'],
        'success' => ['#16a34a', '#e7f7ee'],
        'danger' => ['#dc2626', '#fdecec'],
        'warning' => ['#d97706', '#fdf3e4'],
        'teal' => ['#0d9488', '#e4f6f4'],
    ];

    private const FONT = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";

    /**
     * @param array{
     *   tone?:string, eyebrow:string, title:string, intro:string, preheader?:string,
     *   commande:array, note?:array{label:string,text:string}, cta?:array{label:string,url:string},
     *   progress?:bool, sender?:string
     * } $o  `intro` is trusted HTML built by the caller (escape user data with View::e).
     */
    public static function render(array $o): string
    {
        [$accent, $soft] = self::TONES[$o['tone'] ?? 'primary'] ?? self::TONES['primary'];
        $f = self::FONT;
        $c = $o['commande'];

        $html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . View::e($o['title']) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#eef1f7;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">'
            . View::e($o['preheader'] ?? html_entity_decode(strip_tags(str_replace('<br>', ' ', $o['intro'])), ENT_QUOTES, 'UTF-8')) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef1f7;">'
            . '<tr><td align="center" style="padding:32px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">';

        // Brand line
        $html .= '<tr><td style="padding:0 6px 16px;' . $f . 'font-size:13px;color:#64748b;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="width:30px;height:30px;border-radius:9px;background:#6366f1;color:#ffffff;text-align:center;'
            . $f . 'font-size:15px;font-weight:800;line-height:30px;">C</td>'
            . '<td style="padding-left:10px;' . $f . 'font-size:14px;color:#0f172a;"><strong>Commandes</strong>'
            . '<span style="color:#64748b;"> · Lycée St Marc</span></td>'
            . '</tr></table></td></tr>';

        // Card
        $html .= '<tr><td style="background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #e3e8f0;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="height:6px;line-height:6px;font-size:0;background:' . $accent . ';">&nbsp;</td></tr>'
            . '<tr><td style="padding:30px 32px 6px;' . $f . '">'
            . '<span style="display:inline-block;padding:5px 12px;border-radius:999px;background:' . $soft . ';color:' . $accent . ';'
            . 'font-size:12px;font-weight:700;letter-spacing:.02em;">' . View::e($o['eyebrow']) . '</span>'
            . '<h1 style="margin:16px 0 10px;font-size:22px;line-height:1.3;color:#0f172a;font-weight:800;">' . View::e($o['title']) . '</h1>'
            . '<p style="margin:0;font-size:15px;line-height:1.65;color:#475569;">' . $o['intro'] . '</p>'
            . '</td></tr>';

        if (!empty($o['note'])) {
            $html .= '<tr><td style="padding:18px 32px 0;' . $f . '">'
                . '<div style="border-left:4px solid ' . $accent . ';background:' . $soft . ';border-radius:0 12px 12px 0;padding:14px 16px;">'
                . '<div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:' . $accent . ';margin-bottom:4px;">'
                . View::e($o['note']['label']) . '</div>'
                . '<div style="font-size:14px;line-height:1.6;color:#0f172a;">' . nl2br(View::e($o['note']['text'])) . '</div>'
                . '</div></td></tr>';
        }

        $html .= '<tr><td style="padding:22px 32px 0;">' . self::summary($c) . '</td></tr>';

        if (!empty($o['progress']) && !empty($c['validations'])) {
            $html .= '<tr><td style="padding:22px 32px 0;">' . self::progress($c['validations']) . '</td></tr>';
        }

        if (!empty($o['cta'])) {
            $url = View::e($o['cta']['url']);
            $html .= '<tr><td style="padding:26px 32px 8px;' . $f . '">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td style="border-radius:12px;background:' . $accent . ';">'
                . '<a href="' . $url . '" style="display:inline-block;padding:14px 26px;' . $f . 'font-size:15px;font-weight:700;'
                . 'color:#ffffff;text-decoration:none;border-radius:12px;">' . View::e($o['cta']['label']) . ' &rarr;</a>'
                . '</td></tr></table>'
                . '<p style="margin:14px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;">Le bouton ne fonctionne pas ? Copiez ce lien :<br>'
                . '<a href="' . $url . '" style="color:#6366f1;word-break:break-all;">' . $url . '</a></p>'
                . '</td></tr>';
        }

        $html .= '<tr><td style="padding:24px 32px 28px;"></td></tr></table></td></tr>';

        // Footer
        $html .= '<tr><td style="padding:18px 12px 0;' . $f . 'font-size:12px;line-height:1.6;color:#94a3b8;text-align:center;">'
            . 'E-mail envoyé automatiquement par l’application Commandes du Lycée St Marc'
            . (!empty($o['sender']) ? '<br>à la suite d’une action de ' . View::e($o['sender']) . '.' : '.')
            . '</td></tr>';

        return $html . '</table></td></tr></table></body></html>';
    }

    public static function hello(?string $displayName): string
    {
        $prenom = explode(' ', trim((string) $displayName))[0] ?? '';
        return 'Bonjour' . ($prenom !== '' ? ' ' . View::e($prenom) : '') . ',<br>';
    }

    private static function summary(array $c): string
    {
        $f = self::FONT;
        $lignes = $c['lignes'] ?? [];
        $total = array_sum(array_map(static fn($l) => (float) $l['prix_ttc'], $lignes));
        $fournisseurs = array_values(array_unique(array_map(static fn($l) => (string) $l['fournisseur'], $lignes)));
        $nb = count($lignes);

        $rows = [
            ['N° de commande', $c['numero_commande'] ?: 'Brouillon #' . $c['id']],
            ['Demandeur', $c['demandeur_nom'] ?? '—'],
            ['Service', $c['service_nom'] ?? '—'],
            ['Articles', $nb . ' article' . ($nb > 1 ? 's' : '') . ($fournisseurs ? ' · ' . implode(', ', $fournisseurs) : '')],
        ];

        $out = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="background:#f8fafc;border:1px solid #e8ecf3;border-radius:14px;">'
            . '<tr><td style="padding:6px 18px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
        foreach ($rows as [$label, $value]) {
            $out .= '<tr>'
                . '<td style="padding:10px 0;border-bottom:1px solid #e8ecf3;' . $f . 'font-size:13px;color:#64748b;">' . View::e($label) . '</td>'
                . '<td align="right" style="padding:10px 0 10px 12px;border-bottom:1px solid #e8ecf3;' . $f . 'font-size:14px;font-weight:600;color:#0f172a;">'
                . View::e((string) $value) . '</td></tr>';
        }
        $out .= '<tr>'
            . '<td style="padding:14px 0 10px;' . $f . 'font-size:13px;font-weight:700;color:#0f172a;">Montant total TTC</td>'
            . '<td align="right" style="padding:14px 0 10px;' . $f . 'font-size:20px;font-weight:800;color:#0f172a;">' . View::money($total) . '</td>'
            . '</tr></table></td></tr></table>';

        return $out;
    }

    private static function progress(array $validations): string
    {
        $f = self::FONT;
        $out = '<div style="' . $f . 'font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:8px;">'
            . 'Circuit de validation</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';

        foreach ($validations as $v) {
            [$symbol, $color, $state] = match ($v['decision']) {
                'valide' => ['&#10003;', '#16a34a', 'Validé'],
                'refuse' => ['&#10005;', '#dc2626', 'Refusé'],
                default => ['&bull;', '#d97706', 'En attente'],
            };
            $who = $v['validateur_nom'] ?? $v['assigned_nom'] ?? null;
            $out .= '<tr>'
                . '<td width="30" style="padding:7px 0;vertical-align:middle;">'
                . '<div style="width:22px;height:22px;border-radius:11px;background:' . $color . ';color:#ffffff;text-align:center;'
                . $f . 'font-size:12px;font-weight:700;line-height:22px;">' . $symbol . '</div></td>'
                . '<td style="padding:7px 0;' . $f . 'font-size:14px;color:#0f172a;">'
                . '<strong>' . View::e(Roles::label($v['role'])) . '</strong>'
                . ($who ? '<span style="color:#64748b;"> · ' . View::e($who) . '</span>' : '')
                . '</td>'
                . '<td align="right" style="padding:7px 0;' . $f . 'font-size:12px;font-weight:700;color:' . $color . ';">' . $state . '</td>'
                . '</tr>';
        }

        return $out . '</table>';
    }
}
