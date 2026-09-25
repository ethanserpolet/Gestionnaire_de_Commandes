<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class UploadService
{
    private const ALLOWED_MIME = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 15 * 1024 * 1024; // 15 Mo

    public static function storageRoot(): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads';
    }

    /**
     * Validates and stores one uploaded file for a given commande line.
     *
     * @return array{nom_original:string,nom_stocke:string,mime:string,taille:int}
     */
    public static function store(array $file, int $commandeId): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException('Fichier invalide.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Erreur lors du téléversement (code ' . $file['error'] . ').');
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Le fichier dépasse la taille maximale autorisée (15 Mo).');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException('Type de fichier non autorisé (PDF, PNG, JPG, GIF, WEBP uniquement).');
        }

        $dir = self::storageRoot() . '/' . $commandeId;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Impossible de créer le dossier de stockage.');
        }

        $ext = self::ALLOWED_MIME[$mime];
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $stored;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Échec de l\'enregistrement du fichier.');
        }

        return [
            'nom_original' => self::sanitizeName($file['name']),
            'nom_stocke' => $stored,
            'mime' => $mime,
            'taille' => (int) $file['size'],
        ];
    }

    public static function absolutePath(int $commandeId, string $nomStocke): string
    {
        return self::storageRoot() . '/' . $commandeId . '/' . $nomStocke;
    }

    public static function delete(int $commandeId, string $nomStocke): void
    {
        $path = self::absolutePath($commandeId, $nomStocke);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private static function sanitizeName(string $name): string
    {
        $name = basename($name);
        return mb_substr(preg_replace('/[^\w\-. À-ÿ]/u', '_', $name) ?? $name, 0, 200);
    }
}
