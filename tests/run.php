<?php
declare(strict_types=1);
require_once __DIR__ . '/../libs/Analytics.php';
require_once __DIR__ . '/../libs/RenaultApiClient.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    echo "PASS: $message\n";
}

$now = strtotime('2026-11-04 12:00:00');
$s = RenaultEVAnalytics::emptyState();
$s = RenaultEVAnalytics::updateTrip($s, 25000, 80, $now, 15, 60);
check(count($s['trips']) === 0 && $s['trip'] === null, 'Baseline does not create a trip');
$s = RenaultEVAnalytics::updateTrip($s, 25008, 78, $now + 300, 15, 60);
check(abs($s['trip']['km'] - 8) < 0.01, 'Trip starts from odometer delta');
$s = RenaultEVAnalytics::updateTrip($s, 25013.5, 75, $now + 600, 15, 60);
$s = RenaultEVAnalytics::updateTrip($s, 25013.5, 75, $now + 1800, 15, 60);
check(count($s['trips']) === 1 && abs($s['trips'][0]['km'] - 13.5) < 0.01, 'Trip auto-closes on idle');
check(abs($s['trips'][0]['kwhEstimated'] - 3) < 0.01, 'SOC decrease estimates drive energy');
$s = RenaultEVAnalytics::updateTrip($s, 200, 77, $now + 2400, 15, 60);
check(count($s['trips']) === 1 && $s['trip'] === null, 'Odometer reset rejected');

$s = RenaultEVAnalytics::emptyState();
$s = RenaultEVAnalytics::updateCharge($s, true, 11.0, 1500, 0, 22, $now, .25);
$s = RenaultEVAnalytics::updateCharge($s, true, 11.0, 1502, 2, 25, $now + 600, .25);
$s = RenaultEVAnalytics::updateCharge($s, true, 11.0, 1505, 5, 29, $now + 1200, .25);
$s = RenaultEVAnalytics::updateCharge($s, false, 0, 1505, 5, 29, $now + 1500, .25);
check(count($s['charges']) === 1, 'Session finishes when wallbox reports not charging');
check($s['charges'][0]['kwh'] === 5.0, 'Total energy counter preferred');
check($s['charges'][0]['costEUR'] === 1.25, 'Fixed tariff is applied');
$stats = RenaultEVAnalytics::summary($s, $now + 1500);
check($stats['chargedTotalKwh'] === 5.0, 'Summary aggregates session energy');

$s = RenaultEVAnalytics::emptyState();
$s = RenaultEVAnalytics::updateCharge($s, true, 6.0, null, null, 40, $now, .25);
$s = RenaultEVAnalytics::updateCharge($s, true, 6.0, null, null, 41, $now + 600, .25);
check(abs($s['charge']['kwh'] - 1.0) < 0.001, 'Power integration works without energy counters');

$store = []; $cfg = [
    'AccountEmail'=>'test@example.com', 'AccountPassword'=>'test-password', 'Country'=>'AT',
    'GigyaAPIKey'=>'public-key','KamereonAPIKey'=>'public-key','VehicleVIN'=>'VF1AAAAA123456789',
    'TfaEmailID'=>'',
];
class TestRenaultEVClient extends RenaultEVApiClient
{
    public bool $requires2FA = false;
    public array $calls = [];
    protected function http(string $method, string $url, array $params = [], array $headers = [], bool $cookies = false): array
    {
        $this->calls[] = [$method, $url];
        if (str_contains($url, 'webSdkBootstrap')) return ['status'=>'OK'];
        if (str_contains($url, 'accounts.login')) {
            if ($this->requires2FA) return ['errorCode'=>403101,'regToken'=>'reg-test'];
            return ['errorCode'=>0,'sessionInfo'=>['cookieValue'=>'cookie-test']];
        }
        if (str_contains($url, 'accounts.getAccountInfo')) return ['errorCode'=>0,'data'=>['personId'=>'person-123']];
        if (str_contains($url, 'accounts.getJWT')) return ['errorCode'=>0,'id_token'=>'token-test'];
        if (str_contains($url, '/persons/')) return ['accounts'=>[['accountId'=>'account-123']]];
        if (str_contains($url, 'battery-status')) return ['data'=>['attributes'=>['batteryLevel'=>63, 'batteryAutonomy'=>298,'chargingStatus'=>0,'plugStatus'=>0]]];
        if (str_contains($url, 'cockpit')) return ['data'=>['attributes'=>['totalMileage'=>28008]]];
        if (str_contains($url, 'initTFA')) return ['errorCode'=>0,'gigyaAssertion'=>'assert-1'];
        if (str_contains($url, 'getEmails')) return ['errorCode'=>0,'emails'=>[['id'=>'email-1','obfuscated'=>'t***@example.com']]];
        if (str_contains($url, 'sendVerificationCode')) return ['errorCode'=>0,'phvToken'=>'phv-test'];
        if (str_contains($url, 'completeVerification')) return ['errorCode'=>0,'providerAssertion'=>'prov-test'];
        if (str_contains($url, 'finalizeTFA')) return ['errorCode'=>0];
        throw new RuntimeException('Unexpected API URL ' . $url);
    }
}
$read = function (string $name) use (&$store): string { return (string) ($store[$name] ?? ''); };
$write = function (string $name, string $value) use (&$store): void { $store[$name] = $value; };
$prop = function (string $name) use (&$cfg): string { return (string) ($cfg[$name] ?? ''); };
$c = new TestRenaultEVClient($read, $write, $prop);
check($c->login() === 'OK', 'Gigya login + JWT + account discovery');
$result = $c->snapshot();
check($result['soc'] === 63 && $result['mileage'] === 28008, 'Renault read-only vehicle data');
check(!array_filter($c->calls, fn($call) => $call[0] !== 'GET' && $call[0] !== 'POST'), 'Only supported HTTP methods used');
check(!array_filter($c->calls, fn($call) => str_contains($call[1], 'actions/') || str_contains($call[1], 'charging-start')), 'No vehicle commands available');

$store = [];
$c = new TestRenaultEVClient($read, $write, $prop);
$c->requires2FA = true;
check($c->login() === '2FA_REQUIRED', 'Two-factor requirement is detected');
$emails = $c->prepareTwoFactor();
check(count($emails) === 1 && $emails[0]['id'] === 'email-1', '2FA email is listed');
check($c->sendTwoFactorCode() === 't***@example.com', '2FA code is requested');
$c->requires2FA = false;
check($c->completeTwoFactor('123456') === 'OK', '2FA verification finalizes login');

$module = file_get_contents(__DIR__ . '/../RenaultEVCenter/module.php');
check(!str_contains($module, 'SetValue(\'Switch') && !str_contains($module, 'RequestAction('), 'Module has no switching actions');
check(!str_contains(file_get_contents(__DIR__ . '/../libs/RenaultApiClient.php'), 'actions/charging'), 'Client contains no charge-control endpoint');
echo "ALL TESTS PASSED\n";
