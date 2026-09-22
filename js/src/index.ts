/**
 * Aquidify API client (TypeScript / JavaScript). Zero dependencies; uses global fetch
 * (Node 18+, Deno, Bun, browsers — but keep API keys server-side).
 *
 *   import { Aquidify } from "@aquidify/sdk";
 *   const aq = new Aquidify({ apiKey: process.env.AQUIDIFY_API_KEY });
 *   const r = await aq.interpret({ domain: "hiring.candidate", input: "Iščem delo v skladišču v Ljubljani, brez nočnih.", locale: "sl-SI" });
 *
 *   // your own task, any industry
 *   await aq.putTask("support.ticket@1.0.0", {
 *     instructions: "Classify customer support emails.",
 *     schema: { type: "object", properties: { category: { enum: ["billing", "bug", "refund"] }, order_id: { type: "string" } } },
 *   });
 *   const t = await aq.interpret<TaskInterpretation>({ domain: "support.ticket@1.0.0", input: emailBody, locale: "en" });
 *   t.interpretation.fields.category;
 */

export const VERSION = "0.2.0";

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
  | "invalid_task" | "task_exists" | "task_limit" | "not_found"
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
    const headers: Record<string, string> = {};
    if (opts.idempotencyKey) headers["Idempotency-Key"] = opts.idempotencyKey;
    return this.request("POST", "/v1/interpret", req, headers);
  }

  /**
   * Register a task version (your own fields, any industry). The same definition
   * again is a no-op; a changed one throws task_exists: register a new version.
   */
  putTask(id: string, def: TaskDefinition): Promise<Task> {
    return this.request("PUT", `/v1/tasks/${encodeURIComponent(id)}`, def);
  }

  /** A registered task, with the output_schema its interpretations follow. Throws not_found. */
  getTask(id: string): Promise<Task> {
    return this.request("GET", `/v1/tasks/${encodeURIComponent(id)}`);
  }

  /** Task ids registered with this API key. */
  async listTasks(): Promise<string[]> {
    return (await this.request<{ tasks: string[] }>("GET", "/v1/tasks")).tasks;
  }

  private async request<T>(method: string, path: string, body?: unknown, extra: Record<string, string> = {}): Promise<T> {
    const headers: Record<string, string> = {
      Authorization: `Bearer ${this.apiKey}`,
      Accept: "application/json",
      ...extra,
    };
    if (body !== undefined) headers["Content-Type"] = "application/json";

    let res: Response;
    try {
      res = await this.fetch(`${this.baseUrl}${path}`, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(this.timeoutMs),
      });
    } catch (e) {
      throw new AquidifyError(`Aquidify request failed: ${(e as Error).message}`, 0, "network");
    }

    const data = (await res.json().catch(() => null)) as
      | (T & { request_id?: string; error?: { code: ErrorCode; message: string } })
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

// ---- client tasks ----

export interface TaskDefinition {
  /** What to extract and how, in plain words (max 4000 characters). */
  instructions: string;
  /**
   * JSON Schema of the fields: top level {type: "object", properties}. Keywords: type,
   * description, enum, properties, required, items, anyOf. A property not in
   * `required` may come back null.
   */
  schema: Record<string, unknown>;
  description?: string;
  /** Up to 5; each output must match the schema. */
  examples?: { input: string; output: Record<string, unknown> }[];
}

export interface Task extends TaskDefinition {
  id: string;
  created_at: string;
  parser_version: string;
  output_schema: Record<string, unknown>;
}

/** What interpret returns for a task. F is your fields type. */
export interface TaskInterpretation<F = Record<string, unknown>> {
  fields: F;
  /** At least one per field with a value, quoting the input. */
  evidence: { field: string; raw_text: string }[];
  uncertainties: { field: string; reason: "ambiguous" | "vague" | "unclear_reference" | "no_matching_value"; raw_text: string }[];
  contradictions: { fields: string[]; description: string; raw_text: string }[];
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
