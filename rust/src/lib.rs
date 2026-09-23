//! Rust client for the Aquidify API: turn what people type into validated, structured intent.
//!
//! ```no_run
//! let aq = aquidify::Client::from_env()?;
//! let r = aq.interpret(&aquidify::Request::new(
//!     "hiring.candidate",
//!     "Iščem delo v skladišču v Ljubljani, brez nočnih.",
//!     "sl-SI",
//! ))?;
//! assert_eq!(r.interpretation["intents"][0]["roles"][0]["value"], "warehouse");
//!
//! // your own task, any industry: register it once, then interpret with its id
//! aq.put_task("support.ticket@1.0.0", &serde_json::json!({
//!     "instructions": "Classify customer support emails.",
//!     "schema": {"type": "object", "properties": {"category": {"enum": ["billing", "bug"]}}}
//! }))?;
//! aq.interpret(&aquidify::Request::new("support.ticket@1.0.0", "I was charged twice", "en"))?;
//! # Ok::<(), aquidify::Error>(())
//! ```

use serde::{Deserialize, Serialize};
use std::time::Duration;

pub const VERSION: &str = "0.2.0";

#[derive(Debug, Clone, Serialize)]
pub struct Request {
    pub domain: String,
    pub input: String,
    pub locale: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub parser_version: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub schema_version: Option<String>,
}

impl Request {
    pub fn new(domain: &str, input: &str, locale: &str) -> Self {
        Self {
            domain: domain.into(),
            input: input.into(),
            locale: locale.into(),
            parser_version: None,
            schema_version: None,
        }
    }
}

#[derive(Debug, Clone, Deserialize)]
pub struct Response {
    pub request_id: String,
    pub domain: String,
    pub parser_version: String,
    pub schema_version: String,
    /// Domain output; see schemas/hiring.candidate-1.0.0.json.
    pub interpretation: serde_json::Value,
    pub clarification: Option<Clarification>,
    pub meta: Meta,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Clarification {
    pub reason: String,
    pub field: String,
    pub question_key: String,
    pub suggested_options: Vec<String>,
}

#[derive(Debug, Clone, Deserialize)]
pub struct Meta {
    /// "deterministic" or "ai"
    pub source: String,
    /// "bypass", "hit", "miss" or "coalesced"
    pub cache: String,
    pub provider: Option<String>,
    pub model: Option<String>,
    pub latency_ms: u64,
}

/// A failed call. `code` is the API's stable error code, or "network".
#[derive(Debug, Clone)]
pub struct Error {
    pub status: u16,
    pub code: String,
    pub message: String,
    pub request_id: Option<String>,
    pub retry_after: Option<u64>,
}

impl Error {
    /// True when the same request may succeed later.
    pub fn retryable(&self) -> bool {
        matches!(
            self.code.as_str(),
            "rate_limited" | "model_unavailable" | "timeout" | "network"
        )
    }

    fn local(code: &str, message: String) -> Self {
        Self {
            status: 0,
            code: code.into(),
            message,
            request_id: None,
            retry_after: None,
        }
    }
}

impl std::fmt::Display for Error {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(
            f,
            "aquidify: {} {}: {}",
            self.status, self.code, self.message
        )
    }
}

impl std::error::Error for Error {}

pub struct Client {
    api_key: String,
    pub base_url: String,
    agent: ureq::Agent,
}

impl Client {
    pub fn new(api_key: impl Into<String>) -> Result<Self, Error> {
        let api_key = api_key.into();
        if api_key.is_empty() {
            return Err(Error::local("config", "Aquidify API key missing".into()));
        }
        Ok(Self {
            api_key,
            base_url: "https://api.aquidify.com".into(),
            agent: ureq::AgentBuilder::new()
                .timeout(Duration::from_secs(60))
                .build(),
        })
    }

