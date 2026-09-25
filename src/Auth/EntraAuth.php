<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config;
use RuntimeException;

final class EntraAuth
{
    private const SCOPES = 'openid profile email offline_access https://graph.microsoft.com/User.Read https://graph.microsoft.com/Mail.Send';

    /** Le contrôle des groupes (APP_BLOCKED_GROUPS) nécessite en plus GroupMember.Read.All. */
    private static function scopes(): string
    {
        return Config::list('APP_BLOCKED_GROUPS')
            ? self::SCOPES . ' https://graph.microsoft.com/GroupMember.Read.All'
            : self::SCOPES;
    }

    /**
     * Groupes interdits (APP_BLOCKED_GROUPS, ex. GP_eleves) dont l'utilisateur est membre, y compris
     * via des groupes imbriqués. Compare le nom Entra et le nom AD d'origine (onPremisesSamAccountName).
     *
     * @return string[]
     * @throws RuntimeException si Microsoft Graph refuse la lecture des groupes
     */
    public static function blockedGroupsOf(string $accessToken): array
    {
        $blocked = Config::list('APP_BLOCKED_GROUPS');
        if (!$blocked) {
            return [];
        }

        $found = [];
        $url = 'https://graph.microsoft.com/v1.0/me/transitiveMemberOf?$select=displayName,onPremisesSamAccountName&$top=999';
        for ($page = 0; $url && $page < 20; $page++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($response === false || $status !== 200) {
                throw new RuntimeException("Lecture des groupes impossible (HTTP $status). Vérifiez la permission GroupMember.Read.All dans Entra.");
            }
            $data = json_decode($response, true);
            foreach ($data['value'] ?? [] as $group) {
                foreach ([$group['displayName'] ?? '', $group['onPremisesSamAccountName'] ?? ''] as $name) {
                    if ($name !== '' && in_array(mb_strtolower($name), $blocked, true)) {
                        $found[] = $name;
                    }
                }
            }
            $url = $data['@odata.nextLink'] ?? null;
        }
        return array_values(array_unique($found));
    }

    public static function authorizeUrl(string $state): string
    {
        $tenant = Config::required('ENTRA_APP_ID_LOCATAIRE');
        $params = [
            'client_id' => Config::required('ENTRA_APP_ID_CLIENT'),
            'response_type' => 'code',
            'redirect_uri' => Config::required('ENTRA_REDIRECT_URI'),
            'response_mode' => 'query',
            'scope' => self::scopes(),
            'state' => $state,
            'prompt' => 'select_account',
        ];
        return "https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize?" . http_build_query($params);
    }

    /** @return array{access_token:string,refresh_token:string,expires_in:int} */
    public static function exchangeCode(string $code): array
    {
        return self::tokenRequest([
            'client_id' => Config::required('ENTRA_APP_ID_CLIENT'),
            'client_secret' => Config::required('ENTRA_APP_SECRET_VALUE'),
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => Config::required('ENTRA_REDIRECT_URI'),
            'scope' => self::scopes(),
        ]);
    }

    /** @return array{access_token:string,refresh_token:string,expires_in:int} */
    public static function refreshToken(string $refreshToken): array
    {
        return self::tokenRequest([
            'client_id' => Config::required('ENTRA_APP_ID_CLIENT'),
            'client_secret' => Config::required('ENTRA_APP_SECRET_VALUE'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => self::scopes(),
        ]);
    }

    private static function tokenRequest(array $fields): array
    {
        $tenant = Config::required('ENTRA_APP_ID_LOCATAIRE');
        $url = "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException("Erreur réseau vers Microsoft Entra : $error");
        }

        $data = json_decode($response, true);
        if ($status !== 200 || !isset($data['access_token'])) {
            $desc = $data['error_description'] ?? $response;
            throw new RuntimeException("Échec de l'authentification Microsoft : $desc");
        }

        return $data;
    }

    /** @return array{id:string,email:string,displayName:string} */
    public static function fetchProfile(string $accessToken): array
    {
        $ch = curl_init('https://graph.microsoft.com/v1.0/me');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status !== 200) {
            throw new RuntimeException('Impossible de récupérer le profil Microsoft.');
        }

        $data = json_decode($response, true);
        $email = $data['mail'] ?? $data['userPrincipalName'] ?? null;
        if (!$email || empty($data['id'])) {
            throw new RuntimeException('Profil Microsoft incomplet.');
        }

        return [
            'id' => $data['id'],
            'email' => strtolower($email),
            'displayName' => $data['displayName'] ?? $email,
        ];
    }
}
