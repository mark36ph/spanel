<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/DomainName.php';

$valid = [
    'example.com' => 'example.com',
    'WWW.Example.COM' => 'www.example.com',
    ' sub.example.co.uk. ' => 'sub.example.co.uk',
    'xn--bcher-kva.example' => 'xn--bcher-kva.example',
];
foreach ($valid as $input => $expected) {
    if (DomainName::normalize($input) !== $expected) {
        fwrite(STDERR, "Expected valid domain to normalize: {$input}\n");
        exit(1);
    }
}

$invalid = [
    '',
    'localhost',
    '127.0.0.1',
    'example..com',
    '-example.com',
    'example-.com',
    'example.com/path',
    'https://example.com',
    'example .com',
    str_repeat('a', 64) . '.com',
    str_repeat('a', 250) . '.com',
    'example.com:443',
    'example.com..',
    '.example.com',
    'a..b.example.com',
    'foo_bar.example.com',
    '*.example.com',
    'example.com-',
    '::1',
    '[::1]',
];
foreach ($invalid as $input) {
    if (DomainName::normalize($input) !== null) {
        fwrite(STDERR, "Expected invalid domain to be rejected: {$input}\n");
        exit(1);
    }
}

fwrite(STDOUT, "DomainName validation checks passed.\n");
