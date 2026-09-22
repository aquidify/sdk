// Live smoke test, no model calls (free):
//   AQUIDIFY_API_KEY=... [AQUIDIFY_BASE_URL=...] npm test
import { Aquidify, AquidifyError } from "./dist/index.js";

if (!process.env.AQUIDIFY_API_KEY) {
  console.log("skip: set AQUIDIFY_API_KEY");
  process.exit(0);
}
const baseUrl = process.env.AQUIDIFY_BASE_URL ?? "https://api.aquidify.com";
const check = (ok, what) => { if (!ok) { console.error(`FAIL: ${what}`); process.exit(1); } };

const r = await new Aquidify({ baseUrl }).interpret({ domain: "hiring.candidate", input: "Ljubljana", locale: "sl-SI" });
check(r.meta.source === "deterministic", "place alone resolves without a model");
check(r.interpretation.intents[0].locations[0].value === "Ljubljana", "location value");
check(r.clarification === null, "no clarification");

async function expectError(client, req, status, code) {
  try {
    await client.interpret(req);
    check(false, `${code} must throw`);
  } catch (e) {
    check(e instanceof AquidifyError && e.status === status && e.code === code, `${status} ${code}, got ${e.status} ${e.code}`);
    return e;
  }
}
const e401 = await expectError(new Aquidify({ apiKey: "wrong-key", baseUrl }), { domain: "hiring.candidate", input: "x", locale: "sl" }, 401, "unauthorized");
check(!e401.retryable, "401 not retryable");
const e422 = await expectError(new Aquidify({ baseUrl }), { domain: "no.such.domain", input: "x", locale: "sl" }, 422, "invalid_request");
check(!!e422.requestId, "request id on errors");

check(Array.isArray(await new Aquidify({ baseUrl }).listTasks()), "listTasks returns ids");
await new Aquidify({ baseUrl }).getTask("no.such.task@1.0.0").then(
  () => check(false, "missing task must throw"),
  (e) => check(e instanceof AquidifyError && e.status === 404 && e.code === "not_found", `404 not_found, got ${e.status} ${e.code}`),
);

console.log("js: ok");
