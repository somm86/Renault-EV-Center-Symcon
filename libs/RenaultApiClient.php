<?php
/**
 * Renault cloud reader (Gigya + Kamereon). No vehicle or wallbox write APIs.
 * Independently implemented with reference to hacf-fr/renault-api and lorbetzki/net.lorbetzki.zoe.
 */
declare(strict_types=1);

class RenaultEVApiException extends RuntimeException {}

class RenaultEVApiClient
{
    private const GIGYA_ROOT = 'https://accounts.eu1.gigya.com';
    private const KAMEREON_ROOT = 'https://api-wired-prod-1-euw1.wrd-aws.com';
    public const DEFAULT_GIGYA_KEY = '3_VgdkgtIRH3AdHvJm-cjV2ug2EFE0lxt0IJzMC4MFqZjFpn_GYFXVdNZ19L7wZX0N';
    public const DEFAULT_KAMEREON_KEY = 'YjkKtHmGfaceeuExUDKGxrLZGGvtVS0J';

    /** @var Closure */ private $read;
    /** @var Closure */ private $write;
    /** @var Closure */ private $property;

    public function __construct(callable $read, callable $write, callable $property)
    {
        $this->read = Closure::fromCallable($read);
        $this->write = Closure::fromCallable($write);
        $this->property = Closure::fromCallable($property);
    }

    private function a(string $key): string { return (string) ($this->read)($key); }
    private function put(string $key, string $value): void { ($this->write)($key, $value); }
    private function p(string $key): string { return (string) ($this->property)($key); }
    private function gigyaKey(): string { return $this->p('GigyaAPIKey') ?: self::DEFAULT_GIGYA_KEY; }
    private function kamKey(): string { return $this->p('KamereonAPIKey') ?: self::DEFAULT_KAMEREON_KEY; }
    private function country(): string { return strtoupper($this->p('Country') ?: 'AT'); }

