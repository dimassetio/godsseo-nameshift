<?php

use App\Services\NameserverSet;
use Illuminate\Validation\ValidationException;

test('nameservers are normalized while retaining order', function () {
    expect(NameserverSet::normalize([' NS2.Example.COM. ', 'ns1.example.com']))
        ->toBe(['ns2.example.com', 'ns1.example.com']);
    expect(NameserverSet::equal(['NS1.EXAMPLE.COM.', 'ns2.example.com'], ['ns1.example.com', 'ns2.example.com']))->toBeTrue();
});

test('nameserver equality ignores registrar response order', function () {
    expect(NameserverSet::equal(
        ['vin.ns.cloudflare.com', 'mia.ns.cloudflare.com'],
        ['MIA.NS.CLOUDFLARE.COM.', 'vin.ns.cloudflare.com'],
    ))->toBeTrue();
});

test('invalid duplicate url and ip nameservers are rejected', function (array $values) {
    NameserverSet::normalize($values);
})->with([
    [['ns1.example.com', 'NS1.EXAMPLE.COM.']],
    [['https://ns1.example.com', 'ns2.example.com']],
    [['192.0.2.1', 'ns2.example.com']],
])->throws(ValidationException::class);
