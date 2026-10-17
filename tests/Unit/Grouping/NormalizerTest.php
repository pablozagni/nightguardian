<?php

use App\Grouping\Normalizer;

it('normalizes file paths', function (string $input, string $expected) {
    expect(Normalizer::file($input))->toBe($expected);
})->with([
    'forward slashes' => ['app\\Http\\Foo.php', 'app/Http/Foo.php'],
    'leading dot slash' => ['./app/Foo.php', 'app/Foo.php'],
    'release directory' => ['/var/www/app/releases/20261017120000/app/Foo.php', 'app/Foo.php'],
    'relative release directory' => ['releases/20261017120000/app/Foo.php', 'app/Foo.php'],
    'plain' => ['app/Foo.php', 'app/Foo.php'],
]);

it('replaces variable parts of messages with placeholders', function (string $input, string $expected) {
    expect(Normalizer::message($input))->toBe($expected);
})->with([
    'numbers' => ['Order 1234 not found for user 56', 'Order <n> not found for user <n>'],
    'uuid' => ['No query results for 6f1c1d1e-8a3b-4c55-9b2f-1d2e3f4a5b6c', 'No query results for <uuid>'],
    'hex id' => ['Commit deadbeef12 failed', 'Commit <hex> failed'],
    'quoted strings' => ["Column 'description' cannot be null", 'Column <str> cannot be null'],
    'double quoted' => ['Undefined array key "customer_id"', 'Undefined array key <str>'],
    'email' => ['Cannot send to ada@example.com now', 'Cannot send to <email> now'],
    'url' => ['GET https://api.test/v1/users?id=3 failed', 'GET <url> failed'],
    'whitespace' => ["too   many \n spaces", 'too many spaces'],
]);

it('truncates messages to 255 characters', function () {
    expect(mb_strlen(Normalizer::message(str_repeat('word ', 200))))->toBeLessThanOrEqual(255);
});

it('normalizes SQL', function () {
    expect(Normalizer::sql("SELECT * FROM invoices /* trace */ WHERE id IN (?, ?, ?)  -- comment\n AND x = ?"))
        ->toBe('select * from invoices where id in (?) and x = ?');
});

it('strips anonymous class suffixes', function () {
    expect(Normalizer::className("class@anonymous\0/var/www/app/Foo.php:12$0"))->toBe('class@anonymous')
        ->and(Normalizer::className('App\\Foo'))->toBe('App\\Foo');
});

it('normalizes URL paths', function () {
    expect(Normalizer::path('https://app.test/invoices/12/items/6f1c1d1e-8a3b-4c55-9b2f-1d2e3f4a5b6c?page=2'))
        ->toBe('/invoices/<n>/items/<uuid>');
});
