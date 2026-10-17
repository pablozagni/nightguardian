<?php

use App\Grouping\DefaultFingerprinter;

function fp(array $event): string
{
    return (new DefaultFingerprinter)->fingerprint($event);
}

function exceptionWithFrames(array $frames, string $message = 'boom'): array
{
    return ngEvent(['payload' => ['exceptions' => [['class' => 'RuntimeException', 'message' => $message, 'frames' => $frames]]]]);
}

function appFrame(array $overrides = []): array
{
    return $overrides + ['file' => 'app/Services/Invoicer.php', 'line' => 10, 'function' => 'total', 'class' => 'App\\Services\\Invoicer', 'in_app' => true];
}

it('produces a 40 character sha1', function () {
    expect(fp(ngEvent()))->toMatch('/^[0-9a-f]{40}$/');
});

it('groups the same bug on a different line together', function () {
    $a = exceptionWithFrames([appFrame(['line' => 10])]);
    $b = exceptionWithFrames([appFrame(['line' => 99])], 'another message');

    expect(fp($a))->toBe(fp($b));
});

it('separates bugs in a different function', function () {
    $a = exceptionWithFrames([appFrame()]);
    $b = exceptionWithFrames([appFrame(['function' => 'subtotal'])]);

    expect(fp($a))->not->toBe(fp($b));
});

it('separates bugs with a different exception class', function () {
    $a = ngEvent();
    $b = ngEvent();
    $b['payload']['exceptions'][0]['class'] = 'Illuminate\\Database\\Eloquent\\ModelNotFoundException';

    expect(fp($a))->not->toBe(fp($b));
});

it('uses the innermost exception of a chain', function () {
    $inner = ['class' => 'PDOException', 'message' => 'x', 'frames' => [appFrame()]];
    $a = ngEvent(['payload' => ['exceptions' => [['class' => 'QueryException', 'frames' => [appFrame(['function' => 'other'])]], $inner]]]);
    $b = ngEvent(['payload' => ['exceptions' => [['class' => 'WrapperException', 'frames' => []], $inner]]]);

    expect(fp($a))->toBe(fp($b));
});

it('picks the first in-app frame as culprit, skipping vendor frames', function () {
    $vendor = ['file' => 'vendor/x/y.php', 'line' => 1, 'function' => 'run', 'class' => 'Vendor\\Y', 'in_app' => false];
    $a = exceptionWithFrames([$vendor, appFrame()]);
    $b = exceptionWithFrames([['file' => 'vendor/other/z.php', 'function' => 'go', 'in_app' => false], appFrame()]);

    expect(fp($a))->toBe(fp($b));
});

it('falls back to the first frame for vendor-only stacks', function () {
    $a = exceptionWithFrames([['file' => 'vendor/a.php', 'function' => 'one', 'class' => 'A', 'in_app' => false]]);
    $b = exceptionWithFrames([['file' => 'vendor/a.php', 'function' => 'two', 'class' => 'A', 'in_app' => false]]);

    expect(fp($a))->not->toBe(fp($b))
        ->and(fp($a))->toBe(fp(exceptionWithFrames([['file' => 'vendor/a.php', 'line' => 77, 'function' => 'one', 'class' => 'A', 'in_app' => false]])));
});

it('uses the normalized message when there are no frames', function () {
    $a = exceptionWithFrames([], 'Order 12 not found');
    $b = exceptionWithFrames([], 'Order 99 not found');
    $c = exceptionWithFrames([], 'Customer 99 not found');

    expect(fp($a))->toBe(fp($b))->and(fp($a))->not->toBe(fp($c));
});

it('ignores release directories in file paths', function () {
    $a = exceptionWithFrames([appFrame(['file' => '/var/www/app/releases/20261017120000/app/Services/Invoicer.php'])]);
    $b = exceptionWithFrames([appFrame(['file' => '/var/www/app/releases/20261018090000/app/Services/Invoicer.php'])]);

    expect(fp($a))->toBe(fp($b));
});

it('treats test events like exceptions but keeps the type in the hash', function () {
    $exception = ngEvent();
    $test = ngEvent(['type' => 'test']);

    expect(fp($test))->not->toBe(fp($exception))
        ->and(fp($test))->toBe(fp(ngEvent(['type' => 'test', 'id' => fake()->uuid()])));
});

