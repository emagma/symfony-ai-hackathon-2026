// How the chat writes tokens and costs. Pure functions, loaded before app.js
// and tested on their own: node --test 'tests/js/*.test.js' (from apps/chat).

const ChatFormat = (() => {
    const ZERO_USAGE = Object.freeze({ input: 0, output: 0, cacheRead: 0, cacheWrite: 0 });

    /**
     * Millions of tokens, four decimals: a step of 0.0001 MT is 100 tokens.
     * Counted in hundreds of tokens, in integers: no float lands just below a half.
     */
    const mt = (tokens) => {
        const hundreds = Math.round(Math.max(0, Number(tokens) || 0) / 100);

        return `${Math.floor(hundreds / 10_000)}.${String(hundreds % 10_000).padStart(4, '0')} MT`;
    };

    const usd = (value) => '$' + (value ?? 0).toFixed(4);

    /**
     * The four counts of one model call, from its llm_call event. The event's
     * input_tokens includes the cache reads and writes; `input` here is the
     * uncached rest, so that the four parts add up.
     */
    const callUsage = (data) => {
        const cacheRead = data.cached_tokens ?? 0;
        const cacheWrite = data.cache_write_tokens ?? 0;

        return {
            input: Math.max(0, (data.input_tokens ?? 0) - cacheRead - cacheWrite),
            output: data.output_tokens ?? 0,
            cacheRead,
            cacheWrite,
        };
    };

    const addUsage = (a, b) => ({
        input: a.input + b.input,
        output: a.output + b.output,
        cacheRead: a.cacheRead + b.cacheRead,
        cacheWrite: a.cacheWrite + b.cacheWrite,
    });

    /** `in 0.0123 MT · out 0.0003 MT · cache read 0.0098 MT · cache write 0.0000 MT` */
    const usage = (u) => [
        `in ${mt(u.input)}`,
        `out ${mt(u.output)}`,
        `cache read ${mt(u.cacheRead)}`,
        `cache write ${mt(u.cacheWrite)}`,
    ].join(' · ');

    /**
     * The badge of one model call: its model and tokens, and its cost only
     * when a price source is configured. Without one, no price is mentioned.
     */
    const callLine = (data, pricing) => {
        const parts = [data.model, usage(callUsage(data))];
        if (pricing) {
            parts.push(null === (data.cost_usd ?? null) ? 'price unknown' : usd(data.cost_usd));
        }

        return parts.join(' · ');
    };

    return { ZERO_USAGE, mt, usd, callUsage, addUsage, usage, callLine };
})();

if ('object' === typeof module) {
    module.exports = ChatFormat;
}
