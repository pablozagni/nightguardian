<?php

use App\Privacy\Scrubber;

it('filters values of denylisted keys case-insensitively, by substring', function (string $key) {
    $result = (new Scrubber)->scrub([$key => 'super-secret-value', 'safe' => 'visible']);

    expect($result[$key])->toBe('[Filtered]')->and($result['safe'])->toBe('visible');
})->with([
    'password', 'PASSWORD', 'user_password_confirmation', 'passwd', 'pwd', 'client_secret', 'api_token',
    'api_key', 'apikey', 'access_key', 'private_key', 'Authorization', 'auth', 'Cookie', 'session_id',
    'csrf_token', 'xsrf', 'credit_card', 'card_number', 'cvv', 'iban',
]);

it('scrubs nested arrays and lists', function () {
    $result = (new Scrubber)->scrub([
        'context' => ['user' => ['password' => 'x', 'name' => 'Ada'], 'items' => [['token' => 'y', 'sku' => 'A1']]],
    ]);

    expect($result['context']['user'])->toBe(['password' => '[Filtered]', 'name' => 'Ada'])
        ->and($result['context']['items'][0])->toBe(['token' => '[Filtered]', 'sku' => 'A1']);
});

it('filters Luhn-valid card numbers inside strings', function (string $input) {
    expect((new Scrubber)->scrubString("Charge failed for card {$input} today"))
        ->toBe('Charge failed for card [Filtered] today');
})->with([
    'visa plain' => '4111111111111111',
    'visa spaced' => '4111 1111 1111 1111',
    'visa dashed' => '4111-1111-1111-1111',
    'amex' => '378282246310005',
]);

it('keeps digit sequences that are not card numbers', function () {
    $scrubber = new Scrubber;

    expect($scrubber->scrubString('Order 4111111111111112 failed'))->toBe('Order 4111111111111112 failed')
        ->and($scrubber->scrubString('Invoice 12345'))->toBe('Invoice 12345');
});

it('filters bearer tokens, JWTs and private key blocks', function () {
    $scrubber = new Scrubber;
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTYifQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

    expect($scrubber->scrubString('Header Authorization: Bearer abc.DEF-123_xyz== sent'))->toBe('Header Authorization: Bearer [Filtered] sent')
        ->and($scrubber->scrubString("jwt={$jwt}"))->toBe('jwt=[Filtered]')
        ->and($scrubber->scrubString("key:\n-----BEGIN RSA PRIVATE KEY-----\nMIIEow\n-----END RSA PRIVATE KEY-----\nend"))->toBe("key:\n[Filtered]\nend");
});

it('scrubs strings found inside arrays', function () {
    $result = (new Scrubber)->scrub(['message' => 'Bearer abcdef123', 'nested' => ['note' => 'card 4111111111111111']]);

    expect($result['message'])->toBe('Bearer [Filtered]')->and($result['nested']['note'])->toBe('card [Filtered]');
});

it('supports extra denylist keys from configuration', function () {
    config(['nightguardian.scrub.extra_keys' => ['cuit']]);

    $result = Scrubber::fromConfig()->scrub(['cuit' => '20-12345678-9', 'name' => 'Ada']);

    expect($result)->toBe(['cuit' => '[Filtered]', 'name' => 'Ada']);
});

it('leaves non-string scalars untouched', function () {
    expect((new Scrubber)->scrub(['count' => 3, 'ok' => true, 'nothing' => null, 'ratio' => 1.5]))
        ->toBe(['count' => 3, 'ok' => true, 'nothing' => null, 'ratio' => 1.5]);
});
