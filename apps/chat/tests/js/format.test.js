// node --test 'tests/js/*.test.js' (from apps/chat): Node's own runner, no dependency.

const assert = require('node:assert/strict');
const { test } = require('node:test');
const { ZERO_USAGE, mt, callUsage, addUsage, usage, callLine } = require('../../public/format.js');

test('tokens are shown in millions, four decimals, rounded to the nearest 100', () => {
    assert.equal(mt(0), '0.0000 MT');
    assert.equal(mt(49), '0.0000 MT');
    assert.equal(mt(50), '0.0001 MT');
    assert.equal(mt(149), '0.0001 MT');
    assert.equal(mt(150), '0.0002 MT');
    assert.equal(mt(12_300), '0.0123 MT');
    assert.equal(mt(999_949), '0.9999 MT');
    assert.equal(mt(999_950), '1.0000 MT');
    assert.equal(mt(12_345_678), '12.3457 MT');
    assert.equal(mt(3_000_000_000), '3000.0000 MT');
});

test('a missing count is zero', () => {
    assert.equal(mt(undefined), '0.0000 MT');
    assert.equal(mt(null), '0.0000 MT');
});

test('the input of a call is its uncached part, so that the four parts add up', () => {
    const data = { model: 'claude-haiku-4-5', input_tokens: 22_100, cached_tokens: 9_800, cache_write_tokens: 0, output_tokens: 300 };

    assert.deepEqual(callUsage(data), { input: 12_300, output: 300, cacheRead: 9_800, cacheWrite: 0 });
    assert.equal(usage(callUsage(data)), 'in 0.0123 MT · out 0.0003 MT · cache read 0.0098 MT · cache write 0.0000 MT');
});

test('the conversation total adds every call', () => {
    const first = { input_tokens: 41_000, cached_tokens: 0, cache_write_tokens: 40_000, output_tokens: 100 };
    const second = { input_tokens: 40_500, cached_tokens: 40_000, cache_write_tokens: 0, output_tokens: 200 };

    const total = [first, second].map(callUsage).reduce(addUsage, ZERO_USAGE);

    assert.equal(usage(total), 'in 0.0015 MT · out 0.0003 MT · cache read 0.0400 MT · cache write 0.0400 MT');
});

test('without a price source, a call mentions no price at all', () => {
    const line = callLine({ model: 'claude-haiku-4-5', input_tokens: 1_000, cached_tokens: 0, cache_write_tokens: 0, output_tokens: 100, cost_usd: null }, false);

    assert.equal(line, 'claude-haiku-4-5 · in 0.0010 MT · out 0.0001 MT · cache read 0.0000 MT · cache write 0.0000 MT');
    assert.doesNotMatch(line, /\$|price/);
});

test('with a price source, a call shows its cost, or says its model has no price', () => {
    const data = { model: 'claude-haiku-4-5', input_tokens: 1_000, cached_tokens: 0, cache_write_tokens: 0, output_tokens: 100 };

    assert.match(callLine({ ...data, cost_usd: 0.0015 }, true), / · \$0\.0015$/);
    assert.match(callLine({ ...data, cost_usd: null }, true), / · price unknown$/);
});
