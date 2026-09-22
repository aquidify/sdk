/**
 * Aquidify API client (TypeScript / JavaScript). Zero dependencies; uses global fetch
 * (Node 18+, Deno, Bun, browsers — but keep API keys server-side).
 *
 *   import { Aquidify } from "@aquidify/sdk";
 *   const aq = new Aquidify({ apiKey: process.env.AQUIDIFY_API_KEY });
 *   const r = await aq.interpret({ domain: "hiring.candidate", input: "Iščem delo v skladišču v Ljubljani, brez nočnih.", locale: "sl-SI" });
 */

export const VERSION = "0.1.0";

export interface InterpretRequest {
  domain: string;
  input: string;
  locale: string;
  parser_version?: string;
  schema_version?: string;
}

export interface Clarification {
  reason: string;
  field: string;
  question_key: string;
  suggested_options: string[];
}

export interface Meta {
  source: "deterministic" | "ai";
  cache: "bypass" | "hit" | "miss" | "coalesced";
  provider?: string;
  model?: string;
  latency_ms: number;
}

export interface InterpretResponse<I = Record<string, unknown>> {
  request_id: string;
  domain: string;
  parser_version: string;
  schema_version: string;
  interpretation: I;
  clarification: Clarification | null;
  meta: Meta;
}

/** Error codes the API returns, plus "network" when no response arrived. */
export type ErrorCode =
  | "unauthorized" | "rate_limited" | "bad_json" | "body_too_large" | "invalid_request"
  | "idempotency_mismatch" | "model_unavailable" | "interpretation_failed" | "timeout"
  | "internal" | "network" | "unknown";

export class AquidifyError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: ErrorCode,
    readonly requestId?: string,
    readonly retryAfter?: number,
  ) {
    super(message);
    this.name = "AquidifyError";
  }

  /** True when the same request may succeed later. */
  get retryable(): boolean {
    return ["rate_limited", "model_unavailable", "timeout", "network"].includes(this.code);
  }
}

export interface Options {
  apiKey?: string;
  baseUrl?: string;
  timeoutMs?: number;
  fetch?: typeof fetch;
}

export class Aquidify {
  private readonly apiKey: string;
  private readonly baseUrl: string;
  private readonly timeoutMs: number;
  private readonly fetch: typeof fetch;

  constructor(opts: Options = {}) {
    const env = (globalThis as { process?: { env?: Record<string, string | undefined> } }).process?.env;
    this.apiKey = opts.apiKey ?? env?.AQUIDIFY_API_KEY ?? "";
    if (!this.apiKey) throw new Error("Aquidify API key missing: pass apiKey or set AQUIDIFY_API_KEY.");
    this.baseUrl = (opts.baseUrl ?? "https://api.aquidify.com").replace(/\/+$/, "");
    this.timeoutMs = opts.timeoutMs ?? 60_000;
    this.fetch = opts.fetch ?? globalThis.fetch;
  }

  /** Interpret free text into structured intent. Throws AquidifyError on failure. */
  async interpret<I = Record<string, unknown>>(
    req: InterpretRequest,
    opts: { idempotencyKey?: string } = {},
  ): Promise<InterpretResponse<I>> {
    const headers: Record<string, string> = {
      Authorization: `Bearer ${this.apiKey}`,
      "Content-Type": "application/json",
      Accept: "application/json",
    };
    if (opts.idempotencyKey) headers["Idempotency-Key"] = opts.idempotencyKey;

    let res: Response;
    try {
      res = await this.fetch(`${this.baseUrl}/v1/interpret`, {
        method: "POST",
        headers,
        body: JSON.stringify(req),
        signal: AbortSignal.timeout(this.timeoutMs),
      });
    } catch (e) {
      throw new AquidifyError(`Aquidify request failed: ${(e as Error).message}`, 0, "network");
    }

    const data = (await res.json().catch(() => null)) as
      | (InterpretResponse<I> & { error?: { code: ErrorCode; message: string } })
      | null;
    if (res.ok && data) return data;

    const retryAfter = Number(res.headers.get("retry-after")) || undefined;
    throw new AquidifyError(
      data?.error?.message ?? `Aquidify returned HTTP ${res.status}`,
      res.status,
      data?.error?.code ?? "unknown",
      data?.request_id ?? res.headers.get("x-request-id") ?? undefined,
      retryAfter,
    );
  }
}

// ---- hiring.candidate 1.0.0 output (schemas/hiring.candidate-1.0.0.json) ----

export type Polarity = "include" | "exclude";
export type Strength = "required" | "preferred" | "acceptable" | "conditional";

interface Stance {
  polarity: Polarity;
  strength: Strength;
  condition: string | null;
  /** Exact span of the input this item came from. */
  raw_text: string;
}

export interface HiringCandidateIntent {
  roles: (Stance & { value: string })[];
  locations: (Stance & { value: string | null; radius_km: number | null })[];
  work_modes: (Stance & { value: "onsite" | "hybrid" | "remote" })[];
  engagement_types: (Stance & {
    value: "full_time" | "part_time" | "contract" | "freelance" | "temporary" | "seasonal" | "internship" | "student_work" | "side_job";
  })[];
  schedules: (Stance & {
    value: "morning" | "afternoon" | "evening" | "night" | "weekday" | "weekend" | "shift_work" | "flexible_hours";
  })[];
  salary: {
    min: number | null;
    max: number | null;
    currency: string | null;
    period: "hour" | "day" | "month" | "year" | null;
    basis: "net" | "gross" | null;
    strength: Strength;
    condition: string | null;
    raw_text: string;
  } | null;
  availability: { timing: "immediately" | "specific" | "flexible"; detail: string | null; raw_text: string } | null;
  capabilities: {
    value: string;
    status: "has" | "lacks";
    willing_to_acquire: boolean;
    willingness_raw_text: string | null;
    raw_text: string;
  }[];
  interests: { value: string; raw_text: string }[];
  constraints: (Stance & { value: string })[];
  /** Fields the person explicitly said anything is fine for. Empty field without this = unknown. */
  explicit_any: ("roles" | "locations" | "work_modes" | "engagement_types" | "schedules")[];
}

export interface HiringCandidateInterpretation {
  intents: HiringCandidateIntent[];
  uncertainties: { field: string; reason: "ambiguous" | "vague" | "unclear_strength" | "unclear_reference"; raw_text: string }[];
  contradictions: { fields: string[]; description: string; raw_text: string }[];
}
