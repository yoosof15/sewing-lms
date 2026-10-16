const baseUrl = process.argv[2] ?? 'http://127.0.0.1:8000/up';
const requests = Number(process.argv[3] ?? 100);
const concurrency = Number(process.argv[4] ?? 20);
if (!Number.isInteger(requests) || requests < 1 || requests > 500 || !Number.isInteger(concurrency) || concurrency < 1 || concurrency > 50) {
    throw new Error('Usage: node scripts/load-smoke.mjs [URL] [requests:1-500] [concurrency:1-50]');
}
let cursor = 0;
const timings = [];
let failures = 0;
async function worker() {
    while (cursor++ < requests) {
        const start = performance.now();
        try {
            const response = await fetch(baseUrl, { redirect: 'manual' });
            if (response.status >= 500) failures++;
            await response.arrayBuffer();
        } catch {
            failures++;
        }
        timings.push(performance.now() - start);
    }
}
const started = performance.now();
await Promise.all(Array.from({ length: Math.min(concurrency, requests) }, worker));
timings.sort((a, b) => a - b);
const percentile = (p) => timings[Math.min(timings.length - 1, Math.ceil(timings.length * p) - 1)];
console.log(JSON.stringify({ url: baseUrl, requests, concurrency, failures, durationMs: Math.round(performance.now() - started), p50Ms: Math.round(percentile(.5)), p95Ms: Math.round(percentile(.95)), maxMs: Math.round(timings.at(-1)) }, null, 2));
process.exitCode = failures ? 1 : 0;