    /**
     * @return array<string, mixed>
     * @throws RenaultEVApiException
     */
    protected function http(string $method, string $url, array $params = [], array $headers = [], bool $cookies = false): array
    {
        if (!extension_loaded('curl')) {
            throw new RenaultEVApiException('PHP-Erweiterung cURL fehlt.');
        }
        $isPost = strtoupper($method) === 'POST';
        if (!$isPost && $params) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RenaultEVApiException('cURL konnte nicht initialisiert werden.');
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'RenaultEVCenterSymcon/0.1.0',
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($isPost) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
            if (!$headers) {
                $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
            }
        }
        if ($cookies) {
            $stored = json_decode($this->a('DeviceCookies'), true);
            if (is_array($stored) && $stored) {
                $pairs = [];
                foreach (['gmid', 'ucid'] as $name) {
                    if (isset($stored[$name]) && is_string($stored[$name])) {
                        $pairs[] = $name . '=' . $stored[$name];
                    }
                }
                if ($pairs) $opts[CURLOPT_COOKIE] = implode('; ', $pairs);
            }
            $opts[CURLOPT_HEADERFUNCTION] = function ($ch, string $header): int {
                if (preg_match('/^Set-Cookie:\s*(gmid|ucid)=([^;\r\n]+)/i', $header, $m)) {
                    $current = json_decode($this->a('DeviceCookies'), true) ?: [];
                    $current[strtolower($m[1])] = $m[2];
                    $this->put('DeviceCookies', json_encode($current, JSON_THROW_ON_ERROR));
                }
                return strlen($header);
            };
        }
        curl_setopt_array($handle, $opts);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);
        if ($raw === false) {
            throw new RenaultEVApiException('Netzwerkfehler beim Renault-Server: ' . $curlError);
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) {
            throw new RenaultEVApiException('Ungültige Renault-Serverantwort (HTTP ' . $status . ').');
        }
        // Never include full server response or authentication data in errors/logs.
        if ($status >= 400 && !(isset($json['errorCode']) && $status < 500)) {
            throw new RenaultEVApiException('Renault HTTP ' . $status . ' – Serverzugriff fehlgeschlagen.', $status);
        }
        return $json;
    }

    private function gigya(string $path, array $args, string $method = 'POST'): array
    {
        $args = ['ApiKey' => $this->gigyaKey()] + $args;
        return $this->http($method, self::GIGYA_ROOT . '/' . $path, $args, [], true);
    }

    private function checkGigya(array $data): void
    {
        $error = (int) ($data['errorCode'] ?? 0);
        if ($error !== 0) {
            throw new RenaultEVApiException('Gigya-Anmeldung fehlgeschlagen (Fehlercode ' . $error . ').', $error);
        }
    }

    public function login(): string
    {
        if ($this->p('AccountEmail') === '' || $this->p('AccountPassword') === '') {
            throw new RenaultEVApiException('My-Renault-E-Mail und Passwort fehlen.');
        }
        $device = json_decode($this->a('DeviceCookies'), true);
        if (!is_array($device) || empty($device['gmid'])) {
            $this->http('GET', self::GIGYA_ROOT . '/accounts.webSdkBootstrap',
                ['APIKey' => $this->gigyaKey()], [], true);
        }
        $response = $this->gigya('accounts.login', [
            'loginID' => $this->p('AccountEmail'),
            'password' => $this->p('AccountPassword'),
        ]);
        $errorCode = (int) ($response['errorCode'] ?? 0);
        if ($errorCode === 403101 && !empty($response['regToken'])) {
            $this->put('TfaRegToken', (string) $response['regToken']);
            return '2FA_REQUIRED';
        }
        $this->checkGigya($response);
        $session = (string) ($response['sessionInfo']['cookieValue'] ?? '');
        if ($session === '') throw new RenaultEVApiException('Gigya hat keinen Login-Token geliefert.');
        $this->put('LoginToken', $session);
        $this->put('JWT', '');
        $this->put('JWTExpiry', '0');
        $this->put('TfaRegToken', '');
        $this->put('TfaAssertion', '');
        $this->put('TfaPhvToken', '');
        $this->discoverAccount();
        return 'OK';
    }

    /** Start an email two-factor challenge; returns eligible (masked) email choices. */
    public function prepareTwoFactor(): array
    {
        $reg = $this->a('TfaRegToken');
        if ($reg === '') throw new RenaultEVApiException('Keine ausstehende Zwei-Faktor-Anmeldung. Zuerst Anmelden klicken.');
        $cookies = json_decode($this->a('DeviceCookies'), true) ?: [];
        $init = $this->gigya('accounts.tfa.initTFA', [
            'regToken' => $reg, 'provider' => 'gigyaEmail', 'mode' => 'verify',
            'gmid' => (string) ($cookies['gmid'] ?? ''), 'ucid' => (string) ($cookies['ucid'] ?? ''),
        ], 'GET');
        $this->checkGigya($init);
        $assertion = (string) ($init['gigyaAssertion'] ?? '');
        if ($assertion === '') throw new RenaultEVApiException('2FA: Gigya-Assertion fehlt.');
        $this->put('TfaAssertion', $assertion);
        $mail = $this->gigya('accounts.tfa.email.getEmails', ['gigyaAssertion' => $assertion], 'GET');
        $this->checkGigya($mail);
        $results = [];
        foreach (($mail['emails'] ?? []) as $entry) {
            if (is_array($entry) && !empty($entry['id'])) {
                $results[] = ['id' => (string) $entry['id'], 'masked' => (string) ($entry['obfuscated'] ?? 'E-Mail')];
            }
        }
        $this->put('TfaEmailChoices', json_encode($results, JSON_THROW_ON_ERROR));
        if (!$results) throw new RenaultEVApiException('2FA: Keine bestätigungsfähige E-Mail-Adresse gefunden.');
        return $results;
    }

    /** Request a verification email (email_id should be selected if there are several). */
    public function sendTwoFactorCode(): string
    {
        if ($this->a('TfaAssertion') === '') $this->prepareTwoFactor();
        $choices = json_decode($this->a('TfaEmailChoices'), true) ?: [];
        $configured = trim($this->p('TfaEmailID'));
        $selected = null;
        foreach ($choices as $choice) {
            if ($configured !== '' && $choice['id'] === $configured) $selected = $choice;
        }
        if ($selected === null && count($choices) === 1) $selected = $choices[0];
        if ($selected === null) throw new RenaultEVApiException('Mehrere E-Mail-Adressen vorhanden. TfaEmailID konfigurieren.');
        $response = $this->gigya('accounts.tfa.email.sendVerificationCode', [
            'gigyaAssertion' => $this->a('TfaAssertion'),
            'emailID' => $selected['id'], 'lang' => in_array($this->country(), ['AT', 'DE', 'CH'], true) ? 'de' : strtolower($this->country()),
        ], 'GET');
        $this->checkGigya($response);
        $phv = (string) ($response['phvToken'] ?? '');
        if ($phv === '') throw new RenaultEVApiException('2FA: Kein Verifizierungstoken empfangen.');
        $this->put('TfaPhvToken', $phv);
        return (string) ($selected['masked'] ?? 'E-Mail');
    }

    public function completeTwoFactor(string $code): string
    {
        $code = trim($code);
        if (!preg_match('/^[a-zA-Z0-9]{4,12}$/', $code)) throw new RenaultEVApiException('Ungültiges Format des Bestätigungscodes.');
        $assertion = $this->a('TfaAssertion');
        $phv = $this->a('TfaPhvToken');
        $reg = $this->a('TfaRegToken');
        if ($assertion === '' || $phv === '' || $reg === '') throw new RenaultEVApiException('Kein offener 2FA-Vorgang.');
        $result = $this->gigya('accounts.tfa.email.completeVerification', [
            'gigyaAssertion' => $assertion, 'phvToken' => $phv, 'code' => $code,
        ], 'GET');
        $this->checkGigya($result);
        $provider = (string) ($result['providerAssertion'] ?? '');
        if ($provider === '') throw new RenaultEVApiException('2FA: Provider-Assertion fehlt.');
        $final = $this->gigya('accounts.tfa.finalizeTFA', [
            'gigyaAssertion' => $assertion, 'providerAssertion' => $provider,
            'regToken' => $reg, 'tempDevice' => 'false',
        ], 'GET');
        $this->checkGigya($final);
        $status = $this->login();
        if ($status !== 'OK') throw new RenaultEVApiException('2FA bestätigt, erneute Anmeldung aber nicht erfolgreich.');
        return 'OK';
    }

    private function jwt(): string
    {
        if ($this->a('JWT') !== '' && (int) $this->a('JWTExpiry') > time() + 90) return $this->a('JWT');
        $token = $this->a('LoginToken');
        if ($token === '') throw new RenaultEVApiException('Kein Renault-Login. Im Modul zuerst anmelden.');
        $jwt = $this->gigya('accounts.getJWT', [
            'login_token' => $token,
            'fields' => 'data.personId,data.gigyaDataCenter', 'expiration' => 900,
        ]);
        $this->checkGigya($jwt);
        $result = (string) ($jwt['id_token'] ?? '');
        if ($result === '') throw new RenaultEVApiException('Kein JWT von Gigya erhalten.');
        $this->put('JWT', $result);
        $this->put('JWTExpiry', (string) (time() + 840));
        return $result;
    }

    private function commerce(string $path, array $params = []): array
    {
        $token = $this->jwt();
        $data = $this->http('GET', self::KAMEREON_ROOT . '/commerce/v1/' . ltrim($path, '/'),
            $params, ['Content-Type: application/vnd.api+json', 'apikey: ' . $this->kamKey(), 'x-gigya-id_token: ' . $token]);
        if (!empty($data['errors'])) throw new RenaultEVApiException('Renault Kamereon meldet einen Anwendungsfehler.');
        if (!empty($data['messages'][0]['code'])) {
            throw new RenaultEVApiException('Renault Kamereon: ' . (string) $data['messages'][0]['code']);
        }
        return $data;
    }

    public function discoverAccount(): void
    {
        $info = $this->gigya('accounts.getAccountInfo', ['login_token' => $this->a('LoginToken')]);
        $this->checkGigya($info);
        $personId = (string) ($info['data']['personId'] ?? '');
        if ($personId === '') throw new RenaultEVApiException('Person-ID fehlt in Renault-Konto.');
        $this->put('PersonID', $personId);
        $person = $this->commerce('persons/' . rawurlencode($personId), ['country' => $this->country()]);
        $accounts = $person['accounts'] ?? ($person['data']['accounts'] ?? []);
        $accountId = '';
        foreach ($accounts as $account) {
            if (is_array($account) && !empty($account['accountId'])) { $accountId = (string) $account['accountId']; break; }
        }
        if ($accountId === '') throw new RenaultEVApiException('Renault-Konto-ID fehlt.');
        $this->put('AccountID', $accountId);
        $manualVIN = strtoupper(trim($this->p('VehicleVIN')));
        if ($manualVIN !== '') {
            if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $manualVIN)) throw new RenaultEVApiException('Die Fahrgestellnummer (VIN) ist ungültig.');
            $this->put('ResolvedVIN', $manualVIN);
            return;
        }
        // Renault can return different vehicle-list shapes; scan only for VIN-shaped values.
        try {
            $vehicles = $this->commerce('accounts/' . rawurlencode($accountId) . '/vehicles', ['country' => $this->country()]);
            $vin = $this->findVIN($vehicles);
            if ($vin !== '') $this->put('ResolvedVIN', $vin);
        } catch (RenaultEVApiException $e) {
            // Manual VIN configuration is still available if list permission is missing.
        }
        if ($this->a('ResolvedVIN') === '') throw new RenaultEVApiException('Keine Fahrzeug-VIN gefunden. Bitte Fahrzeug-VIN im Modul eintragen.');
    }

    private function findVIN(array $data): string
    {
        foreach ($data as $key => $value) {
            if (strtolower((string) $key) === 'vin' && is_string($value)
                && preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', strtoupper($value))) return strtoupper($value);
            if (is_array($value)) {
                $found = $this->findVIN($value);
                if ($found !== '') return $found;
            }
        }
        return '';
    }

    /** @return array<string, mixed> Normalized, read-only vehicle information. */
    public function snapshot(): array
    {
        $account = $this->a('AccountID');
        $vin = strtoupper(trim($this->p('VehicleVIN') ?: $this->a('ResolvedVIN')));
        if ($account === '' || $vin === '') throw new RenaultEVApiException('Renault-Konto oder VIN fehlen. Erst anmelden.');
        if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) throw new RenaultEVApiException('VIN ist ungültig.');
        $root = 'accounts/' . rawurlencode($account) . '/kamereon/kca/car-adapter/';
        $common = '/cars/' . rawurlencode($vin) . '/';
        $values = ['vin' => $vin];
        $errors = [];
        try {
            $raw = $this->commerce($root . 'v2' . $common . 'battery-status', ['country' => $this->country()]);
            $a = $raw['data']['attributes'] ?? [];
            foreach (['batteryLevel' => 'soc', 'batteryAutonomy' => 'range', 'chargingStatus' => 'chargeStatus', 'plugStatus' => 'plug', 'chargingRemainingTime' => 'remainingMinutes', 'timestamp' => 'batteryTimestamp'] as $from => $to) {
                if (array_key_exists($from, $a) && $a[$from] !== null) $values[$to] = $a[$from];
            }
        } catch (RenaultEVApiException $e) { $errors[] = 'Batterie: ' . $e->getMessage(); }
        try {
            $raw = $this->commerce($root . 'v1' . $common . 'cockpit', ['country' => $this->country()]);
            $a = $raw['data']['attributes'] ?? [];
            if (isset($a['totalMileage'])) $values['mileage'] = $a['totalMileage'];
        } catch (RenaultEVApiException $e) { $errors[] = 'Kilometerstand: ' . $e->getMessage(); }
        if (count($values) === 1) throw new RenaultEVApiException(implode(' | ', $errors));
        $values['warnings'] = $errors;
        return $values;
    }
}
