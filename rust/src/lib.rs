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
//! # Ok::<(), aquidify::Error>(())
//! ```

use serde::{Deserialize, Serialize};
use std::time::Duration;

pub const VERSION: &str = "0.1.0";

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
        let url = format!("{}/v1/interpret", self.base_url.trim_end_matches('/'));
        let mut call = self
            .agent
            .post(&url)
            .set("Authorization", &format!("Bearer {}", self.api_key))
            .set("Accept", "application/json")
            .set("User-Agent", &format!("aquidify-sdk-rust/{VERSION}"));
        if let Some(key) = idempotency_key {
            call = call.set("Idempotency-Key", key);
        }

        match call.send_json(req) {
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
