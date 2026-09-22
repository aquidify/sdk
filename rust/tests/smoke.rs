//! Live smoke test, no model calls (free):
//!   AQUIDIFY_API_KEY=... [AQUIDIFY_BASE_URL=...] cargo test

fn client(key: Option<&str>) -> Option<aquidify::Client> {
    let env_key = std::env::var("AQUIDIFY_API_KEY")
        .ok()
        .filter(|k| !k.is_empty())?;
    let mut c = aquidify::Client::new(key.unwrap_or(&env_key)).unwrap();
    if let Ok(url) = std::env::var("AQUIDIFY_BASE_URL") {
        c.base_url = url;
    }
    Some(c)
}

#[test]
fn deterministic() {
    let Some(c) = client(None) else {
        return eprintln!("skip: set AQUIDIFY_API_KEY");
    };
    let r = c
        .interpret(&aquidify::Request::new(
            "hiring.candidate",
            "Ljubljana",
            "sl-SI",
        ))
        .unwrap();
    assert_eq!(r.meta.source, "deterministic");
    assert_eq!(
        r.interpretation["intents"][0]["locations"][0]["value"],
        "Ljubljana"
    );
    assert!(r.clarification.is_none());
}

#[test]
fn errors() {
    for (key, domain, status, code) in [
        (Some("wrong-key"), "hiring.candidate", 401, "unauthorized"),
        (None, "no.such.domain", 422, "invalid_request"),
    ] {
        let Some(c) = client(key) else {
            return eprintln!("skip: set AQUIDIFY_API_KEY");
        };
        let e = c
            .interpret(&aquidify::Request::new(domain, "x", "sl"))
            .unwrap_err();
        assert_eq!((e.status, e.code.as_str()), (status, code));
        assert!(e.request_id.is_some() && !e.retryable());
    }
}
