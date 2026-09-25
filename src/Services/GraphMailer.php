<?php
declare(strict_types=1);

namespace App\Services;

final class GraphMailer
{
    /** When not null, e-mails are recorded here instead of being sent (simulation mode). */
    private static ?array $captured = null;

    public static function startCapture(): void
    {
        self::$captured = [];
    }

    /** Returns the e-mails recorded since the last call, capture stays on. */
    public static function drainCaptured(): array
    {
        $mails = self::$captured ?? [];
        if (self::$captured !== null) {
            self::$captured = [];
        }
        return $mails;
    }

    public static function stopCapture(): void
    {
        self::$captured = null;
    }

    /**
     * Sends an email as the currently authenticated user via Microsoft Graph.
     *
     * @param string[] $toAddresses
     * @return bool true when Graph accepted the message (or it was captured)
     */
    public static function send(string $accessToken, array $toAddresses, string $subject, string $htmlBody): bool
    {
        $toAddresses = array_values(array_unique(array_filter($toAddresses)));
        if (empty($toAddresses)) {
            return true;
        }

        if (self::$captured !== null) {
            self::$captured[] = ['to' => $toAddresses, 'subject' => $subject, 'html' => $htmlBody];
            return true;
        }

        $payload = [
            'message' => [
                'subject' => $subject,
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $htmlBody,
                ],
                'toRecipients' => array_map(
                    static fn(string $addr) => ['emailAddress' => ['address' => $addr]],
                    $toAddresses
                ),
            ],
            'saveToSentItems' => true,
        ];

        $ch = curl_init('https://graph.microsoft.com/v1.0/me/sendMail');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer $accessToken",
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 202) {
            error_log("GraphMailer: échec envoi mail (HTTP $status): $response");
            // Do not throw: a notification failure must not block the business action.
            return false;
        }
        return true;
    }
}
