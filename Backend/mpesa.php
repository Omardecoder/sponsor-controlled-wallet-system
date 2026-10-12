<?php
// Backend/mpesa.php
// Small helper for Safaricom Daraja (M-Pesa Express / STK Push).
// Usage:  require_once __DIR__ . '/mpesa.php';

require_once __DIR__ . '/mpesa_config.php';

function mpesa_base_url(): string
{
    return MPESA_ENV === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
}

/** Sends an HTTPS request and returns [httpCode, decodedJson|null, rawBody]. Throws on network errors. */
function mpesa_http(string $method, string $url, array $headers, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,     // never turn this off
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err  = curl_error($ch);
        $code = curl_errno($ch);
        curl_close($ch);
        if ($code === 60 || $code === 77) {
            throw new RuntimeException('SSL certificate problem. In C:\\xampp\\php\\php.ini set curl.cainfo = "C:\\xampp\\apache\\bin\\curl-ca-bundle.crt", then restart Apache. (' . $err . ')');
        }
        throw new RuntimeException('Could not reach Safaricom: ' . $err);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$status, json_decode($raw, true), $raw];
}

/** Gets an OAuth access token (valid about 1 hour). */
function mpesa_token(): string
{
    [$status, $json] = mpesa_http(
        'GET',
        mpesa_base_url() . '/oauth/v1/generate?grant_type=client_credentials',
        ['Authorization: Basic ' . base64_encode(MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET)]
    );
    if ($status !== 200 || empty($json['access_token'])) {
        throw new RuntimeException('Daraja refused the Consumer Key/Secret (HTTP ' . $status . '). Check mpesa_config.php.');
    }
    return $json['access_token'];
}

/** "0712 345 678", "+254712345678", "712345678" -> "254712345678". Returns null if not a Kenyan mobile number. */
function mpesa_phone(string $input): ?string
{
    $digits = preg_replace('/\D/', '', $input);
    if (preg_match('/^0([17]\d{8})$/', $digits, $m))   return '254' . $m[1];
    if (preg_match('/^254([17]\d{8})$/', $digits, $m)) return '254' . $m[1];
    if (preg_match('/^([17]\d{8})$/', $digits, $m))    return '254' . $m[1];
    return null;
}

/**
 * Sends an STK Push prompt to the payer's phone.
 * $amount must be whole shillings. Returns Daraja's reply (MerchantRequestID, CheckoutRequestID, ResponseCode...).
 */
function mpesa_stk_push(string $phone254, int $amount, string $reference, string $description): array
{
    $timestamp = date('YmdHis');
    $password  = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);

    [$status, $json, $raw] = mpesa_http(
        'POST',
        mpesa_base_url() . '/mpesa/stkpush/v1/processrequest',
        ['Authorization: Bearer ' . mpesa_token(), 'Content-Type: application/json'],
        [
            'BusinessShortCode' => MPESA_SHORTCODE,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone254,
            'PartyB'            => MPESA_SHORTCODE,
            'PhoneNumber'       => $phone254,
            'CallBackURL'       => MPESA_CALLBACK_URL . '?token=' . urlencode(MPESA_CALLBACK_TOKEN),
            'AccountReference'  => mb_substr($reference, 0, 12),
            'TransactionDesc'   => mb_substr($description, 0, 13),
        ]
    );
    if ($status !== 200 || ($json['ResponseCode'] ?? '') !== '0') {
        $msg = $json['errorMessage'] ?? $json['ResponseDescription'] ?? ('HTTP ' . $status);
        throw new RuntimeException('M-Pesa did not accept the request: ' . $msg);
    }
    return $json;
}


/**
 * Asks Daraja for the status of an STK Push (used when the callback is late).
 * Returns Daraja's reply. ResultCode "0" = paid, "1032" = cancelled, "1037" = no response, "1" = not enough money, "2001" = wrong PIN.
 * While the customer hasn't answered yet, Daraja replies with an error ("The transaction is being processed").
 */
function mpesa_stk_query(string $checkoutRequestId): array
{
    $timestamp = date('YmdHis');
    [$status, $json] = mpesa_http(
        'POST',
        mpesa_base_url() . '/mpesa/stkpushquery/v1/query',
        ['Authorization: Bearer ' . mpesa_token(), 'Content-Type: application/json'],
        [
            'BusinessShortCode' => MPESA_SHORTCODE,
            'Password'          => base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp),
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ]
    );
    return is_array($json) ? $json : [];
}