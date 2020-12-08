<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Transaction;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class FastSpringService
{
    public function encrypt(array $payload): ?array
    {
        try {
            $pem = File::get(config('fastspring.certificate_path'));
        } catch (Exception $e) {
            return null;
        }

        if (!$pem) {
            return null;
        }

        $aes_key = openssl_random_pseudo_bytes(16);

        $cipher_text = openssl_encrypt(json_encode($payload), 'AES-128-ECB', $aes_key, OPENSSL_RAW_DATA);
        $secure_payload = base64_encode($cipher_text);

        $private_key = openssl_pkey_get_private($pem);
        openssl_private_encrypt($aes_key, $aes_key_encrypted, $private_key);
        $secure_key = base64_encode($aes_key_encrypted);

        return [
            'payload' => $secure_payload,
            'key' => $secure_key,
        ];
    }

    public function verify(Request $request): bool
    {
        $hash = base64_encode(hash_hmac('sha256', $request->getContent(), config('fastspring.hmac_secret'), true));

        return $request->server('HTTP_X_FS_SIGNATURE') === $hash;
    }

    public function verifyLicense(Request $request): bool
    {
        $data = $request->all();

        ksort($data);

        $hashparam = 'security_request_hash';
        $privatekey = config('fastspring.license_private_key');

        $string = '';

        foreach ($data as $key => $val) {
            if ($key != $hashparam) {
                $string .= stripslashes($val);
            }
        }

        return md5($string . $privatekey) === $request->input($hashparam);
    }

    public function createTransaction(array $payload): ?Transaction
    {
        if (data_get($payload, 'type') !== 'order.completed') {
            return null;
        }

        $data = data_get($payload, 'data');

        try {
            $plan = Plan::doesntHave('transaction')->findOrFail(data_get($data, 'tags.plan'));

            $user = $plan->user;
        } catch (Exception $e) {
            return null;
        }

        return Transaction::create([
            'plan_id' => $plan->id,
            'user_id' => $user->id,
            'order_id' => data_get($data, 'id'),
            'reference' => data_get($data, 'reference'),
            'country' => data_get($data, 'account.country', data_get($data, 'address.country', '---')),
            'currency' => data_get($data, 'currency'),
            'payout_currency' => data_get($data, 'payoutCurrency'),
            'amount' => data_get($data, 'total'),
            'card_type' => data_get($data, 'payment.type'),
            'card_ending' => data_get($data, 'payment.cardEnding'),
            'invoice_url' => data_get($data, 'invoiceUrl'),
            'domain' => data_get($data, 'tags.domain'),
            'payload' => $payload,
        ]);
    }
}