it('uses an explicit fingerprint when present', function () {
    $a = ngEvent(['fingerprint' => ['payments', 'gateway-timeout']]);
    $b = exceptionWithFrames([appFrame()]);
    $b['fingerprint'] = ['payments', 'gateway-timeout'];

    expect(fp($a))->toBe(fp($b))
        ->and(fp($a))->toBe(sha1("payments\ngateway-timeout"));
});

it('extends the default fingerprint with the {{ default }} marker', function () {
    $plain = ngEvent();
    $extended = ngEvent(['fingerprint' => ['{{ default }}', 'tenant-acme']]);
    $other = ngEvent(['fingerprint' => ['{{ default }}', 'tenant-other']]);

    expect(fp($extended))->not->toBe(fp($plain))
        ->and(fp($extended))->not->toBe(fp($other))
        ->and(fp($extended))->toBe(fp(ngEvent(['fingerprint' => ['{{ default }}', 'tenant-acme']])));
});

it('groups logs without exception by level, channel and message template', function () {
    $log = fn (string $message, string $level = 'error') => ngEvent([
        'type' => 'log', 'level' => $level, 'payload' => ['message' => $message, 'channel' => 'stack', 'exceptions' => null, 'exception' => null],
    ]);

    expect(fp($log('Payment gateway timeout for order 42')))->toBe(fp($log('Payment gateway timeout for order 77')))
        ->and(fp($log('Payment gateway timeout for order 42')))->not->toBe(fp($log('Payment gateway timeout for order 42', 'critical')))
        ->and(fp($log('Something else')))->not->toBe(fp($log('Payment gateway timeout')));
});

it('groups logs that carry an exception like exceptions', function () {
    $log = ngEvent(['type' => 'log', 'payload' => ['message' => 'Failed', 'exception' => ['class' => 'RuntimeException', 'frames' => [appFrame()]]]]);
    $log['payload']['exceptions'] = null;
    unset($log['payload']['exceptions']);
    $other = $log;
    $other['payload']['message'] = 'Different text';

    expect(fp($log))->toBe(fp($other));
});

it('groups failed jobs by job, exception class and culprit', function () {
    $job = fn (string $class) => ngEvent(['type' => 'job_failed', 'payload' => ['job' => $class, 'exceptions' => [['class' => 'RuntimeException', 'frames' => [appFrame()]]]]]);

    expect(fp($job('App\\Jobs\\SendInvoice')))->toBe(fp($job('App\\Jobs\\SendInvoice')))
        ->and(fp($job('App\\Jobs\\SendInvoice')))->not->toBe(fp($job('App\\Jobs\\SendReceipt')));
});

it('groups failed scheduled commands by normalized command', function () {
    $command = fn (string $c) => ngEvent(['type' => 'schedule_failed', 'payload' => ['command' => $c, 'exceptions' => []]]);

    expect(fp($command('php artisan invoices:sync --batch=12')))->toBe(fp($command('php artisan invoices:sync --batch=99')))
        ->and(fp($command('php artisan invoices:sync')))->not->toBe(fp($command('php artisan reports:build')));
});

it('groups slow requests by method and route, falling back to the normalized path', function () {
    $slow = fn (array $request) => ngEvent(['type' => 'slow_request', 'level' => 'warning', 'request' => $request, 'payload' => ['duration_ms' => 4000]]);

    expect(fp($slow(['method' => 'GET', 'route' => 'invoices.show', 'url' => 'https://a.test/invoices/1'])))
        ->toBe(fp($slow(['method' => 'get', 'route' => 'invoices.show', 'url' => 'https://a.test/invoices/2'])))
        ->and(fp($slow(['method' => 'GET', 'route' => 'invoices.show'])))->not->toBe(fp($slow(['method' => 'POST', 'route' => 'invoices.show'])));

    $noRoute = fn (string $url) => ngEvent(['type' => 'slow_request', 'request' => ['method' => 'GET', 'route' => null, 'url' => $url], 'payload' => ['duration_ms' => 1]]);
    expect(fp($noRoute('https://a.test/invoices/1?x=1')))->toBe(fp($noRoute('https://a.test/invoices/2')));
});

it('groups slow queries by connection and normalized SQL', function () {
    $query = fn (string $sql, string $connection = 'mysql') => ngEvent(['type' => 'slow_query', 'payload' => ['sql' => $sql, 'connection' => $connection, 'duration_ms' => 3000]]);

    expect(fp($query('SELECT * FROM invoices WHERE id IN (?, ?)')))->toBe(fp($query('select * from invoices where id in (?, ?, ?, ?)')))
        ->and(fp($query('select 1')))->not->toBe(fp($query('select 1', 'replica')));
});