    /// Reads the key from AQUIDIFY_API_KEY.
    pub fn from_env() -> Result<Self, Error> {
        Self::new(std::env::var("AQUIDIFY_API_KEY").unwrap_or_default())
    }

    pub fn interpret(&self, req: &Request) -> Result<Response, Error> {
        self.interpret_idempotent(req, None)
    }

    /// Same as `interpret`, replaying the first response for `idempotency_key` for 24h.
    pub fn interpret_idempotent(
        &self,
        req: &Request,
        idempotency_key: Option<&str>,
    ) -> Result<Response, Error> {
        let body = serde_json::to_value(req).map_err(|e| Error::local("encode", e.to_string()))?;
        let data = self.call("POST", "/v1/interpret", Some(&body), idempotency_key)?;
        serde_json::from_value(data).map_err(|e| Error::local("decode", e.to_string()))
    }

    /// Registers a task version (`name@version`); `definition` is the task's JSON
    /// (instructions, schema, optional description and examples). The same definition
    /// again is a no-op; a changed one fails with code `task_exists`: register a new
    /// version instead. Returns the registered task.
    pub fn put_task(
        &self,
        id: &str,
        definition: &serde_json::Value,
    ) -> Result<serde_json::Value, Error> {
        self.call(
            "PUT",
            &format!("/v1/tasks/{}", path_segment(id)),
            Some(definition),
            None,
        )
    }

    /// A registered task; code `not_found` when this key has none by that id.
    pub fn get_task(&self, id: &str) -> Result<serde_json::Value, Error> {
        self.call(
            "GET",
            &format!("/v1/tasks/{}", path_segment(id)),
            None,
            None,
        )
    }

    /// The task ids registered with this API key.
    pub fn list_tasks(&self) -> Result<Vec<String>, Error> {
        let data = self.call("GET", "/v1/tasks", None, None)?;
        Ok(data["tasks"]
            .as_array()
            .map(|a| {
                a.iter()
                    .filter_map(|t| t.as_str().map(String::from))
                    .collect()
            })
            .unwrap_or_default())
    }

    fn call(
        &self,
        method: &str,
        path: &str,
        body: Option<&serde_json::Value>,
        idempotency_key: Option<&str>,
    ) -> Result<serde_json::Value, Error> {
        let url = format!("{}{}", self.base_url.trim_end_matches('/'), path);
        let mut call = self
            .agent
            .request(method, &url)
            .set("Authorization", &format!("Bearer {}", self.api_key))
            .set("Accept", "application/json")
            .set("User-Agent", &format!("aquidify-sdk-rust/{VERSION}"));
        if let Some(key) = idempotency_key {
            call = call.set("Idempotency-Key", key);
        }
        let sent = match body {
            Some(b) => call.send_json(b),
            None => call.call(),
        };

        match sent {
            Ok(res) => res
                .into_json()
                .map_err(|e| Error::local("network", e.to_string())),
            Err(ureq::Error::Status(status, res)) => {
                let request_id_header = res.header("X-Request-ID").map(String::from);
                let retry_after = res.header("Retry-After").and_then(|s| s.parse().ok());
                let body: serde_json::Value = res.into_json().unwrap_or_default();
                Err(Error {
                    status,
                    code: body["error"]["code"].as_str().unwrap_or("unknown").into(),
                    message: body["error"]["message"]
                        .as_str()
                        .map(String::from)
                        .unwrap_or_else(|| format!("HTTP {status}")),
                    request_id: body["request_id"]
                        .as_str()
                        .map(String::from)
                        .or(request_id_header),
                    retry_after,
                })
            }
            Err(e) => Err(Error::local("network", e.to_string())),
        }
    }
}

/// Percent-encodes a task id for the path, keeping `@` and the unreserved characters.
fn path_segment(id: &str) -> String {
    id.bytes()
        .map(|b| match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'.' | b'_' | b'~' | b'@' => {
                (b as char).to_string()
            }
            _ => format!("%{b:02X}"),
        })
        .collect()
}
