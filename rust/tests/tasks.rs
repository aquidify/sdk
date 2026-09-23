//! Task calls against a local stand-in for the API: offline, no key needed.

use std::io::{BufRead, BufReader, Read, Write};
use std::net::TcpListener;
use std::sync::mpsc;
use std::thread;

/// Serves `n` requests, answering by "METHOD path", and reports each request line + body.
fn serve(n: usize) -> (String, mpsc::Receiver<(String, String)>) {
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    let url = format!("http://{}", listener.local_addr().unwrap());
    let (tx, rx) = mpsc::channel();
    thread::spawn(move || {
        for stream in listener.incoming().take(n) {
            let mut stream = stream.unwrap();
            let mut reader = BufReader::new(stream.try_clone().unwrap());
            let mut line = String::new();
            reader.read_line(&mut line).unwrap();
            let mut len = 0;
            loop {
                let mut h = String::new();
                reader.read_line(&mut h).unwrap();
                if h == "\r\n" {
                    break;
                }
                if let Some(v) = h.to_ascii_lowercase().strip_prefix("content-length:") {
                    len = v.trim().parse().unwrap();
                }
            }
            let mut body = vec![0; len];
            reader.read_exact(&mut body).unwrap();
            let req = line
                .split_whitespace()
                .take(2)
                .collect::<Vec<_>>()
                .join(" ");
            let (status, out) = match req.as_str() {
                "PUT /v1/tasks/support.ticket@1.0.0" | "GET /v1/tasks/support.ticket@1.0.0" => (
                    200,
                    r#"{"id":"support.ticket@1.0.0","parser_version":"1.0.0+abc"}"#,
                ),
                "GET /v1/tasks" => (200, r#"{"tasks":["support.ticket@1.0.0","booking@2.0.0"]}"#),
                _ => (
                    404,
                    r#"{"request_id":"r1","error":{"code":"not_found","message":"no such task for this API key"}}"#,
                ),
            };
            write!(
                stream,
                "HTTP/1.1 {status} X\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{out}",
                out.len()
            )
            .unwrap();
            tx.send((req, String::from_utf8(body).unwrap())).unwrap();
        }
    });
    (url, rx)
}

fn client(url: &str) -> aquidify::Client {
    let mut c = aquidify::Client::new("k").unwrap();
    c.base_url = url.into();
    c
}

#[test]
fn put_sends_the_definition_and_returns_the_task() {
    let (url, seen) = serve(1);
    let task = client(&url)
        .put_task(
            "support.ticket@1.0.0",
            &serde_json::json!({
                "instructions": "Classify customer support emails.",
                "schema": {"type": "object", "properties": {"category": {"enum": ["billing", "bug"]}}}
            }),
        )
        .unwrap();
    assert_eq!(task["parser_version"], "1.0.0+abc");
    let (req, body) = seen.recv().unwrap();
    assert_eq!(req, "PUT /v1/tasks/support.ticket@1.0.0");
    let sent: serde_json::Value = serde_json::from_str(&body).unwrap();
    assert_eq!(sent["instructions"], "Classify customer support emails.");
    assert_eq!(sent["schema"]["properties"]["category"]["enum"][1], "bug");
}

#[test]
fn get_and_list() {
    let (url, _seen) = serve(2);
    let c = client(&url);
    assert_eq!(
        c.get_task("support.ticket@1.0.0").unwrap()["id"],
        "support.ticket@1.0.0"
    );
    assert_eq!(
        c.list_tasks().unwrap(),
        vec!["support.ticket@1.0.0", "booking@2.0.0"]
    );
}

#[test]
fn unknown_task_is_not_found() {
    let (url, seen) = serve(1);
    let e = client(&url).get_task("no such@1.0.0").unwrap_err();
    assert_eq!((e.status, e.code.as_str()), (404, "not_found"));
    assert_eq!(e.request_id.as_deref(), Some("r1"));
    assert!(!e.retryable());
    // the id is one path segment, whatever it contains
    assert_eq!(seen.recv().unwrap().0, "GET /v1/tasks/no%20such@1.0.0");
}
